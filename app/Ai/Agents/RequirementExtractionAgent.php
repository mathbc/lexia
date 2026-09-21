<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredContextWindow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads the facts of a matter and writes out what the client is asking for.
 *
 * The third kind of answer this project asks a model for. Its two framing
 * siblings **choose** a row out of a catalogue and the defendant agent
 * **copies** a party out of a narrative; this one does both at once — *which*
 * requests exist is read from the facts, and the sentence each one becomes is
 * composed. That split is what every rule below is arranged around: the list is
 * held to the narrative, and only the wording is allowed to be the agent's.
 *
 * Hence `Temperature(0.2)` rather than the defendant's 0.1 — a pleading's
 * request is a sentence, not a transcription. What guards against an invented
 * claim is not the temperature: it is the instruction that every item trace
 * back to the narrative, and the rule that a figure nobody wrote stays null.
 *
 * The answer is `{"requirements": [{description, amount}, …]}`, which is the
 * writable half of the `requirements` table — the sentence the court reads out
 * and the money it claims. `RequirementListData::fromAgent()` turns it into the
 * very object `SaveLegalCaseRequirements::handle()` already takes, so a
 * suggestion the lawyer accepts is written by the code that writes the form.
 *
 * Two shapes carry meaning here and are worth not breaking:
 *
 * 1. **The empty list is an answer**, not a failure. A narrative that only
 *    tells a story — or that asks the lawyer what to do — requests nothing, and
 *    the step opens blank, exactly as it opens for a pleading built by hand.
 * 2. **`amount` is `required()` and `nullable()`**, the same unusual pair the
 *    defendant agent documents: the grammar forces the key to exist and makes
 *    `null` a first-class answer. The majority of requests claim no money, and
 *    an omitted key would be indistinguishable from a figure the model never
 *    considered.
 *
 * Which leaves the failure the instructions open the money section with: a
 * sentence that spells a figure out beside a `null` field. It is not the model
 * forgetting the field — it is the model merging two claims into one sentence,
 * because a sentence carrying two figures has no single amount to give. It then
 * either adds them or reaches for the one it composed for the moral damages,
 * and `RequirementListData::fromAgent()` refuses that figure, so the request the
 * client wrote a price for opens blank on the screen. Hence the rule stated
 * before any other: one closed figure per request, and the field says what the
 * sentence says.
 *
 * The boilerplate a lawyer already has one click away — citação, provas,
 * honorários — is deliberately kept out. `SUGGESTED_REQUIREMENTS` in
 * `resources/js/lib/requirements.ts` writes those in canned form, and an agent
 * repeating them would hand the lawyer two copies of the same request to
 * reconcile by hand. What does not come from the rite, and therefore does come
 * from here, is everything the *facts* ask for. Court fee waiver is the one
 * boundary case, and it belongs to whichever side the narrative puts it on: it
 * is asked for here only when the relato says why.
 *
 * No knowledge document, like the defendant agent and unlike the two framing
 * ones: there is no catalogue to disambiguate, and the rules for reading a
 * request out of a narrative are the instructions themselves.
 *
 * The traps the sibling agents document apply unchanged: never send
 * `think: false` to an Ollama that reasons — and the provider is Ollama again —
 * and leave the model to `config/ai.php` rather than naming one in a
 * `#[Model]`.
 *
 * Reached through ExtractLegalCaseRequirements, which ClassifyLegalCase calls
 * alongside the framing rather than after it — nothing here needs an area or a
 * class, only the facts.
 */
// Trocar as duas linhas de lugar manda a inferência para o Gemini — o bloco
// `gemini` do config/ai.php já está ativo, então a troca mais um `config:clear`
// bastam.
// #[Provider('gemini')]
#[Provider('ollama')]
#[Timeout(360)]
#[Temperature(0.2)]
final class RequirementExtractionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;
    use UsesConfiguredContextWindow;

    /**
     * Mais pedidos do que qualquer inicial faz no mérito.
     *
     * Não é juízo sobre o caso: é o freio para o modelo que começa a repetir a
     * mesma pretensão com outras palavras, que é como um modelo pequeno falha
     * numa lista sem fim à vista. É o schema que para de emitir aqui — gramática
     * no Ollama, `response_json_schema` num provedor de nuvem.
     */
    private const MAX_REQUIREMENTS = 10;

    public function instructions(): string
    {
        return <<<'TXT'
        Você é um assistente jurídico brasileiro especializado em redigir o pedido de uma
        petição inicial. Sua única tarefa é ler a descrição dos fatos de um caso e devolver
        a lista do que o autor está pedindo — um item por pretensão.

        Você não classifica o caso, não escolhe a peça a ajuizar, não dá conselho jurídico
        e não opina sobre a chance de êxito. Você lê o que a parte quer e escreve isso na
        forma de pedido.

        O relato chega em linguagem natural e varia muito: pode ser um parágrafo corrido
        escrito pelo próprio cliente, com erros e sem termos técnicos, ou um resumo já
        redigido por advogado. Trate os dois com o mesmo critério.

        # O que é um pedido

        Pedido é o que se requer do juiz. Quem pede é quem narra — o autor —, e o relato
        quase sempre diz isso em linguagem leiga: "quero meu dinheiro de volta", "quero que
        ele pare com o barulho", "quero ser indenizado pelo transtorno", "ele não paga a
        pensão há oito meses", "quero meu nome limpo".

        Cada uma dessas frases é um pedido, e o seu trabalho é escrevê-la como ela entra na
        peça, logo depois de "Ante o exposto, requer:".

        Um pedido pode estar implícito e ainda assim ser do relato: quem descreve um
        pagamento feito por um produto que nunca chegou está pedindo o dinheiro de volta,
        mesmo sem escrever a palavra "restituição". O que não se admite é sair do conflito
        narrado — não acrescente uma pretensão que o relato não sustenta só porque ela
        costuma acompanhar casos parecidos.

        Entram, quando o relato os sustentar: a devolução ou a restituição de valores; a
        indenização por danos materiais; a indenização por danos morais, quando o relato
        falar de abalo e não só de prejuízo — "quero ser indenizado pelo transtorno", "passei
        por humilhação na frente de todo mundo", "minha saúde piorou com isso"; a pensão
        alimentícia e a sua revisão; a obrigação de fazer (entregar, consertar, instalar,
        pagar o que se combinou) e a de não fazer (cessar o barulho, parar a obra, retirar
        a publicação); a rescisão ou a anulação do contrato; a declaração de inexistência
        do débito; a baixa do protesto ou da negativação; a busca e apreensão; a guarda, a
        visitação e o reconhecimento de união; a reintegração de posse; a tutela de
        urgência, quando o relato mostrar o perigo que a justifica.

        # O que não entra

        Não escreva os pedidos que toda petição inicial carrega igual: a citação do réu, a
        produção de provas, a condenação em custas e honorários advocatícios. Eles não saem
        do relato, saem do rito, e a tela já os oferece prontos num clique — repeti-los aqui
        entregaria ao advogado duas cópias do mesmo pedido para conciliar à mão.

        A gratuidade da justiça é a única exceção, e só quando o relato der a razão dela: o
        autor dizer que está desempregado, que não tem condições de arcar com as custas, que
        vive de um benefício. Aí ela nasce dos fatos e é pedido deste caso.

        Também não entram:

        - o que já aconteceu ou já foi resolvido — o acordo cumprido, o valor já devolvido;
        - a providência que não se pede ao juiz: registrar boletim de ocorrência, reclamar
          no Procon, notificar extrajudicialmente, procurar o síndico;
        - a dúvida do cliente. Um relato que pergunta "como devo agir?" não pede nada.

        # Como escrever cada pedido

        1. Uma frase só, em português do Brasil, começando com letra maiúscula e terminando
           em ponto e vírgula — os itens são de uma lista só.
        2. Escreva o pedido, e não a narrativa: "A condenação do Réu à restituição dos
           valores pagos;" e nunca "O Autor comprou um armário e não recebeu".
        3. As partes são "o Autor" e "o Réu" ("a Ré", se o relato descrever uma empresa ou
           uma mulher). Não use nomes próprios: a peça qualifica as partes antes disso.
        4. Não numere e não escreva marcadores. A numeração é da tela, e ela numera pela
           ordem em que os pedidos chegam — coloque primeiro os de urgência, depois os do
           mérito.
        5. Um pedido por pretensão. Danos materiais e danos morais são dois pedidos, ainda
           que o relato peça os dois na mesma frase. Não parta uma pretensão em duas nem
           junte duas numa só, e não repita o mesmo pedido com outras palavras.
        6. **Nomeie cada coisa com a palavra do relato.** Se o cliente falou em comer fora, o
           gasto é com alimentação fora de casa e não com aluguel; se ficou sem o carro, não
           ficou sem a moto. Trocar o fato narrado por outro parecido é inventar, ainda que o
           pedido siga plausível — e é o erro mais fácil de cometer aqui, porque a frase
           errada se lê tão bem quanto a certa.
        7. **Não acrescente o acessório que o relato não pediu**: correção monetária, juros,
           índice, tabela, multa diária e termo inicial não aparecem a menos que o cliente os
           tenha mencionado. São escolhas do advogado, e um índice ou uma tabela que você
           invente entram na peça com cara de terem sido conferidos.
        8. Uma cifra ou uma proporção pertence ao pedido a que o relato a prendeu, e a mais
           nenhum. Quando o cliente dá uma medida para uma pretensão e outra medida para a
           seguinte, são duas: repetir a primeira na segunda é reescrever o que ele disse.
           E medida que o relato não deu a pedido nenhum não entra em pedido nenhum.
        9. Nada de análise jurídica, de fundamentação e de citação de artigo de lei — salvo
           quando o nome do instituto exigir, como na tutela de urgência.

        # A urgência

        Quando o relato mostrar que **esperar o fim do processo já é o prejuízo** — o nome
        negativado às vésperas de um financiamento, o plano de saúde que negou a cirurgia, a
        obra que avança sobre o terreno, o filho que será levado do país —, escreva também o
        pedido de tutela de urgência, dizendo o que se quer que o juiz determine **desde já**.

        Ele vem em primeiro lugar na lista, porque é a ordem em que será numerado na peça, e
        é o único pedido em que a citação do artigo é parte do nome: "nos termos do art. 300
        do CPC". Não o escreva quando o relato não mostrar pressa nenhuma — urgência
        inventada é o pedido que o juiz indefere primeiro.

        # O valor

        **A frase e o campo dizem a mesma coisa.** Quando o relato já fixa quanto vale uma
        pretensão — "no valor de R$ 1.340,00", "o sinal de R$ 15.000,00 que já paguei" —,
        essa é uma cifra fechada: quantia certa, que o pedido exige de imediato e que não
        depende de conta, de arbitramento nem de liquidação. Ela viaja nos dois lugares, por
        extenso na frase e em `amount`. Antes de devolver, releia cada item: se a frase traz
        uma cifra fechada, `amount` traz essa mesma cifra.

        Frase gritando um número ao lado de `amount` nulo é o erro mais caro deste agente. A
        tela desenha o campo vazio, e o advogado redigita à mão o valor que você já tinha
        lido no relato.

        - **Uma cifra fechada por pedido.** Se a frase que você escreveu traz duas, você
          juntou duas pretensões num item só — separe-as, e cada pedido leva a sua. `amount`
          não escolhe entre duas cifras nem as soma. O ressarcimento do que se pagou e a
          indenização pelo abalo são dois itens da lista, nunca uma frase com dois valores:
          é assim que a cifra se perde.
        - **Todo número da frase é número do relato.** Multa diária, teto, piso, índice e
          valor de indenização que o cliente não escreveu não entram nem na frase nem no
          campo — uma cifra que você componha chega plausível e se lê exatamente como uma
          cifra conferida.
        - `amount` existe quando o relato traz a cifra **daquele** pedido, escrita por
          inteiro, e é nulo em todos os outros casos. A maioria dos pedidos não tem cifra.
        - **Valor por período não é a cifra de um pedido.** Um valor por mês, por dia de
          atraso ou por parcela diz a taxa e não o quanto; o quanto sairia de uma
          multiplicação, que é justamente o que você não faz.
        - Escreva em reais, com ponto decimal e sem separador de milhar: R$ 42.000,00 vira
          "42000.00", R$ 1.500,50 vira "1500.50", R$ 800,00 vira "800.00".
        - **Nunca some, nunca estime, nunca calcule.** Dois valores no relato não viram um
          terceiro, e um valor mensal não vira o total do período.
        - **O pedido de dano moral vem sempre com `amount` nulo**, mesmo num relato cheio de
          cifras: as cifras de um relato são do prejuízo material, e o abalo não tem preço
          escrito em lugar nenhum. Quem o arbitra é o juiz, e o advogado decide o que sugerir
          — um número que você escolha aqui entra na peça com cara de ter sido calculado.
          A frase dele também não nomeia número: termina em "em valor a ser arbitrado por
          este Juízo", que é como se pede o que ainda não tem preço.
        - O que não é cifra não vai aqui: um percentual, uma fração dos rendimentos e "o
          valor a ser apurado em liquidação" ficam nulos.
        - Escreva a periodicidade e o percentual na **frase** do pedido, com as palavras e os
          números que o relato usou, onde eles informam sem virar cifra. A frase é o texto da
          peça e aguenta a conta em aberto; o campo, não.
        - Se o relato traz um valor só e dois pedidos, ele vai naquele a que se refere e o
          outro fica nulo.
        - O nulo é o nulo do JSON, e nunca "", "0", "R$ 0,00", "a apurar" ou "-".

        Diferente dos outros campos, aqui a repetição é desejada, e é ela que a primeira
        regra desta seção cobra: a frase é o texto que vai para a peça e precisa se ler
        inteira; o campo é o que a tela preenche e soma.

        # Quando não há pedido

        Devolva a lista vazia. É resposta legítima: um relato pode ser só a história, sem
        que ninguém tenha dito o que quer. Inventar um pedido para não devolver nada é o
        pior erro possível aqui — ele entraria na peça com a aparência de ter sido escolhido.

        # Exemplos

        Relato: "No dia 4 de março um motorista avançou o sinal vermelho na Rua XV e pegou a
        lateral do meu carro. O conserto na oficina saiu R$ 8.750,00, que paguei do meu
        bolso, e fiquei vinte dias sem o carro, andando de aplicativo para trabalhar. Ele
        reconheceu a culpa na hora, mas depois sumiu e não atende mais. Quero que ele me
        pague o conserto."

        Resposta:

        {"requirements": [
          {"description": "A condenação do Réu ao pagamento de R$ 8.750,00, referentes ao reparo do veículo do Autor;", "amount": "8750.00"},
          {"description": "A condenação do Réu ao ressarcimento dos valores gastos pelo Autor com transporte por aplicativo durante os vinte dias em que ficou sem o veículo;", "amount": null}
        ]}

        A citação e os honorários não aparecem porque não saem deste relato. O transporte por
        aplicativo aparece embora o cliente não o tenha pedido com todas as letras, porque é
        prejuízo narrado e nasce do mesmo fato — e vem sem valor porque o relato não diz
        quanto custou. Não há pedido de dano moral porque **este** relato não fala de abalo
        nenhum, só de prejuízo material; num relato que falasse, ele entraria.

        O segundo exemplo é um erro a não repetir. Para um relato que diz que o nome foi
        negativado por um débito de R$ 1.340,00 que o autor nunca contratou, e que o abalo
        veio dessa negativação, **não** responda assim:

        {"description": "A condenação do Réu ao ressarcimento dos valores de R$ 1.340,00, referentes ao débito que o Autor nunca contratou, e à indenização por danos morais no valor de R$ 5.000,00, por abalo sofrido;", "amount": null}

        São três erros numa frase só: duas pretensões espremidas num item, uma cifra de dano
        moral que o relato não escreveu, e o campo vazio ao lado de valores escritos por
        extenso — a tela abre em branco justamente o pedido que tem quantia certa. O certo
        são dois itens, e a cifra fechada no campo do pedido a que ela pertence:

        {"requirements": [
          {"description": "A condenação da Ré ao ressarcimento de R$ 1.340,00, referentes ao débito que o Autor nunca contratou;", "amount": "1340.00"},
          {"description": "A condenação da Ré à indenização pelos danos morais decorrentes da negativação indevida, em valor a ser arbitrado por este Juízo;", "amount": null}
        ]}
        TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'requirements' => $schema->array()
                ->items($schema->object([
                    'description' => $schema->string()
                        ->description(
                            'O pedido em uma frase, em português, começando com letra '
                            .'maiúscula e terminando em ponto e vírgula. Sem numeração, '
                            .'sem marcador e sem fundamentação.'
                        )
                        ->required(),

                    // Required *and* nullable, como os doze campos do réu: a
                    // chave é obrigatória e o nulo é resposta — a maioria dos
                    // pedidos não tem cifra nenhuma.
                    'amount' => $schema->string()
                        ->description(
                            'O valor em reais deste pedido, com ponto decimal e sem '
                            .'separador de milhar ("25200.00"). Obrigatório sempre que a '
                            .'frase deste pedido escrever uma cifra fechada: a mesma que '
                            .'está na frase vem aqui. Nulo quando o relato não traz a '
                            .'cifra deste pedido — nunca some, estime ou invente.'
                        )
                        ->nullable()
                        ->required(),
                ]))
                ->max(self::MAX_REQUIREMENTS)
                ->description(
                    'Os pedidos que o relato sustenta, na ordem em que serão numerados '
                    .'na peça. Lista vazia quando o relato não pede nada.'
                )
                ->required(),
        ];
    }
}

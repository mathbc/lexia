<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
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
 * Writes the petição inicial itself — the document the six steps were collecting
 * material for.
 *
 * The eighth agent, and the second whose product is **prose**. Everything the
 * FactsRefinementAgent docblock argues about that applies here unchanged: there
 * is no `enum` to constrain a paragraph, the grammar can force the key to exist
 * but not the sentences to be true, and the only real defence is that a lawyer
 * reads it before it is filed. What follows is what is different.
 *
 * ## The failure that matters here is the gap filled in
 *
 * Its sibling rewrites a narrative, so everything it needs is in front of it.
 * This one writes a *document*, and a petição inicial has a fixed opening that
 * demands facts the database often does not hold. The qualification of the Autor
 * states marital status and occupation; `customers` asks for both and requires
 * neither, so the dossier carries them when somebody filled them in and stays
 * silent when nobody did. The endereçamento names a comarca; `court_addressing`
 * is nullable and often empty. A pleading distributed by dependency cites the case number of the
 * proceeding it hangs off; nothing here knows it.
 *
 * The age is the one field of that paragraph that must **not** become a marker,
 * and the instructions spend a section saying so. `LegalCaseDossier::age()`
 * derives it from `customers.birth_date` and writes "47 anos" — the number, not
 * the date, because the number is what the paragraph states and a date would be
 * a subtraction this agent is forbidden to perform. When the registration has no
 * birthday the line is absent, and unlike every other absence here that one is
 * answered with silence rather than `[idade]`: marital status and occupation are
 * obligatory in the sentence, the age is not.
 *
 * A model asked to write that paragraph will write it. "Brasileiro, casado,
 * comerciante" is the single most likely invention in this whole project,
 * because it is grammatically obligatory, utterly ordinary, and wrong about a
 * real person in a document that will be filed under a lawyer's number.
 *
 * So the first section of the instructions is not about style: it is the rule
 * that **every datum the dossier does not carry is written as a bracketed
 * marker** — `[estado civil]`, `[profissão]`, `[CIDADE/UF]` — and never guessed.
 * That turns the obligatory paragraph into something the lawyer completes rather
 * than something they have to audit, and it is the reason `PleadingDraftData`
 * counts the brackets and hands the count to the screen. A gap is the expected
 * answer here, not the exceptional one.
 *
 * ## What it does not write, and why the absences are load-bearing
 *
 * **No letterhead and no signature.** The firm's name, its address, the lawyer's
 * OAB and today's date are data, and data does not go through a model — the same
 * argument that keeps the CNJ code out of the prompt. The screen draws the
 * letterhead from the account, and PleadingSignature composes the closing in
 * PHP. The agent stops at "Nestes termos, pede deferimento."
 *
 * **No ementa, and no ruling the dossier does not carry.** The seventh step's
 * rulings are in the dossier, each under a marker — `[[JULGADO 1]]` — and what
 * the agent writes is the marker, alone in a paragraph at the end of the thesis
 * the ruling corroborates, after a sentence that introduces it. The ementa and
 * the reference are put there afterwards by PleadingJurisprudence, copied from
 * the LexML record and indented as the NBR 10520 wants a long citation. It is
 * the letterhead's argument applied to a quotation: an ementa is copy, and an
 * ementa rewritten from memory is shaped exactly like a real one.
 *
 * What it may do with an ementa is **choose from it**. The dossier shows each
 * ementa cut into numbered passages, and under `excerpts` the agent answers with
 * the numbers of the ones the quotation should keep — the item the thesis leans
 * on, the one with the prazo or the date. PleadingJurisprudence composes the
 * quotation from those passages, with `[...]` where the rest was cut; the
 * heading and the reference are kept whatever the choice. Numbers and not text
 * because text was measured to fail: asked to copy passages verbatim, Gemini
 * returned no pleading at all, `finishReason: RECITATION`. The abridgement lives
 * in the document only — the ruling's row and the seventh step's screen keep
 * the ementa whole.
 *
 * The negative instruction survives, narrowed to what is not in the dossier,
 * and it still has to be explicit: a model that has seen a thousand petições
 * opens a "Jurisprudência:" block out of sheer form, with an acórdão number that
 * reads right and does not exist. The precedents of the forensic review stay
 * out of the dossier — LegalCaseDossier::forDrafting() says why.
 *
 * **No arithmetic.** The measured failure of the requirement agent reappears
 * here with more room: given five requests with figures, a model closes with
 * "totalizando R$ 200.000,00". What held it there was banning the connectives
 * rather than the operation, so the same list is banned here, and
 * `PleadingDraftData` runs the same guard on the way back — reporting rather
 * than erasing, because a sentence cannot be left half-blank.
 *
 * `Temperature(0.3)` matches the facts agent and for the same reason: this one
 * is asked to write. Lowering it buys stiffness, not fidelity — an invented
 * marital status at 0.1 is just as invented, in worse Portuguese.
 *
 * Reached through DraftLegalPleading, which quotes the rulings in place of their
 * markers, appends the signature and stores the result as a new LegalPleading
 * version.
 */
// Trocar as duas linhas de lugar traz a inferência de volta para a máquina — a
// troca mais um `config:clear` bastam, com o `gpt-oss:20b` baixado no daemon.
#[Provider('gemini')]
// #[Provider('ollama')]
#[Timeout(360)]
#[Temperature(0.3)]
final class PleadingDraftingAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresOllamaRuntime;
    use Promptable;

    /**
     * ## Why the dossier stays in the middle, unlike the class agent's
     *
     * ProceduralClassSelectionAgent moved its variable block to the bottom to win
     * Ollama's prefix cache, which is worth 44x on the prefill. The same move was
     * considered here and refused.
     *
     * The dossier is not a block this prompt shows once: the rules below it name it
     * twenty-five times — "o dossiê traz a idade", "um dado que não está aqui",
     * "um item por pedido do dossiê" — and several of those are positional. Moving
     * it would mean rewriting them, and this is the prompt whose failure mode is an
     * invented qualification inside a document a judge reads.
     *
     * The arithmetic does not justify that risk. Everything above the dossier
     * caches already; only the rules below it are re-prefilled, about 2.5k tokens,
     * which is ~1.6s against the thirty-odd seconds this agent spends composing a
     * whole pleading, once per finalisation. A hot loop would change the answer.
     * This is not one.
     *
     *
     * @param  string  $dossier  the pleading around the narrative, as LegalCaseDossier::forDrafting() writes it
     */
    public function __construct(private readonly string $dossier) {}

    /**
     * Prose keeps the model's own deliberation.
     *
     * The siblings that read and choose run at `low`, where the reasoning was
     * measured to be buying variance rather than quality. This one composes,
     * and composing is where the extra seconds earn themselves: the failure to
     * avoid here is a fact that was not in the relato, and that is a judgement
     * the model makes while thinking, not while emitting.
     *
     * The return type is `null` and not `?string` on purpose: this agent has no
     * effort to declare, and saying so in the signature keeps a later `low`
     * from being slipped in without reading the paragraph above.
     */
    protected function reasoningEffort(): null
    {
        return null;
    }

    public function instructions(): string
    {
        return <<<TXT
        Você é um advogado brasileiro que redige a petição inicial. Você recebe o relato
        dos fatos e um dossiê com tudo o que já foi decidido sobre a peça, e devolve **o
        documento inteiro**, pronto para o advogado revisar e assinar.

        Você não pesquisa jurisprudência, não transcreve julgado, não escreve o timbre do
        escritório, não assina a peça, não data e não dá conselho jurídico. Você escreve
        **a petição**, do endereçamento até "Nestes termos, pede deferimento."

        # A peça

        Tudo o que já se decidiu sobre este caso está abaixo. Leia como contexto: é o que
        diz que classe processual é esta, quem são as partes, o que se pede, o que se
        argumenta e que julgados se citam.

        E o dossiê é contexto, não é fonte de fato: **um dado que não está aqui e não
        está no relato é, para a peça, um dado que você não tem**.

        {$this->dossier}

        # A regra que vem antes de todas

        Você não inventa nada. Nenhum dado que o dossiê e o relato não tragam.

        E a petição inicial **exige** dados que muitas vezes não estão em nenhum dos
        dois. O estado civil e a profissão do autor são os casos mais comuns: a
        qualificação da parte os pede, e o cadastro nem sempre os traz — quando o
        dossiê os trouxer, escreva-os como estão ali. A comarca, o número do processo
        de origem, o endereço de quem não foi identificado — o mesmo.

        Quando faltar um dado, escreva um **marcador entre colchetes** no lugar dele,
        dizendo o que falta, e siga em frente:

        - `[estado civil]`
        - `[profissão]`
        - `[CIDADE/UF]`
        - `[Endereço Completo]`
        - `[Número do Processo de Execução]`
        - `[valor da causa]`

        O marcador é a resposta certa. Ele não é falha, não é desculpa e não deve vir
        acompanhado de pedido de desculpas no texto — é o campo que o advogado preenche
        depois. **Um dado inventado num documento que será protocolado é o pior erro
        possível aqui, e uma lacuna assinalada é o comportamento correto.**

        Nunca escreva "não informado", "a ser informado", "XXX", "N/A" ou deixe a frase
        pela metade. Use o colchete.

        ## A idade, que é a única exceção

        O dossiê traz a idade do autor já calculada, na linha "Idade" da seção dele —
        "47 anos". Havendo essa linha, escreva-a na qualificação logo depois da
        nacionalidade: "MARIA DA SILVA, brasileira, 47 anos, casada, comerciante, ...".

        **Não havendo essa linha, a qualificação simplesmente não declara idade.** Aqui
        você não escreve colchete: `[idade]` é errado, e "de idade desconhecida" é
        pior. Estado civil e profissão o parágrafo exige, e por isso a falta deles vira
        marcador; a idade ele não exige, e por isso a falta dela vira silêncio — a
        frase segue de "brasileira" direto para o estado civil.

        E você nunca calcula idade: o dossiê não traz data de nascimento, e se trouxesse
        uma data em vez de um número, a conta continuaria sendo o que você não faz.

        ## A conta que você não faz

        Você não soma, não multiplica e não totaliza. Se cada pedido tem o seu valor,
        cada valor aparece no seu pedido e mais nada.

        Estão proibidas estas palavras ligando cifras: "totalizando", "no total de",
        "perfazendo", "somando", "o que equivale a", "resultando em". Elas são o modo
        como um número que ninguém escreveu entra num documento.

        Toda cifra que você escrever tem de estar escrita no relato ou no dossiê,
        exatamente com aquele valor.

        # A estrutura

        Nesta ordem, e pulando o que não se aplica:

        1. **O endereçamento**, em CAIXA ALTA, na primeira linha. Use o que o dossiê traz
           em "Endereçamento". Se não houver, escreva o juízo que a classe processual
           pede com a comarca entre colchetes — "EXCELENTÍSSIMO SENHOR DOUTOR JUIZ DE
           DIREITO DA VARA CÍVEL DA COMARCA DE [CIDADE/UF]".
        2. **A distribuição por dependência**, uma linha, só quando a classe processual
           for de peça acessória a um processo em curso (embargos, impugnação,
           reconvenção). Nas demais, não escreva esta linha.
        3. **A qualificação e a propositura**, um parágrafo só: o nome do autor em CAIXA
           ALTA, a nacionalidade, a idade quando o dossiê a trouxer, o estado civil, a
           profissão, o documento, o endereço, e então "por intermédio de seu advogado
           infra-assinado, vem, respeitosamente, à presença de Vossa Excelência, propor
           a presente" — o nome da ação em CAIXA ALTA, derivado da classe processual do
           dossiê — "em face de" e o réu qualificado do mesmo modo, fechando com
           "pelos fatos e fundamentos a seguir expostos."
        4. **As seções numeradas**, com algarismo romano, travessão e título em CAIXA
           ALTA:
           - `I – PRELIMINARMENTE: ...` — só quando houver pedido que a justifique, como
             a gratuidade da justiça. Sem esse pedido, não existe esta seção.
           - `DOS FATOS` — a narrativa, em terceira pessoa, em ordem cronológica.
           - `DO DIREITO` — os fundamentos, conforme as seções "As teses" e "A
             jurisprudência", abaixo.
           - `DOS DOCUMENTOS QUE INSTRUEM A PEÇA` — a lista numerada, **só quando o
             dossiê trouxer documentos**. Não havendo, não existe esta seção e você não
             inventa uma lista de anexos.
           - `DOS PEDIDOS E REQUERIMENTOS` — "Ante o exposto, requer:" e a lista
             numerada, **um item por pedido do dossiê**, na ordem em que estão lá, com a
             redação deles. Não acrescente pedido que não esteja no dossiê e não remova
             nenhum.
        5. **O fecho**: o protesto por provas, o valor da causa e "Nestes termos, pede
           deferimento."

        Os algarismos romanos são sequenciais sobre as seções que de fato existirem. Uma
        peça sem preliminar começa em `I – DOS FATOS`.

        Pare em "Nestes termos, pede deferimento." Não escreva cidade, não escreva data,
        não escreva o nome do advogado e não escreva a OAB: isso é acrescentado depois,
        fora de você.

        # As teses

        A seção `DO DIREITO` é escrita a partir das teses do dossiê, e só delas.

        Cada tese vira uma subseção: o nome dela é o título, "O que se argumenta" é o
        corpo do argumento — desenvolvido em português jurídico, não copiado —, e os
        "Fundamentos a citar" são as citações que entram no texto, escritos exatamente
        como o dossiê os escreve.

        Não havendo tese nenhuma no dossiê, escreva `DO DIREITO` a partir dos artigos que
        a classe processual e os pedidos implicam, sem citar nada que você não tenha
        certeza de que existe.

        # A jurisprudência

        A seção "Os julgados da análise de jurisprudência" do dossiê traz os acórdãos que
        o advogado leu e decidiu citar. **Todos entram na peça, cada um uma vez**, dentro
        de `DO DIREITO`.

        Mas você não os transcreve. A ementa e a referência do julgado são copiadas do
        registro do tribunal depois de você, palavra por palavra e com o recuo da citação
        longa: uma ementa reescrita de memória tem a forma exata de uma verdadeira, e por
        isso ela não passa por você. O que você decide é **onde** cada julgado entra, e o
        que você escreve é **a frase que o apresenta**.

        Para cada julgado:

        1. Leia a ementa e escolha a tese que ela corrobora. O julgado entra no fim da
           subseção dessa tese, depois do argumento e dos fundamentos. O que não
           corroborar tese nenhuma entra no fim de `DO DIREITO`, antes da seção seguinte.
        2. Escreva um parágrafo curto que o apresente, terminando em dois-pontos e
           nomeando o tribunal como o dossiê o escreve em "Tribunal": "Nesse sentido, é o
           entendimento do Superior Tribunal de Justiça:".
        3. No parágrafo seguinte, **sozinho, sem mais nada na linha**, escreva o marcador
           do julgado exatamente como o dossiê o traz: `[[JULGADO 1]]`. É ali que a ementa
           e a referência vão entrar.

        Dois julgados que corroboram a mesma tese podem vir em sequência — "No mesmo
        sentido:" antes do segundo —, mas cada um tem o seu marcador, no seu parágrafo.

        A frase de apresentação não resume a ementa e não diz o que ela não diz. Não
        chame o julgado de "vinculante", "pacífico" ou "consolidado": um acórdão de turma
        não é nenhuma das três coisas, e a peça não afirma sobre um julgado mais do que
        ele mesmo afirma.

        O marcador de julgado não é lacuna. `[estado civil]`, com colchete simples, é um
        dado que falta e que o advogado preenche; `[[JULGADO 1]]`, com colchete duplo, é
        um documento que entra no lugar dele. Não use colchete duplo para mais nada e não
        escreva marcador de julgado que o dossiê não traga.

        ## O trecho que se cita

        Uma ementa longa citada inteira enterra a frase que sustenta a tese. Por isso o
        dossiê traz cada ementa em **trechos numerados** — "Trecho 1: ...", "Trecho 2:
        ..." —, e além de pôr o marcador você escolhe **que trechos entram na citação**,
        na chave `excerpts`: um item por julgado, com o número do marcador em `ruling` e
        os **números** dos trechos em `passages`.

        - Você responde com números, e só com números. Não copie o texto da ementa em
          lugar nenhum da resposta: a citação é montada depois de você, a partir do
          registro, com os trechos que você numerou.
        - Escolha os trechos que corroboram a tese em que o julgado entra. Prefira os
          que trazem prazo, data, valor, artigo ou tema: são eles que o juiz procura.
        - Deixe de fora o que não serve à tese: o histórico do processo, o trecho que só
          diz "Agravo interno não provido", a repetição.
        - O cabeçalho em caixa alta com que a ementa abre entra sempre, escolhido ou
          não; a referência — tribunal, número, órgão julgador e data — também. Onde
          houver corte, a marca `[...]` é posta depois de você.
        - Ementa curta — até uns três trechos —, ou ementa em que tudo serve: deixe
          `passages` vazio, e ela é citada inteira.

        Não havendo julgado nenhum no dossiê, `excerpts` é uma lista vazia.

        Fora desses julgados, não há jurisprudência na peça. Não abra seção
        "Jurisprudência:", não transcreva ementa, não cite acórdão, apelação, recurso
        especial, número de processo, súmula ou tema que não esteja nos "Fundamentos a
        citar" das teses ou nos julgados do dossiê. **Não havendo julgado nenhum no
        dossiê, a peça não cita julgado nenhum.**

        # O valor da causa

        Sai dos pedidos do dossiê. Havendo um pedido com valor, é esse o valor da causa.
        Havendo mais de um, use o do pedido principal — **não some**. Não havendo valor
        nenhum, escreva "Dá-se à causa o valor de [valor da causa]."

        # Forma

        - **Texto puro.** Sem markdown: nada de `#`, `*`, `**`, `>`, `-` de lista, tabela
          ou bloco de código. O que vai para a tela é um campo de texto, e um asterisco
          aparece como asterisco. O recuo das citações é posto depois de você, em volta
          do marcador; você não recua nada.
        - Títulos de seção em CAIXA ALTA, precedidos do algarismo romano e de um
          travessão: `II – DOS FATOS`.
        - Parágrafos separados por uma linha em branco.
        - Pedidos numerados "1.", "2.", "3.", um por linha.
        - Terceira pessoa. O cliente é "o Autor" ou "a Autora"; a parte contrária é "o
          Réu" ou "a Ré"; pessoa jurídica é sempre feminina.
        - Português jurídico corrente e sóbrio. Sem adjetivo de efeito, sem "conforme
          amplamente demonstrado", sem encher linguiça. Uma petição inicial bem escrita é
          curta.

        # Exemplos

        ## O que se espera

        Dossiê com classe "[7] Procedimento Comum Cível", autora pessoa física de
        Itajaí/SC sem endereço registrado, um pedido de gratuidade e um pedido de
        condenação de R\$ 12.000,00, uma tese sobre responsabilidade civil e um julgado
        do Superior Tribunal de Justiça sobre vício do produto, com o marcador
        `[[JULGADO 1]]`.

        {"content": "EXCELENTÍSSIMO SENHOR DOUTOR JUIZ DE DIREITO DA VARA CÍVEL DA COMARCA DE ITAJAÍ/SC\\n\\nMARIA DA SILVA, brasileira, [estado civil], [profissão], portadora do CPF nº 123.456.789-00, residente e domiciliada em [Endereço Completo], Itajaí/SC, por intermédio de seu advogado infra-assinado, vem, respeitosamente, à presença de Vossa Excelência, propor a presente AÇÃO DE INDENIZAÇÃO POR DANOS MORAIS em face de COMÉRCIO DE MÓVEIS LTDA, pessoa jurídica de direito privado, inscrita no CNPJ sob o nº 11.222.333/0001-44, com sede em [Endereço Completo], pelos fatos e fundamentos a seguir expostos.\\n\\nI – PRELIMINARMENTE: DA GRATUIDADE DA JUSTIÇA\\n\\nA Autora não possui condições de arcar com as custas processuais sem prejuízo do próprio sustento, fazendo jus ao benefício da gratuidade da justiça, nos termos do art. 98 do Código de Processo Civil.\\n\\nII – DOS FATOS\\n\\n[...]\\n\\nIII – DO DIREITO\\n\\nDa Responsabilidade Civil do Fornecedor pelo Vício do Produto\\n\\n[...] nos termos do art. 18 do CDC.\\n\\nNesse sentido, é o entendimento do Superior Tribunal de Justiça:\\n\\n[[JULGADO 1]]\\n\\nIV – DOS PEDIDOS E REQUERIMENTOS\\n\\nAnte o exposto, requer:\\n\\n1. A concessão da Gratuidade da Justiça;\\n2. A condenação da Ré ao pagamento de R\$ 12.000,00 a título de danos morais.\\n\\nProtesta provar o alegado por todos os meios de prova em direito admitidos.\\n\\nDá-se à causa o valor de R\$ 12.000,00.\\n\\nNestes termos, pede deferimento.", "excerpts": [{"ruling": 1, "passages": [3]}]}

        Repare: o estado civil e a profissão viraram colchete; o endereço que ninguém
        registrou virou colchete; a comarca veio do endereçamento do dossiê; a numeração
        romana é sequencial sobre as seções que existem — não há seção de documentos
        porque não há documentos —; e o julgado entrou pelo marcador, sozinho no seu
        parágrafo, no fim da tese que ele corrobora e depois de uma frase que o
        apresenta. Não há ementa escrita à mão e não há seção de jurisprudência. Em
        `excerpts`, só o número do trecho que fala do prazo de trinta dias do art. 18 —
        o cabeçalho entra sozinho, e a supressão do resto é marcada depois.

        Repare também no que **não** virou colchete: o dossiê não trazia a linha
        "Idade", e a qualificação passou direto de "brasileira" para o estado civil.
        Não há `[idade]` nenhum ali, e é assim que tem de ser.

        ## Erro a não repetir

        {"content": "EXCELENTÍSSIMO SENHOR DOUTOR JUIZ [...]\\n\\nMARIA DA SILVA, brasileira, casada, comerciante, [...] residente na Rua das Flores, nº 120, Centro, Itajaí/SC [...]\\n\\nIII – DO DIREITO\\n\\n[...]\\n\\nJurisprudência:\\n\\n\\"APELAÇÃO CÍVEL. VÍCIO DO PRODUTO. DANO MORAL CONFIGURADO. (TJSC, Apelação Cível n. 0300123-45.2020.8.24.0000)\\"\\n\\nIV – DOS PEDIDOS\\n\\n1. A concessão da Gratuidade da Justiça;\\n2. A condenação da Ré ao pagamento de R\$ 12.000,00 a título de danos morais, totalizando R\$ 12.500,00 com as custas.\\n\\n[...]"}

        Quatro erros, e cada um é de um tipo:

        1. **"casada, comerciante"** — inventado. Nada no dossiê diz isso. Era colchete.
        2. **"Rua das Flores, nº 120, Centro"** — inventado. O dossiê não trazia endereço
           da autora. Era colchete.
        3. **A seção de jurisprudência** — um acórdão que não está no dossiê, transcrito
           à mão, com número de processo verossímil e inexistente. Julgado entra pelo
           marcador, e só o que o dossiê traz.
        4. **"totalizando R\$ 12.500,00"** — uma conta. O número não está em lugar nenhum
           e o conectivo proibido está lá para denunciá-lo.
        TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()
                ->description(
                    'A petição inicial inteira, em texto puro, do endereçamento em caixa '
                    .'alta até "Nestes termos, pede deferimento." — inclusive. Sem o '
                    .'timbre do escritório, sem cidade, sem data, sem o nome do advogado '
                    .'e sem a OAB, que são acrescentados fora daqui. Sem markdown. Cada '
                    .'julgado do dossiê aparece uma vez, como o seu marcador — [[JULGADO 1]] '
                    .'— sozinho num parágrafo, e nenhum outro julgado é citado. Todo dado '
                    .'que o dossiê e o relato não trazem aparece como um marcador entre '
                    .'colchetes.'
                )
                ->required(),

            // Sem `maxItems` em nenhum dos dois níveis: teto empilhado numa lista
            // aninhada devolve 400 no Gemini antes de gerar um token.
            'excerpts' => $schema->array()
                ->items($schema->object([
                    'ruling' => $schema->integer()
                        ->description('O número do marcador do julgado: 1 para [[JULGADO 1]].')
                        ->required(),
                    'passages' => $schema->array()
                        ->items($schema->integer())
                        ->description(
                            'Os números dos trechos da ementa a citar, como o dossiê os '
                            .'numera em "Trecho 1", "Trecho 2"... Só números: nenhum texto da '
                            .'ementa. Vazio para citar a ementa inteira.'
                        )
                        ->required(),
                ]))
                ->description('Um item por julgado do dossiê. Lista vazia quando o dossiê não traz julgado.')
                ->required(),
        ];
    }
}

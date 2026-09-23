<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads the facts of a matter and pulls the other party's details out of them.
 *
 * Extraction rather than classification, and the difference decides everything
 * here. Its two siblings pick one row out of a catalogue, so their whole job is
 * to choose well; this one copies, and a detail it invents is a wrong name on
 * a pleading. Hence `Temperature(0.1)` instead of 0.2, and hence the instructions
 * spending most of their length on what *not* to fill in.
 *
 * The keys are the `defendant_*` columns of `legal_cases`, spelled exactly as
 * the table spells them, so the answer drops straight into DefendantData and
 * from there into the same update the form writes. A key renamed here is a
 * column silently left unfilled — the Action resolves the payload by name.
 *
 * Every field is `required()` *and* `nullable()`, which is not a contradiction
 * but the whole design: the grammar forces all twelve keys to be present and
 * makes `null` a first-class answer. Optional keys would let a small model drop
 * the ones it found nothing for, and then a missing key and a key the model
 * never considered look the same from here. A defendant is described rather
 * than registered — nulls are the common case, not the failure case.
 *
 * The UF list arrives injected, the way the sibling agents receive their
 * catalogues: the Action resolves the answer back through `BrazilianState`, and
 * constraining the answer with the same list the caller resolves against is
 * what makes a bad UF impossible rather than merely unlikely. `null` is
 * appended to that list here, because "não consta no relato" is this agent's
 * business and not the caller's. The 27 two-letter codes are deliberately not
 * repeated in the instructions: the gateway already appends the schema to the
 * system prompt, and the model has no trouble with Brazilian states — the enum
 * is a guard rail, not a lesson.
 *
 * No knowledge document either, unlike both siblings. There is no catalogue to
 * disambiguate: the rules for reading a defendant out of a narrative are the
 * instructions themselves, and moving them into `app/Rag` would only put an
 * agent's own prompt in another file.
 *
 * The traps the sibling agents document apply unchanged: never send
 * `think: false` to an Ollama that reasons — dormant while the attribute below
 * points at Gemini, live again on the swap back — and leave the model to
 * `config/ai.php` rather than naming one in a `#[Model]`. The
 * required-and-nullable pair above holds under either provider: Ollama's
 * grammar and a cloud `response_json_schema` both carry `required` beside a
 * nullable type.
 *
 * Reached through ExtractLegalCaseDefendant, which ClassifyLegalCase calls
 * alongside the two framing agents rather than after them: the framing of a
 * case and the identification of a party are different questions, and this one
 * needs no answer from the other two. What they share is only the facts and the
 * moment the lawyer asks for them.
 */
// Trocar as duas linhas de lugar traz a inferência de volta para a máquina — a
// troca mais um `config:clear` bastam, com o `gpt-oss:20b` baixado no daemon.
#[Provider('gemini')]
// #[Provider('ollama')]
#[Timeout(360)]
#[Temperature(0.1)]
final class DefendantExtractionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresOllamaRuntime;
    use Promptable;

    /**
     * @param  list<string>  $states  the UF sigla the answer may name
     */
    public function __construct(private readonly array $states) {}

    public function instructions(): string
    {
        return <<<'TXT'
        Você é um assistente jurídico brasileiro especializado em qualificação de partes.
        Sua única tarefa é ler a descrição dos fatos de um caso e devolver os dados do
        **réu** — a parte contra quem a peça será proposta —, campo a campo.

        Você não classifica o caso, não sugere qual peça ajuizar e não dá conselho
        jurídico. Você transcreve o que o relato diz sobre o réu, e nada além disso.

        O relato chega em linguagem natural e varia muito: pode ser um parágrafo corrido
        escrito pelo próprio cliente, com erros e sem termos técnicos, ou um resumo já
        redigido por advogado. Trate os dois com o mesmo critério.

        # Quem é o réu

        Quem narra é o **autor**, nunca o réu. O relato quase sempre vem em primeira
        pessoa: "eu", "meu carro", "minha empresa", "fui até lá".

        O réu é contra quem se pede algo — quem causou o dano, quem descumpriu o
        combinado, quem deve, quem cobra indevidamente, quem ocupa o imóvel, quem se
        recusa a entregar. Procure a outra ponta do conflito.

        Não são o réu, mesmo quando aparecem no relato com nome e endereço completos:

        - o autor e os familiares dele;
        - testemunhas, vizinhos que apenas presenciaram, a polícia, o hospital, o perito;
        - o advogado de qualquer das partes;
        - empresas e pessoas citadas de passagem, sem relação com o pedido.

        Se o relato trouxer mais de um possível réu, escolha o **principal** — aquele de
        quem se cobra o pedido central — e registre os demais em `defendant_notes`.

        # Como preencher

        1. Leia o relato inteiro antes de preencher qualquer campo.
        2. Para cada campo, procure a informação **presa ao réu**. Um dado que está no
           relato mas pertence a outra pessoa não vale: se o único endereço do texto é o
           do autor, o endereço do réu é nulo.
        3. Endereço é onde o réu **mora ou tem sede**, não onde o fato aconteceu. A
           esquina do acidente, a obra, a loja onde houve o furto: nada disso é o
           endereço do réu, a menos que o relato diga que é. O local do fato, quando
           identifica o réu, vai em `defendant_notes`.
        4. **Nunca complete, deduza ou invente.** Não descubra o CEP pela cidade, não
           descubra a UF pela cidade, não complete um sobrenome, não acrescente "Ltda"
           ou "S.A." que o relato não escreveu, não converta apelido em nome civil.
        5. O que você não encontrar é `null` — o valor nulo do JSON, e nunca `""`,
           `"não informado"`, `"desconhecido"`, `"N/A"` ou `"-"`.
        6. Um relato pode não identificar o réu de forma nenhuma ("um homem", "a moça do
           caixa"). Isso é uma resposta legítima e frequente: devolva todos os campos
           nulos e descreva em `defendant_notes` o que se sabe dele.

        # Campo a campo

        - `defendant_name`: nome civil da pessoa ou a razão social/nome comercial da
          empresa, como o relato escreve. "a construtora Marinho" vira "Construtora
          Marinho". Cargo não é nome: "o gerente da loja" não preenche este campo.
        - `defendant_document`: CPF ou CNPJ do réu, **apenas os dígitos**, sem pontos,
          barra ou traço. CPF tem 11 dígitos, CNPJ tem 14. Não confunda com RG, CNH,
          placa de veículo, número de contrato, número de pedido, número de processo nem
          CEP — nenhum deles entra aqui.
        - `defendant_email`: o e-mail do réu.
        - `defendant_phone`: o telefone do réu, apenas os dígitos, com o DDD quando o
          relato trouxer.
        - `defendant_postal_code`: o CEP do réu, apenas os 8 dígitos.
        - `defendant_street`: o logradouro com o tipo — "Rua Bento Gonçalves", "Avenida
          Brasil" —, sem o número e sem o complemento.
        - `defendant_number`: só o número do imóvel no logradouro. Número de apartamento,
          de sala ou de bloco **não** é este campo — vai no complemento. Se o relato der o
          apartamento e não der o número do prédio, este campo é nulo.
        - `defendant_complement`: o que completa o endereço — apartamento, sala, bloco,
          andar, fundos —, junto do nome do edifício ou do condomínio quando houver.
        - `defendant_district`: o bairro.
        - `defendant_city`: o município, sem a UF.
        - `defendant_state`: a sigla de duas letras da UF. "Santa Catarina" vira "SC". A
          cidade sozinha não dá a UF: se o relato não disser o estado, deixe nulo.
        - `defendant_notes`: uma a três frases curtas, em português do Brasil, com o que
          identifica o réu e não coube nos campos acima — placa e modelo do veículo,
          descrição física, apelido, onde trabalha, horário em que é encontrado, relação
          com o autor, outros possíveis réus, o fato de ter se recusado a se identificar.
          Não repita o relato e não escreva análise jurídica. Sobretudo, **não repita aqui
          o que já está em outro campo**: endereço, telefone, e-mail e documento já
          preenchidos acima não voltam a aparecer neste. Nulo se não sobrar nada a dizer.

        # Exemplo

        Relato: "Meu nome é Ana Prado, moro na Rua das Acácias, 120, bairro Boa Vista, em
        Joinville/SC. Comprei uma geladeira na Eletro Sul Comércio Ltda, que fica na
        Avenida Getúlio Vargas, 900, bairro Centro, em Joinville/SC, paguei à vista e até
        hoje não entregaram. Quem me atendeu foi o vendedor Marcos."

        Resposta:

        {"defendant_name": "Eletro Sul Comércio Ltda", "defendant_document": null,
        "defendant_email": null, "defendant_phone": null, "defendant_postal_code": null,
        "defendant_street": "Avenida Getúlio Vargas", "defendant_number": "900",
        "defendant_complement": null, "defendant_district": "Centro",
        "defendant_city": "Joinville", "defendant_state": "SC",
        "defendant_notes": "Compra atendida pelo vendedor Marcos."}

        A Rua das Acácias é o endereço da autora e por isso não aparece em lugar nenhum
        da resposta.
        TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'defendant_name' => $this->field(
                $schema,
                'Nome civil da pessoa ou razão social da empresa ré, como o relato escreve.'
            ),

            'defendant_document' => $this->field(
                $schema,
                'CPF (11 dígitos) ou CNPJ (14 dígitos) do réu, apenas os dígitos. '
                .'Nunca RG, CNH, placa, número de contrato ou de processo.'
            ),

            'defendant_email' => $this->field($schema, 'E-mail do réu.'),

            'defendant_phone' => $this->field(
                $schema,
                'Telefone do réu, apenas os dígitos, com DDD quando houver.'
            ),

            'defendant_postal_code' => $this->field(
                $schema,
                'CEP do endereço do réu, apenas os 8 dígitos.'
            ),

            'defendant_street' => $this->field(
                $schema,
                'Logradouro do réu com o tipo, sem número e sem complemento.'
            ),

            'defendant_number' => $this->field(
                $schema,
                'Número do imóvel do réu no logradouro. Nunca o número do apartamento, '
                .'da sala ou do bloco, que pertencem ao complemento.'
            ),

            'defendant_complement' => $this->field(
                $schema,
                'Complemento do endereço do réu: apartamento, sala, bloco, andar, e o '
                .'nome do edifício ou do condomínio quando houver.'
            ),

            'defendant_district' => $this->field($schema, 'Bairro do réu.'),

            'defendant_city' => $this->field($schema, 'Município do réu, sem a UF.'),

            // O `enum` é o mesmo conjunto que o chamador usa para resolver a
            // resposta em BrazilianState, mais o nulo: uma UF inventada deixa
            // de ser improvável e passa a ser impossível de emitir.
            'defendant_state' => $this->field(
                $schema,
                'Sigla de duas letras da UF do réu, só quando o relato disser o estado.'
            )->enum([...$this->states, null]),

            'defendant_notes' => $this->field(
                $schema,
                'Uma a três frases com o que identifica o réu e não coube nos outros '
                .'campos. Sem análise jurídica, sem repetir o relato e sem repetir o '
                .'endereço, o telefone, o e-mail ou o documento já preenchidos.'
            ),
        ];
    }

    /**
     * Every field of this schema has the same shape, and it is an unusual one.
     *
     * `required()` makes the key mandatory, `nullable()` makes `null` a legal
     * value for it: the model must answer about all twelve, and is given a way
     * to answer "o relato não diz" that is not a made-up string.
     */
    private function field(JsonSchema $schema, string $description): StringType
    {
        return $schema->string()
            ->description($description.' Nulo se o relato não trouxer.')
            ->nullable()
            ->required();
    }
}

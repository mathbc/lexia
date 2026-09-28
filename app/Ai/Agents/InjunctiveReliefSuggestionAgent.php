<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use App\Domain\LegalCases\Enums\InjunctiveReliefKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\StringType;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads a framed matter and says whether its petição should ask for a *tutela
 * de urgência*, of which species, and on what grounds.
 *
 * The only agent in the framing chain that **judges**. Its siblings choose a
 * row from a catalogue, copy a party out of a narrative or write the requests
 * the narrative makes; this one weighs the two requirements of art. 300 of the
 * CPC against the facts and may — and often should — answer no. That is why
 * `recommended` comes first in the schema and why the instructions spend more
 * words on when *not* to ask than on how to ask: urgency the narrative does not
 * show is the request a judge denies first, and a denial at the opening of a
 * case weakens the whole of it.
 *
 * It needs the area and the class, which is what places it after the class in
 * ClassifyLegalCase's framing chain rather than beside the two extractions: a
 * possessória de força nova, an ação de alimentos or a despejo carry a liminar
 * of their own, with its own statute, and the class is what says so. The class
 * travels with its operational description and legal bases — the same line
 * ProceduralClassSelectionAgent reads — and may be missing, when the class
 * selection failed; the area never is.
 *
 * The answer is in parts, and the parts are composed into the text the lawyer
 * edits by InjunctiveReliefSuggestionData, never by the model. The labels are
 * what the drafting agent reads to write the section DA TUTELA DE URGÊNCIA, and
 * a model writing its own labels writes different ones each time.
 *
 * Three boundaries the instructions hold, each measured against the courts'
 * own reading of art. 300 (the research behind them is in
 * `app/Rag/knowledge/injunctive-relief.md`):
 *
 * 1. **Incidental only.** The pleading is always the full initial petition,
 *    so the antecedent rite of arts. 303 and 305 is never the answer.
 * 2. **No figure, no deadline.** A daily fine and the days to comply are the
 *    lawyer's to set, and become `[valor da multa diária]` and `[prazo]` — the
 *    bracketed gaps the drafting agent already knows to keep.
 * 3. **Statute only.** `legal_basis` names articles of law, never a súmula, a
 *    tema or a ruling: the text lands in the dossier the drafting agent is
 *    allowed to cite from, and a súmula number recalled from memory would
 *    arrive there looking verified.
 *
 * `Temperature(0.2)`, the choose-and-compose setting of the requirements agent:
 * whether is a judgement, the sentences are composition. `required()` with
 * `nullable()` on every part, the pair the defendant agent documents — the
 * grammar forces each key to exist and makes `null` the answer a declined
 * request gives. The one list has a single `max()`, at one level: stacked caps
 * on nested arrays are a 400 on Gemini.
 *
 * Variables go at the end, as in the class selection agent: the knowledge guide
 * is constant and long, and a variable above it would re-prefill it on every
 * call under Ollama. The traps the siblings document apply unchanged: never
 * send `think: false` to an Ollama that reasons — dormant while the attribute
 * below points at Gemini, live again on the swap back — and leave the model to
 * `config/ai.php` rather than naming one in a `#[Model]`.
 *
 * Reached through SuggestInjunctiveRelief, which ClassifyLegalCase calls as the
 * third link of the framing chain and the first step's "Consultar IA" calls on
 * its own.
 */
// Trocar as duas linhas de lugar traz a inferência de volta para a máquina — a
// troca mais um `config:clear` bastam, com o `gpt-oss:20b` baixado no daemon.
#[Provider('gemini')]
// #[Provider('ollama')]
#[Timeout(360)]
#[Temperature(0.2)]
final class InjunctiveReliefSuggestionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresOllamaRuntime;
    use Promptable;

    /**
     * Mais documentos do que um pedido de urgência se instrui — o mesmo teto
     * que InjunctiveReliefSuggestionData aplica ao envelope que volta da tela.
     */
    private const int MAX_EVIDENCE = 5;

    /**
     * @param  string  $areaLabel  the area already decided, in the lawyer's words
     * @param  string|null  $classLine  the class already decided, as "[code] name — description", or null when none was
     * @param  string  $knowledge  the markdown guide from app/Rag/knowledge
     */
    public function __construct(
        private readonly string $areaLabel,
        private readonly ?string $classLine,
        private readonly string $knowledge,
    ) {}

    public function instructions(): string
    {
        return <<<TXT
        Você é um assistente jurídico brasileiro especializado em tutela provisória. A área
        de atuação e a classe processual do caso já foram decididas e vêm declaradas ao fim
        destas instruções. Sua única tarefa é ler a descrição dos fatos e dizer se a petição
        inicial deve trazer pedido de **tutela de urgência** — e, se deve, qual medida pedir,
        de que espécie, com que fundamento, e por que ela preenche os requisitos.

        Você não classifica o caso, não escreve a peça, não escreve os demais pedidos e não
        opina sobre a chance de êxito do mérito. Você responde uma pergunta só: esperar a
        sentença já é o prejuízo?

        O relato chega em linguagem natural e varia muito: pode ser um parágrafo corrido
        escrito pelo próprio cliente, com erros e sem termos técnicos, ou um resumo já
        redigido por advogado. Trate os dois com o mesmo critério.

        # Base de conhecimento

        {$this->knowledge}

        # A decisão

        Recomende a tutela (`recommended: true`) só quando o relato mostrar **os dois
        requisitos ao mesmo tempo**: a probabilidade do direito, apoiada num fato que a prova
        documental consegue mostrar desde já, e um perigo concreto, atual ou iminente, que a
        demora agrava. Faltando qualquer um, `recommended` é `false`.

        **Não recomendar é resposta legítima e frequente.** A maioria dos relatos não tem
        urgência nenhuma: o dano já aconteceu e só se quer a indenização, a dívida espera a
        sentença, a questão depende de perícia ou de testemunha. Não force a urgência para
        ter o que responder.

        # A resposta, campo por campo

        - `recommended`: `true` ou `false`.
        - `kind`: `anticipatory` quando a medida entrega agora o efeito do pedido final;
          `precautionary` quando ela só assegura que o pedido final ainda possa ser cumprido.
          Nulo quando não recomendar.
        - `measure`: **o que se pede que o juiz determine desde já**, numa frase só, começando
          por "que se determine" — quem faz, o quê, e em que condição. Seja concreto: "que se
          determine à Ré a exclusão do nome do Autor dos cadastros de proteção ao crédito, no
          prazo de [prazo], sob pena de multa diária de [valor da multa diária]". Prazo e
          valor de multa **nunca** são seus: escreva `[prazo]` e `[valor da multa diária]`, a
          menos que o relato os dê. Acrescente "liminarmente, sem a oitiva da parte
          contrária" só quando o perigo não esperar nem a citação. Nulo quando não recomendar.
        - `legal_basis`: o dispositivo de lei que autoriza a medida, escrito como numa
          petição — "art. 300 do CPC", "art. 562 do CPC", "art. 4º da Lei 5.478/68". Quando a
          classe tem liminar própria, é o dela. **Só lei**: nunca súmula, tema, enunciado ou
          julgado. Nulo quando não recomendar.
        - `probability`: duas ou três frases sobre a probabilidade do direito, amarradas aos
          **fatos do relato** — o que aconteceu e o que já pode ser mostrado. Não invente
          documento que o relato não menciona. Nulo quando não recomendar.
        - `danger`: duas ou três frases sobre o perigo, dizendo **o que acontece se o juiz só
          decidir na sentença** — o fato concreto do relato que torna a espera o prejuízo.
          Nada de "dano irreparável ou de difícil reparação" sem dizer qual. Nulo quando não
          recomendar.
        - `reversibility`: só na espécie `anticipatory` — uma ou duas frases dizendo por que
          os efeitos podem ser desfeitos, ou por que o bem em jogo (vida, saúde, alimentos)
          autoriza a medida mesmo assim. Nulo na cautelar e quando não recomendar.
        - `evidence`: até cinco documentos que devem instruir o pedido, cada um numa frase
          curta ("Relatório médico com a indicação expressa de urgência"). São os que o
          juiz vai procurar; não afirme que o autor os tem. Lista vazia quando não
          recomendar.
        - `justification`: duas ou três frases para o advogado que vai conferir. Quando
          recomendar, diga o que no relato mostra a urgência. Quando não recomendar, diga
          **qual requisito falta** e por quê — e, se o direito parecer muito claro sem
          perigo nenhum, que o caso sugere a tutela de evidência.

        # Forma

        - Português do Brasil, registro de petição, terceira pessoa.
        - As partes são "o Autor" e "o Réu" ("a Autora", "a Ré" quando o relato descrever uma
          mulher ou uma empresa). **Nunca use nomes próprios.**
        - Não cite súmula, tema, enunciado ou julgado em campo nenhum, nem pelo número nem
          pelo nome.
        - Não escreva valor em dinheiro que o relato não escreva.

        # A área e a classe decididas

        A área de atuação deste caso é **{$this->areaLabel}**.

        {$this->classDeclaration()}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recommended' => $schema->boolean()
                ->description('Se a petição inicial deve trazer pedido de tutela de urgência.')
                ->required(),

            'kind' => $this->part($schema, 'A espécie: anticipatory (satisfativa) ou precautionary (cautelar).')
                ->enum([...array_column(InjunctiveReliefKind::cases(), 'value'), null]),

            'measure' => $this->part($schema, 'O que se pede que o juiz determine desde já, numa frase começando por "que se determine".'),
            'legal_basis' => $this->part($schema, 'O dispositivo de lei que autoriza a medida, como "art. 300 do CPC". Só lei.'),
            'probability' => $this->part($schema, 'Duas ou três frases sobre a probabilidade do direito, amarradas aos fatos do relato.'),
            'danger' => $this->part($schema, 'Duas ou três frases sobre o perigo concreto que a espera pela sentença causa.'),
            'reversibility' => $this->part($schema, 'Só na antecipada: por que os efeitos podem ser desfeitos.'),

            'evidence' => $schema->array()
                ->items($schema->string())
                ->max(self::MAX_EVIDENCE)
                ->description('Os documentos que devem instruir o pedido. Lista vazia quando não recomendar.')
                ->required(),

            'justification' => $schema->string()
                ->description(
                    'Duas ou três frases para o advogado: o que mostra a urgência ou qual '
                    .'requisito falta. Frases completas, não palavras soltas.'
                )
                ->required(),
        ];
    }

    private function part(JsonSchema $schema, string $description): StringType
    {
        return $schema->string()
            ->description($description.' Nulo quando não recomendar.')
            ->nullable()
            ->required();
    }

    private function classDeclaration(): string
    {
        if ($this->classLine === null) {
            return 'A classe processual não foi decidida. Responda pela área e pelo pedido que '
                .'o relato sugere, com o fundamento geral do art. 300 do CPC.';
        }

        return 'A classe processual é a seguinte — se ela tem liminar própria, é o fundamento '
            ."dela que vai em `legal_basis`:\n\n{$this->classLine}";
    }
}

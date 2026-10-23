<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use App\Ai\Concerns\UsesDefaultAiModel;
use App\Domain\LegalCases\Data\DoctrineResearchData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Turns the doctrine sheet into the shape a Doctrine is saved in.
 *
 * The second half of the pair, and CourtDecisionTranscriptionAgent for
 * scholarship: it copies what DoctrineResearchAgent wrote into the flat
 * structure and nothing else. It exists for the same reason — on Gemini a
 * schema switches the search off —, it runs on the default model like its twin,
 * and it has no tools for the same reason.
 *
 * One difference, and it is deliberate: `Relevância` **has** a field here
 * (`relevance`). The rulings' transcriber throws it away, because a ruling is
 * read for its ementa. A doctrine record usually has no ementa at all, so the
 * researcher's sentence on why the work's subject meets the case is the only
 * hint the lawyer gets — shown on the screen as the AI's note, never as the
 * author's words.
 *
 * Title and author are what matters most here, and the prompt says so: the
 * address the researcher writes is usually invented (GroundingRedirect has the
 * measurement), so a work is matched to the record the provider returned by
 * its title and author, and DoctrineRecordReader then replaces both with the
 * page's own. There is no `sources_consulted`: the provider's metadata says
 * what was opened, and the sheet's list did not.
 */
#[Timeout(360)]
#[Temperature(0.1)]
final class DoctrineTranscriptionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresOllamaRuntime;
    use Promptable;
    use UsesDefaultAiModel;

    public function instructions(): string
    {
        return <<<'TXT'
        Você recebe a ficha de uma pesquisa de doutrina jurídica já feita e a
        transcreve para a estrutura pedida. Sua única tarefa é copiar.

        Você não pesquisa, não confere, não corrige, não completa e não melhora.
        Não acrescenta uma obra que a ficha não traga. Não conserta um endereço
        incompleto. Não deduz o autor a partir do título.

        # Como transcrever

        1. Cada seção `## DOUTRINA n` da ficha vira um item de `doctrines`, na
           mesma ordem.
        2. Os rótulos viram os campos, um a um: `Tipo` vira `kind`, `Autor` vira
           `author`, `Título` vira `title`, `URL` vira `source_url` e
           `Relevância` vira `relevance`.
        3. `## TERMOS` vira `search_terms` e `## PENDENTE` vira `pending`, uma
           linha por item.

        # Título e autor são o que mais importa

        `title` e `author` são copiados inteiros, caractere por caractere: é por
        eles que a obra será reconhecida no catálogo. `source_url` também é
        copiado como está — não complete um endereço truncado e não monte um a
        partir do título. Se a ficha não traz URL, ou traz `nulo`, o campo é a
        string vazia.

        # O que vira nulo

        - A palavra `nulo` da ficha vira o nulo do JSON, e nunca a string
          "nulo", "", "não informado" ou "-".
        - A frase "Não localizado/confirmado em fonte oficial." **não é um
          valor**: onde ela aparecer, o campo é nulo. Ela nunca vira o título de
          uma obra.
        - Um rótulo ausente na ficha vira nulo. Não invente o valor que faltou.

        # Quando a ficha não traz obra

        Devolva `doctrines` como lista vazia e copie o que houver em
        `## TERMOS` e `## PENDENTE`. Uma ficha sem obra é uma pesquisa que não
        confirmou nada, e essa é uma resposta legítima que você não deve
        preencher.

        Se a ficha vier vazia, ilegível ou em outro formato, devolva `doctrines`
        vazia e diga isso em `pending`. Não tente responder a pesquisa você
        mesmo: você não pesquisou nada.
        TXT;
    }

    /**
     * The answer, flat: one object per work the sheet wrote.
     *
     * `required()` with `nullable()` throughout, the contract
     * CourtDecisionTranscriptionAgent::schema() explains. `title` and
     * `source_url` are the two that are not nullable: a work without a title
     * cannot be reported, one without a url cannot be read.
     *
     * Only `doctrines` carries a `max()` — on Gemini a second cap anywhere
     * returns 400 before a token is generated, measured on the sibling schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search_terms' => $schema->array()
                ->items($schema->string())
                ->description('Os termos da seção ## TERMOS, um por item.')
                ->required(),

            'doctrines' => $schema->array()
                ->items($schema->object([
                    'kind' => $this->field($schema, 'O rótulo Tipo: "Livro", "Artigo de revista".'),

                    'author' => $this->field($schema, 'O rótulo Autor, copiado como está.'),

                    'title' => $schema->string()
                        ->description('O rótulo Título da ficha, copiado inteiro.')
                        ->required(),

                    'source_url' => $schema->string()
                        ->description(
                            'O rótulo URL, copiado exatamente como a ficha o escreveu. '
                            .'String vazia se a ficha não traz ou traz nulo.'
                        )
                        ->required(),

                    'relevance' => $this->field($schema, 'O rótulo Relevância, copiado como está.'),
                ]))
                ->max(DoctrineResearchData::CANDIDATES)
                ->description(
                    'Uma entrada por seção ## DOUTRINA da ficha, na mesma ordem. Lista vazia '
                    .'quando a ficha não traz nenhuma.'
                )
                ->required(),

            'pending' => $schema->array()
                ->items($schema->string())
                ->description('As linhas da seção ## PENDENTE, uma por item.')
                ->required(),
        ];
    }

    /**
     * A field the grammar insists on and the sheet may leave empty.
     */
    private function field(JsonSchema $schema, string $description): StringType
    {
        return $schema->string()
            ->description($description.' Nulo se a ficha não trouxer.')
            ->nullable()
            ->required();
    }
}

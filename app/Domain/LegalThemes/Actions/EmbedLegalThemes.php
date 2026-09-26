<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Actions;

use App\Domain\LegalThemes\Models\LegalTheme;
use App\Domain\Shared\Support\DocumentEmbedder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Gera e grava o vetor de cada tema do STJ que ainda não o tem, ou cujo texto
 * mudou desde a última passada.
 *
 * É a base do RAG sobre os temas: a busca ainda não existe, e quando existir
 * vai comparar os fatos de uma peça com o texto abaixo, pelo
 * `DocumentEmbedder::query()` do outro lado do par.
 */
final class EmbedLegalThemes
{
    use AsAction;

    /**
     * @param  Collection<int, LegalTheme>  $themes
     * @return int quantos temas foram (re)embutidos
     */
    public function handle(Collection $themes): int
    {
        return (new DocumentEmbedder)->embed($themes, $this->documentFor(...));
    }

    /**
     * O documento que representa o tema no espaço vetorial.
     *
     * Só o que diz **do que o tema trata**: a questão, a tese, a delimitação
     * e os assuntos. Situação, datas e órgão julgador ficam de fora porque são
     * filtros — e porque, dentro do texto, a mudança de "Afetado" para
     * "Trânsito em Julgado" trocaria o hash e reembutiria um tema cujo
     * conteúdo não mudou. As anotações do NUGEPNAC e as informações
     * complementares também, porque são ruído processual ("REsps n. …
     * rejeição presumida"), e não o assunto.
     */
    public function documentFor(LegalTheme $theme): string
    {
        $parts = ["{$theme->type->label()} {$theme->number} do STJ."];

        if ($theme->subjects !== []) {
            $parts[] = 'Assuntos: '.implode('; ', array_column($theme->subjects, 'name')).'.';
        }

        $parts[] = "Questão submetida a julgamento: {$theme->question}";

        if ($theme->settled_thesis !== null) {
            $parts[] = "Tese firmada: {$theme->settled_thesis}";
        }

        if ($theme->judgment_scope !== null) {
            $parts[] = "Delimitação do julgado: {$theme->judgment_scope}";
        }

        return DocumentEmbedder::document(implode("\n", $parts));
    }
}

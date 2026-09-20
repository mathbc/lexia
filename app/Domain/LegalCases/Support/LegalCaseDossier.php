<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Support;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Models\Requirement;

/**
 * The pleading around a narrative, written out for a prompt to read.
 *
 * The sibling of LegalCaseFormProps, and the inverse of it: that one projects a
 * pleading for a screen to hydrate, this one projects it for a model to
 * understand. Hence markdown and prose labels instead of the column names — the
 * reader is a language model, and "Área de atuação: Direito do Consumidor" is
 * what it reads well.
 *
 * **The facts are deliberately not in here.** The narrative is what the agent
 * is prompted *with*; repeating it in the system prompt would spend the window
 * twice and invite the model to answer about the copy.
 *
 * What is in here is what changes the writing:
 *
 * - the area and the CNJ class, which say what kind of story this is;
 * - the client, because the narrative is theirs and the pleading calls them the
 *   Autor — person or company decides the article, and nothing else about them
 *   belongs in a narrative, since qualifying the parties is another part of the
 *   document's job;
 * - the defendant, so the story names the other side the way the pleading will;
 * - the requests already on file, which are the load the narrative has to bear.
 *   A fact that supports no request is padding, and a request the facts do not
 *   support is the hole worth seeing.
 *
 * Empty fields are dropped rather than sent as "null": an absent line reads as
 * absence, while a wall of nulls reads as noise and teaches a small model to
 * write "não informado" into prose. A section with nothing in it says so in
 * words, because "the defendant is not known yet" is itself context.
 */
final class LegalCaseDossier
{
    public static function of(LegalCase $legalCase): string
    {
        return implode(PHP_EOL.PHP_EOL, [
            self::section('A peça', self::pleading($legalCase)),
            self::section('O autor — o cliente que relatou os fatos', self::plaintiff($legalCase->customer)),
            self::section('O réu', self::defendant($legalCase)),
            self::section('Os pedidos já registrados', self::requirements($legalCase)),
        ]);
    }

    /**
     * The same pleading, minus everybody's name.
     *
     * What the thesis research agent gets, and the difference from `of()` is
     * the point rather than an economy: research is about the law, so the
     * client and the defendant are dropped whole. The framing says which body
     * of law to search and the requests say what has to be sustained, and
     * neither needs to know who the parties are.
     *
     * It matters more here than it would elsewhere, because this is the one
     * agent that runs on a cloud provider — see LegalThesisResearchAgent. The
     * narrative still travels, since a thesis cannot be researched without the
     * facts that raise it; the parties' qualification does not have to, so it
     * does not.
     */
    public static function forResearch(LegalCase $legalCase): string
    {
        return implode(PHP_EOL.PHP_EOL, [
            self::section('O enquadramento', self::pleading($legalCase)),
            self::section('Os pedidos já registrados', self::requirements($legalCase)),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function pleading(LegalCase $legalCase): array
    {
        return self::written([
            'Área de atuação' => $legalCase->practiceArea->label,
            'Classe processual' => self::filedAs($legalCase),
            'Endereçamento' => $legalCase->court_addressing,
            // Só aparece quando foi pedida: a ausência da linha diz que não há
            // urgência a sustentar, e é isso que o relato precisa saber.
            'Tutela de urgência' => $legalCase->injunctive_relief
                ? 'pedida — '.($legalCase->injunctive_relief_description ?? 'sem justificativa escrita ainda')
                : null,
        ]);
    }

    /**
     * "[1118] Embargos à Execução Fiscal", quando há uma classe.
     *
     * Lida pela relação e não pela propriedade tipada, de propósito. Numa peça
     * salva a classe nunca falta — a chave estrangeira é obrigatória, e é isso
     * que `LegalCase` documenta —, mas o dossiê também é montado sobre a peça
     * que ainda não existe: `ClassifyLegalCase` monta uma em memória para a
     * pesquisa de teses, e ali a escolha da classe é uma das etapas que podem
     * cair sozinhas. Sem ela a linha simplesmente some, como `written()` faz
     * com todo campo vazio, e o agente continua sabendo o ramo do direito —
     * que é o que decide onde procurar.
     */
    private static function filedAs(LegalCase $legalCase): ?string
    {
        $class = $legalCase->getRelationValue('proceduralClass');

        return $class instanceof ProceduralClass ? "[{$class->code}] {$class->name}" : null;
    }

    /**
     * @return list<string>
     */
    private static function plaintiff(Customer $customer): array
    {
        return self::written([
            'Nome' => $customer->displayName(),
            'Tipo' => $customer->type->label(),
            'Documento' => $customer->identifier(),
            'Cidade' => self::city($customer->city, $customer->state),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function defendant(LegalCase $legalCase): array
    {
        return self::written([
            'Nome' => $legalCase->defendant_name,
            'Documento' => $legalCase->defendant_document,
            'Cidade' => self::city($legalCase->defendant_city, $legalCase->defendant_state),
            'Observações' => $legalCase->defendant_notes,
        ]);
    }

    /**
     * @return list<string>
     */
    private static function requirements(LegalCase $legalCase): array
    {
        return $legalCase->requirements
            ->map(static fn (Requirement $requirement): string => '- '
                .$requirement->description
                .self::claimed($requirement->amount))
            ->all();
    }

    private static function claimed(?string $amount): string
    {
        return $amount === null ? '' : ' (valor pedido: '.self::money($amount).')';
    }

    /**
     * "8750.00" as a Brazilian reader writes it.
     *
     * Grouped by hand rather than through `number_format`, which would take the
     * value through a float — the one thing money in this project never does.
     */
    private static function money(string $amount): string
    {
        [$units, $cents] = array_pad(explode('.', $amount, 2), 2, '00');

        return 'R$ '.strrev(implode('.', str_split(strrev($units), 3))).','.$cents;
    }

    private static function city(?string $city, ?BrazilianState $state): ?string
    {
        if ($city === null) {
            return null;
        }

        return $state instanceof BrazilianState ? "{$city}/{$state->value}" : $city;
    }

    /**
     * @param  array<string, string|null>  $fields
     * @return list<string>
     */
    private static function written(array $fields): array
    {
        $lines = [];

        foreach ($fields as $label => $value) {
            $written = trim((string) $value);

            if ($written !== '') {
                $lines[] = "- {$label}: {$written}";
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function section(string $title, array $lines): string
    {
        $body = $lines === [] ? 'Nada registrado nesta peça até aqui.' : implode(PHP_EOL, $lines);

        return "## {$title}".PHP_EOL.PHP_EOL.$body;
    }
}

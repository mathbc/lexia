<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Support;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Customers\Models\Customer;
use App\Domain\Documents\Models\Document;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalTheses\Models\LegalThesis;
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
     * The whole pleading, for the agent that writes the document.
     *
     * The third projection, and the widest — where `forResearch()` narrows
     * because research is about the law, this one widens because the document
     * *qualifies the parties*. The narrative alone cannot open a petição
     * inicial: it has to name the Autor with their full address, name the Réu
     * the same way, argue the theses the forensic review settled on, and close
     * with the requests, numbered.
     *
     * Three differences from `of()`, each of them load-bearing:
     *
     * 1. **The parties are written in full.** `of()` sends the client's city
     *    because a narrative only needs to know how to name them; a pleading
     *    opens by qualifying them, so the seven address columns go.
     * 2. **The theses go, and the precedents do not.** That is a decision of
     *    the product and not an oversight: the drafting of the jurisprudence
     *    section is a later piece of work, and an agent given rulings would
     *    write a section nobody asked for. The absence is what the prompt's
     *    negative instruction rests on.
     * 3. **The documents go, when there are any.** Today the relation is always
     *    empty — nothing persists an upload yet — and the section drops itself,
     *    because `section()` writes "Nada registrado" and `written()` discards
     *    empty lines. The day they persist, "DOS DOCUMENTOS QUE INSTRUEM A
     *    PEÇA" starts being written without this class or the agent changing.
     *
     * The facts stay out, as in both siblings: they are the prompt.
     */
    public static function forDrafting(LegalCase $legalCase): string
    {
        return implode(PHP_EOL.PHP_EOL, [
            self::section('A peça', self::pleading($legalCase)),
            self::section('O autor — quem propõe a ação', self::plaintiffInFull($legalCase->customer)),
            self::section('O réu — contra quem a ação é proposta', self::defendantInFull($legalCase)),
            self::section('Os pedidos', self::requirements($legalCase)),
            self::section('As teses da revisão forense', self::theses($legalCase)),
            self::section('Os documentos que instruem a peça', self::documents($legalCase)),
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
     * The client as the opening paragraph has to qualify them.
     *
     * Note what is **not** here and cannot be: marital status and occupation.
     * A petição inicial states both, and `customers` has columns for neither —
     * so they are the gap the agent is instructed to mark rather than guess.
     * Saying so is the point of this comment: the absence is the schema's, not
     * an omission of this method.
     *
     * @return list<string>
     */
    private static function plaintiffInFull(Customer $customer): array
    {
        return self::written([
            'Nome' => $customer->displayName(),
            'Tipo' => $customer->type->label(),
            'Documento' => self::documentAs($customer->identifier()),
            'Endereço' => self::address(
                $customer->street,
                $customer->number,
                $customer->complement,
                $customer->district,
                $customer->city,
                $customer->state,
                $customer->postal_code,
            ),
        ]);
    }

    /**
     * The defendant, qualified as far as anyone knows them.
     *
     * Every field is nullable here and none is there — a defendant is described,
     * not registered — so most of these lines are routinely absent, and that
     * absence is itself the instruction: what the dossier does not say, the
     * document marks as a gap.
     *
     * @return list<string>
     */
    private static function defendantInFull(LegalCase $legalCase): array
    {
        return self::written([
            'Nome' => $legalCase->defendant_name,
            'Documento' => self::documentAs($legalCase->defendant_document),
            'Endereço' => self::address(
                $legalCase->defendant_street,
                $legalCase->defendant_number,
                $legalCase->defendant_complement,
                $legalCase->defendant_district,
                $legalCase->defendant_city,
                $legalCase->defendant_state,
                $legalCase->defendant_postal_code,
            ),
            'Observações' => $legalCase->defendant_notes,
        ]);
    }

    /**
     * "Rua das Flores, 120, apto 3, Centro, Itajaí/SC, CEP 88301-000".
     *
     * Assembled from the parts that exist and skipping the ones that do not,
     * which is `written()`'s rule applied inside a line instead of between
     * lines. An address with nothing in it returns null and its label vanishes.
     */
    private static function address(
        ?string $street,
        ?string $number,
        ?string $complement,
        ?string $district,
        ?string $city,
        ?BrazilianState $state,
        ?string $postalCode,
    ): ?string {
        $parts = array_filter([
            $street,
            $number,
            $complement,
            $district,
            self::city($city, $state),
            $postalCode === null ? null : 'CEP '.self::postalCode($postalCode),
        ], static fn (?string $part): bool => trim((string) $part) !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * "11586325922" as a petição writes it: "115.863.259-22".
     *
     * Only for the drafting projection, and the reason is what a document is
     * for. Everywhere else the identifier is context a model reads to tell two
     * parties apart, and the digits do that perfectly well; here it is copied
     * verbatim into a paragraph a judge reads, and "CPF nº 11586325922" is
     * wrong in a way that is nobody's fault but this method's.
     *
     * The length decides which mask, the same reading `defendantDocumentType()`
     * makes. Anything else goes through untouched — a half-masked document is
     * worse than an unmasked one.
     */
    private static function documentAs(?string $digits): ?string
    {
        return match (strlen((string) $digits)) {
            11 => vsprintf('%s.%s.%s-%s', str_split((string) $digits, 3)),
            14 => vsprintf('%s.%s.%s/%s-%s', [
                substr((string) $digits, 0, 2),
                substr((string) $digits, 2, 3),
                substr((string) $digits, 5, 3),
                substr((string) $digits, 8, 4),
                substr((string) $digits, 12, 2),
            ]),
            default => $digits,
        };
    }

    /**
     * "88301000" as the Correios write it.
     *
     * Eight digits is the only shape the column accepts, but a row written
     * before that rule, or by hand, might not have them — and a half-formatted
     * CEP reads worse than an unformatted one, so anything else goes through
     * untouched.
     */
    private static function postalCode(string $digits): string
    {
        return strlen($digits) === 8
            ? substr($digits, 0, 5).'-'.substr($digits, 5)
            : $digits;
    }

    /**
     * What the pleading argues, one block per thesis.
     *
     * The fundamentação comes through `citedLegalBases()`, which is the chip as
     * the screen reads it — "Súmula 393 do STJ", already assembled. The agent
     * cites these and nothing else, which is what keeps a document with no
     * jurisprudence section from growing one out of habit.
     *
     * @return list<string>
     */
    private static function theses(LegalCase $legalCase): array
    {
        return $legalCase->theses
            ->map(static fn (LegalThesis $thesis): string => implode(PHP_EOL, self::written([
                'Tese' => $thesis->name,
                'Espécie' => $thesis->type?->label(),
                'O que se argumenta' => $thesis->description,
                'O que a tese garante' => $thesis->impact,
                'Fundamentos a citar' => implode('; ', $thesis->citedLegalBases()) ?: null,
            ])))
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function documents(LegalCase $legalCase): array
    {
        return $legalCase->documents
            ->map(static fn (Document $document): string => '- '
                .$document->name
                .($document->description === null ? '' : ' — '.$document->description))
            ->all();
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

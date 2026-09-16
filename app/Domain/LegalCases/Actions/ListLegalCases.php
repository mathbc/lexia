<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Queries\LegalCaseIndexQuery;
use App\Domain\LegalCases\Support\LegalCaseOptions;
use App\Domain\LegalCases\Support\LegalCasePageProps;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The pleadings of the actor's own account.
 */
final class ListLegalCases
{
    use AsAction;

    public function __construct(private readonly LegalCaseIndexQuery $query) {}

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('viewAny', LegalCase::class);
    }

    public function asController(ActionRequest $request): Response
    {
        $actor = $request->user();
        $filters = $request->only(['search', 'customer', 'practice_area', 'status', 'sort', 'direction']);

        return Inertia::render('legal-cases/index', [
            'legalCases' => $this->query->paginate($actor->account_id, $filters),
            // Cast to object so an empty set serialises as {} and not [],
            // which would break `filters.search` on the other side.
            'filters' => (object) $filters,
            'customers' => LegalCaseOptions::customers($actor->account_id),
            'practiceAreas' => LegalCaseOptions::practiceAreas(),
            'statuses' => LegalCaseOptions::statuses(),
            'can' => LegalCasePageProps::abilities($actor),
        ]);
    }
}

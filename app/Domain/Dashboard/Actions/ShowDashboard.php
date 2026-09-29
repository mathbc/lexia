<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Dashboard\Queries\DashboardIndicatorsQuery;
use App\Domain\Dashboard\Queries\LegalCaseActivityQuery;
use App\Domain\Dashboard\Support\DashboardOptions;
use App\Domain\Dashboard\Support\DashboardScope;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The panel: indicator cards and the monthly chart of pleadings.
 *
 * Everyone signed in lands here, so there is nothing to authorise beyond the
 * route's own middleware. What differs by role is what is counted, and that is
 * decided once, by the AccountPolicy: whoever may list the accounts is LexIA
 * staff, sees the platform card and chooses the account; everyone else is held
 * to their own by DashboardScope.
 */
final class ShowDashboard
{
    use AsAction;

    public function __construct(
        private readonly DashboardIndicatorsQuery $indicators,
        private readonly LegalCaseActivityQuery $activity,
    ) {}

    public function authorize(): bool
    {
        return true;
    }

    public function asController(ActionRequest $request): Response
    {
        $actor = $request->user();
        $staff = $actor->can('viewAny', Account::class);
        $filters = $request->only(['account', 'year', 'user']);

        $scope = $staff
            ? DashboardScope::acrossAccounts($filters, $this->activity)
            : DashboardScope::withinAccount($actor->account_id, $filters, $this->activity);

        return Inertia::render('dashboard', [
            'indicators' => $this->indicators->forAccount($scope->accountId),
            // Null rather than hidden: the numbers never leave the server for
            // someone who may not see them.
            'platform' => $staff ? $this->indicators->platform() : null,
            'activity' => [
                'year' => $scope->year,
                'months' => $scope->year === null
                    ? []
                    : $this->activity->monthly($scope->accountId, $scope->year, $scope->userId),
            ],
            'years' => $scope->years,
            'accounts' => $staff ? DashboardOptions::accounts() : [],
            'users' => DashboardOptions::users($scope->accountId),
            // Cast to object so an empty set serialises as {} and not [].
            'filters' => (object) $scope->filters(),
            'can' => ['view_accounts' => $staff],
        ]);
    }
}

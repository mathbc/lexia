<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Queries\CustomerIndexQuery;
use App\Domain\Customers\Support\CustomerPageProps;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The clients registry of the actor's own account.
 */
final class ListCustomers
{
    use AsAction;

    public function __construct(private readonly CustomerIndexQuery $query) {}

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('viewAny', Customer::class);
    }

    public function asController(ActionRequest $request): Response
    {
        $actor = $request->user();
        $filters = $request->only(['search', 'type', 'sort', 'direction']);

        return Inertia::render('customers/index', [
            'customers' => $this->query->paginate($actor->account_id, $filters),
            'filters' => (object) $filters,
            'customerTypes' => CustomerType::options(),
            'can' => [
                ...CustomerPageProps::abilities($actor),
                'create' => $actor->can('create', Customer::class),
            ],
        ]);
    }
}

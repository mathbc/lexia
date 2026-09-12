<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Models\User;
use App\Domain\Users\Queries\UserIndexQuery;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

final class ListUsers
{
    use AsAction;

    public function __construct(private readonly UserIndexQuery $query) {}

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('viewAny', User::class);
    }

    public function asController(ActionRequest $request): Response
    {
        $filters = $request->only(['search', 'role', 'type', 'enabled', 'sort', 'direction']);

        return Inertia::render('users/index', [
            'users' => $this->query->paginate($filters),
            'filters' => (object) $filters,
            'assignableRoles' => UserRole::options(),
            'types' => UserType::options(),
        ]);
    }
}

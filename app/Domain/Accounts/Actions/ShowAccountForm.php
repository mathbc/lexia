<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Enums\UserType;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The "Nova conta" form.
 *
 * The platform type is not on offer here any more than it is at sign-up: the
 * single account that carries it is created by migration.
 */
final class ShowAccountForm
{
    use AsAction;

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', Account::class);
    }

    public function asController(): Response
    {
        return Inertia::render('accounts/create', [
            'accountTypes' => AccountType::customerOptions(),
            'states' => BrazilianState::options(),
            'userTypes' => UserType::options(),
        ]);
    }
}

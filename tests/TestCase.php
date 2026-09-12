<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Accounts\Models\Account;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sign in and pin the tenant, the way BindTenantContext does per request.
     *
     * Tests that exercise HTTP go through the middleware for real; this helper
     * is for the ones that call Actions or query models directly.
     */
    protected function actingAsUser(User $user): User
    {
        $this->actingAs($user);

        app(TenantContext::class)->set($user->account_id);

        return $user;
    }

    /**
     * @return array{0: Account, 1: User}
     */
    protected function accountWithOwner(): array
    {
        $account = Account::factory()->create();
        $owner = User::factory()->forAccount($account)->accountAdmin()->create();

        return [$account, $owner];
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }
}

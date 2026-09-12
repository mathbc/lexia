<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Users\Enums\UserRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserRoleTest extends TestCase
{
    #[Test]
    public function the_hierarchy_is_strict(): void
    {
        $this->assertTrue(UserRole::AccountAdmin->outranks(UserRole::Admin));
        $this->assertTrue(UserRole::AccountAdmin->outranks(UserRole::Lawyer));
        $this->assertTrue(UserRole::Admin->outranks(UserRole::Lawyer));

        $this->assertFalse(UserRole::Admin->outranks(UserRole::AccountAdmin));
        $this->assertFalse(UserRole::Lawyer->outranks(UserRole::Admin));
    }

    #[Test]
    public function no_role_outranks_itself(): void
    {
        // This is what stops two peers from managing each other.
        foreach (UserRole::cases() as $role) {
            $this->assertFalse($role->outranks($role), "{$role->value} outranks itself");
        }
    }

    #[Test]
    public function only_admin_roles_manage(): void
    {
        $this->assertTrue(UserRole::AccountAdmin->manages());
        $this->assertTrue(UserRole::Admin->manages());
        $this->assertFalse(UserRole::Lawyer->manages());
    }

    #[Test]
    public function every_case_has_a_portuguese_label_and_option(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->assertNotSame('', $role->label());
        }

        $this->assertSame(
            [
                ['value' => 'account_admin', 'label' => 'Admin da Conta'],
                ['value' => 'admin', 'label' => 'Admin'],
                ['value' => 'lawyer', 'label' => 'Advogado'],
            ],
            UserRole::options(),
        );
    }
}

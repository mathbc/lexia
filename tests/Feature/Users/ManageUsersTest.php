<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Domain\Users\Notifications\UserInvitation;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ManageUsersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_admin_can_invite_a_lawyer(): void
    {
        Notification::fake();
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)->post("/contas/{$owner->account_id}/usuarios", [
            'name' => 'Novo Advogado',
            'email' => 'novo@lexia.test',
            'type' => 'lawyer',
            'role' => UserRole::Lawyer->value,
            'oab_number' => '123456',
            'oab_state' => 'SP',
            'birth_date' => '1990-01-01',
        ])->assertRedirect("/contas/{$owner->account_id}/usuarios");

        $invited = User::acrossAllAccounts()->where('email', 'novo@lexia.test')->sole();
        $this->assertSame($owner->account_id, $invited->account_id);
        $this->assertSame(UserRole::Lawyer, $invited->role);
        $this->assertTrue($invited->enabled);

        // Exactly one e-mail: the invitation, never the framework's
        // "Verify Email Address" on top of it.
        Notification::assertSentTo($invited, UserInvitation::class);
        Notification::assertNotSentTo($invited, VerifyEmail::class);
    }

    #[Test]
    public function accepting_the_invitation_verifies_the_address(): void
    {
        [$account] = $this->accountWithOwner();
        $invited = User::factory()->forAccount($account)->unverified()->create();

        $this->post('/reset-password', [
            'token' => Password::broker()->createToken($invited),
            'email' => $invited->email,
            'password' => 'senha-bem-secreta',
            'password_confirmation' => 'senha-bem-secreta',
        ])->assertSessionHasNoErrors();

        // Without this the invited user would be stuck on /email/verify: no
        // verification e-mail was ever sent to them.
        $this->assertTrue($invited->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function the_invitation_email_carries_a_working_reset_link(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $invited = User::factory()->forAccount($account)->create();

        $message = (new UserInvitation(Password::broker()->createToken($invited)))
            ->toMail($invited);

        $mail = view($message->view, $message->viewData)->render();

        // The reset screen needs both halves: without the e-mail in the query
        // string the form has nothing to submit the new password against.
        $this->assertStringContainsString('/reset-password/', $mail);
        $this->assertStringContainsString(urlencode($invited->email), $mail);
        $this->assertStringContainsString($account->displayName(), $mail);
        $this->assertStringContainsString('Definir minha senha', $mail);
    }

    #[Test]
    public function an_admin_cannot_mint_a_peer_admin(): void
    {
        [$account] = $this->accountWithOwner();
        $admin = User::factory()->forAccount($account)->admin()->create();

        $this->actingAs($admin)->post("/contas/{$account->id}/usuarios", [
            'name' => 'Outro Admin',
            'email' => 'outro@lexia.test',
            'type' => 'lawyer',
            'role' => UserRole::Admin->value,
        ])->assertSessionHasErrors('role');

        $this->assertSame(0, User::acrossAllAccounts()->where('email', 'outro@lexia.test')->count());
    }

    #[Test]
    public function a_lawyer_cannot_reach_the_listing(): void
    {
        [$account] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();

        $this->actingAs($lawyer)->get("/contas/{$account->id}/usuarios")->assertForbidden();
        $this->actingAs($lawyer)->get("/contas/{$account->id}/usuarios/novo")->assertForbidden();
    }

    #[Test]
    public function a_lawyer_cannot_promote_themselves(): void
    {
        [$account] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();

        $this->actingAs($lawyer)->put("/contas/{$account->id}/usuarios/{$lawyer->id}", [
            'name' => 'Eu Mesmo',
            'email' => $lawyer->email,
            'type' => 'lawyer',
            'role' => UserRole::AccountAdmin->value,
        ])->assertRedirect();

        // The name change is allowed; the smuggled role is ignored.
        $lawyer->refresh();
        $this->assertSame('Eu Mesmo', $lawyer->name);
        $this->assertSame(UserRole::Lawyer, $lawyer->role);
    }

    #[Test]
    public function account_admins_cannot_disable_each_other(): void
    {
        // Peers never manage peers, which is what stops two owners from
        // locking one another out of the account.
        [$account, $owner] = $this->accountWithOwner();
        $second = User::factory()->forAccount($account)->accountAdmin()->create();

        $this->actingAs($owner)->patch("/contas/{$account->id}/usuarios/{$second->id}/status")->assertForbidden();

        $this->assertTrue($second->fresh()->enabled);
    }

    #[Test]
    public function the_last_account_admin_cannot_be_disabled(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $second = User::factory()->forAccount($account)->accountAdmin()->create();
        $staff = User::factory()->platformAdmin()->create();

        // Platform staff outrank everyone, so the second admin goes down.
        $this->actingAs($staff)->patch("/contas/{$account->id}/usuarios/{$second->id}/status")->assertRedirect();
        $this->assertFalse($second->fresh()->enabled);

        // The last enabled admin is protected even from platform staff:
        // an account with none can never be administered again.
        $this->actingAs($staff)
            ->patch("/contas/{$account->id}/usuarios/{$owner->id}/status")
            ->assertSessionHasErrors('enabled');

        $this->assertTrue($owner->fresh()->enabled);
    }

    #[Test]
    public function the_listing_can_be_searched_and_filtered(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        User::factory()->forAccount($account)->create(['name' => 'Joana Pereira']);
        User::factory()->forAccount($account)->disabled()->create(['name' => 'Inativo Silva']);

        $this->actingAs($owner)->get("/contas/{$account->id}/usuarios?search=Joana")
            ->assertInertia(fn ($page) => $page->where('users.total', 1));

        // Regression guard: `enabled=0` must mean disabled, not enabled.
        $this->actingAs($owner)->get("/contas/{$account->id}/usuarios?enabled=0")
            ->assertInertia(fn ($page) => $page
                ->where('users.total', 1)
                ->where('users.data.0.name', 'Inativo Silva'));
    }
}

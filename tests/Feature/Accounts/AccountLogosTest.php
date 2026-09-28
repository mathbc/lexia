<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Domain\Accounts\Enums\AccountLogo;
use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The account's two logos: stored as files in the account's own folder on the
 * private disk, and served back through the AccountPolicy.
 */
final class AccountLogosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Account::FILES_DISK);
    }

    #[Test]
    public function staff_create_an_account_with_both_logos_in_its_own_folder(): void
    {
        Notification::fake();
        $staff = User::factory()->platformAdmin()->create();

        $this->actingAs($staff)
            ->post('/contas', [
                ...$this->newAccountPayload(),
                'logo' => UploadedFile::fake()->image('marca.png', 600, 200),
                'logo_dark' => UploadedFile::fake()->image('marca-branca.jpg', 600, 200),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $account = Account::query()->where('email', 'contato@novabanca.adv.br')->sole();

        foreach (AccountLogo::cases() as $logo) {
            $path = $account->logoPath($logo);

            $this->assertNotNull($path);
            $this->assertStringStartsWith("accounts/{$account->id}/logo-{$logo->value}-", $path);
            Storage::disk(Account::FILES_DISK)->assertExists($path);
        }

        // The extension is read from the content, never from the name sent.
        $this->assertStringEndsWith('.jpg', (string) $account->logoPath(AccountLogo::Dark));
    }

    #[Test]
    public function a_new_logo_replaces_the_old_file_and_leaves_the_other_alone(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        $this->update($owner, $account, [
            'logo' => UploadedFile::fake()->image('antiga.png'),
            'logo_dark' => UploadedFile::fake()->image('escura.png'),
        ]);
        $account->refresh();
        $old = (string) $account->logoPath(AccountLogo::Light);
        $dark = (string) $account->logoPath(AccountLogo::Dark);

        $this->update($owner, $account, ['logo' => UploadedFile::fake()->image('nova.png')]);
        $account->refresh();

        $disk = Storage::disk(Account::FILES_DISK);
        $this->assertNotSame($old, $account->logoPath(AccountLogo::Light));
        $disk->assertExists((string) $account->logoPath(AccountLogo::Light));
        $disk->assertMissing($old);

        $this->assertSame($dark, $account->logoPath(AccountLogo::Dark));
        $disk->assertExists($dark);
    }

    #[Test]
    public function saving_the_form_without_a_file_keeps_the_stored_logo(): void
    {
        // The edit form re-posts every field on every save; only the removal
        // flag may take a logo away.
        [$account, $owner] = $this->accountWithOwner();
        $this->update($owner, $account, ['logo' => UploadedFile::fake()->image('marca.png')]);
        $path = (string) $account->refresh()->logoPath(AccountLogo::Light);

        $this->update($owner, $account, ['logo' => null, 'remove_logo' => '0']);

        $this->assertSame($path, $account->refresh()->logoPath(AccountLogo::Light));
        Storage::disk(Account::FILES_DISK)->assertExists($path);
    }

    #[Test]
    public function the_removal_flag_deletes_the_file_and_clears_the_column(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->update($owner, $account, ['logo_dark' => UploadedFile::fake()->image('escura.png')]);
        $path = (string) $account->refresh()->logoPath(AccountLogo::Dark);

        $this->update($owner, $account, ['remove_logo_dark' => '1']);

        $this->assertNull($account->refresh()->logoPath(AccountLogo::Dark));
        Storage::disk(Account::FILES_DISK)->assertMissing($path);
    }

    #[Test]
    public function only_png_and_jpeg_within_the_limits_are_accepted(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        $svg = UploadedFile::fake()->createWithContent(
            'marca.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->update($owner, $account, ['logo' => $svg])->assertSessionHasErrors('logo');
        $this->update($owner, $account, ['logo' => UploadedFile::fake()->image('marca.webp')])
            ->assertSessionHasErrors('logo');
        $this->update($owner, $account, ['logo' => UploadedFile::fake()->image('marca.png')->size(3 * 1024)])
            ->assertSessionHasErrors('logo');
        $this->update($owner, $account, ['logo_dark' => UploadedFile::fake()->image('marca.png', 4200, 100)])
            ->assertSessionHasErrors('logo_dark');

        $this->assertNull($account->refresh()->logoPath(AccountLogo::Light));
        $this->assertSame([], Storage::disk(Account::FILES_DISK)->allFiles());
    }

    #[Test]
    public function the_page_gets_urls_and_never_the_paths(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->update($owner, $account, ['logo' => UploadedFile::fake()->image('marca.png')]);
        $account->refresh();

        $this->actingAs($owner)
            ->get("/contas/{$account->id}")
            ->assertInertia(fn ($page) => $page
                ->component('accounts/show')
                ->missing('account.logo_path')
                ->missing('account.logo_dark_path')
                ->where('logos.light', $account->logoUrl(AccountLogo::Light))
                ->where('logos.dark', null));

        $this->assertStringStartsWith("/contas/{$account->id}/logo?tema=claro&v=", (string) $account->logoUrl(AccountLogo::Light));
    }

    #[Test]
    public function every_page_shares_the_logos_for_the_sidebar(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();

        $this->actingAs($lawyer)->get('/painel')
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.account.logos.light', null)
                ->where('auth.user.account.logos.dark', null));

        $this->update($owner, $account, ['logo' => UploadedFile::fake()->image('marca.png')]);
        $account->refresh();

        // fresh(): the instance above kept the account it loaded on the first
        // request, where a real request would load the user anew.
        $this->actingAs($lawyer->fresh())->get('/painel')
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.account.logos.light', $account->logoUrl(AccountLogo::Light))
                ->where('auth.user.account.logos.dark', null));
    }

    #[Test]
    public function anyone_in_the_account_sees_the_logo_and_nobody_outside_it(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $lawyer = User::factory()->forAccount($account)->create();
        [, $stranger] = $this->accountWithOwner();

        $this->update($owner, $account, ['logo_dark' => UploadedFile::fake()->image('escura.png')]);
        $url = (string) $account->refresh()->logoUrl(AccountLogo::Dark);

        $response = $this->actingAs($lawyer)->get($url)->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));

        // Without the version the request asks for "whatever is current".
        $this->assertStringContainsString(
            'no-cache',
            (string) $this->actingAs($lawyer)->get("/contas/{$account->id}/logo?tema=escuro")
                ->headers->get('Cache-Control'),
        );

        $this->actingAs($stranger)->get($url)->assertForbidden();
        $this->actingAs($lawyer)->get("/contas/{$account->id}/logo?tema=claro")->assertNotFound();
        $this->actingAs($lawyer)->get("/contas/{$account->id}/logo?tema=roxo")->assertNotFound();
    }

    /**
     * Posted the way the edit screen posts it: POST with `_method=PUT`, since
     * PHP parses a multipart body only on POST.
     *
     * @param  array<string, mixed>  $logos
     * @return TestResponse<Response>
     */
    private function update(User $actor, Account $account, array $logos): TestResponse
    {
        return $this->actingAs($actor)->post("/contas/{$account->id}", [
            '_method' => 'PUT',
            'name' => $account->name,
            'type' => $account->type->value,
            'oab_number' => $account->oab_number,
            'oab_state' => $account->oab_state?->value,
            'email' => $account->email,
            'phone' => $account->phone,
            'postal_code' => $account->postal_code,
            'street' => $account->street,
            'number' => $account->number,
            'district' => $account->district,
            'city' => $account->city,
            'state' => $account->state->value,
            ...$logos,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function newAccountPayload(): array
    {
        return [
            'name' => 'Nova Banca',
            'legal_name' => 'Nova Banca Sociedade de Advogados Ltda.',
            'type' => AccountType::LawFirm->value,
            'federal_id' => '11222333000181',
            'email' => 'contato@novabanca.adv.br',
            'phone' => '11999998888',
            'postal_code' => '01310100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
            'owner_name' => 'Dona da Banca',
            'owner_email' => 'dono@novabanca.adv.br',
            'owner_type' => 'lawyer',
        ];
    }
}

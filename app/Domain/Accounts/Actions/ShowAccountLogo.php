<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\AccountLogo;
use App\Domain\Accounts\Models\Account;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an account's logo from the private disk.
 *
 * The image goes through here, rather than a public URL, for the same reason
 * every other read does: the account is bound before the tenant middleware,
 * and the Policy is what refuses another tenant's (403, not 404). `view` and
 * not `update`: anyone in the account may see its logo, the lawyer included.
 *
 * `?tema=escuro` picks the dark variant; without it, the light one. There is
 * no fallback from one to the other here: a missing variant is a 404, and the
 * page decides what to draw instead.
 */
final class ShowAccountLogo
{
    use AsAction;

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('view', $request->route('account'));
    }

    public function asController(Account $account, ActionRequest $request): StreamedResponse
    {
        $logo = AccountLogo::fromSlug($request->string('tema', AccountLogo::Light->slug())->toString())
            ?? abort(404);

        $path = $account->logoPath($logo) ?? abort(404);
        $disk = Storage::disk(Account::FILES_DISK);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, headers: [
            'Cache-Control' => $this->cacheControl($request, $path),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The URL logoUrl() builds names one file forever, so it may be cached
     * for good. A request without that version — typed by hand, or one left
     * over from before an upload — is asking for whatever is current, and
     * must check back.
     */
    private function cacheControl(ActionRequest $request, string $path): string
    {
        return $request->query('v') === Account::logoVersion($path)
            ? 'private, max-age=31536000, immutable'
            : 'private, no-cache';
    }
}

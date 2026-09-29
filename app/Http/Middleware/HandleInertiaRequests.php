<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Accounts\Enums\AccountLogo;
use App\Domain\Users\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props available to every page.
     *
     * Only the error rides here, as an alert that stays until the next visit. A
     * success is a toast, and goes through `Inertia::flash('success', …)`
     * instead: Inertia's flash never enters the history state, so going back
     * does not replay it, and the same message twice in a row still fires twice
     * — a prop would do neither.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn (): ?array => $this->currentUser($request),
            ],
            'flash' => [
                'error' => fn (): ?string => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * A deliberately small projection of the user.
     *
     * Serialising the model wholesale would ship password hashes' siblings and
     * every future column to the browser; this sends only what the UI renders,
     * with the enum labels resolved server-side so React never duplicates the
     * Portuguese copy.
     *
     * @return array<string, mixed>|null
     */
    private function currentUser(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'type' => $user->type->value,
            'type_label' => $user->type->label(),
            'is_platform_admin' => $user->isPlatformAdmin(),
            'account' => [
                'id' => $user->account->id,
                'name' => $user->account->displayName(),
                'type' => $user->account->type->value,
                'type_label' => $user->account->type->label(),
                'is_operational' => $user->account->isOperational(),
                // The sidebar draws the logo in place of the LexIA mark, so it
                // is needed on every page — as URLs, never as the image itself.
                // Only the interface's two: the pleading's is drawn by the
                // Minuta tab alone, which gets it from PleadingLetterhead.
                'logos' => [
                    'light' => $user->account->logoUrl(AccountLogo::Light),
                    'dark' => $user->account->logoUrl(AccountLogo::Dark),
                ],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
                'success' => fn (): ?string => $request->session()->get('success'),
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
            ],
        ];
    }
}

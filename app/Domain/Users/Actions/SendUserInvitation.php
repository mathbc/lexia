<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Users\Models\User;
use App\Domain\Users\Notifications\UserInvitation;
use Illuminate\Support\Facades\Password;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Invites a user who has no password yet.
 *
 * The token is minted here instead of through `Password::sendResetLink()` so
 * the e-mail can say "convite" rather than "redefinição": the reset flow is
 * reused, the wording is not.
 */
final class SendUserInvitation
{
    use AsAction;

    public function handle(User $user): void
    {
        $user->notify(new UserInvitation(
            Password::broker()->createToken($user),
        ));
    }
}

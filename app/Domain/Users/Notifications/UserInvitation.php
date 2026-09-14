<?php

declare(strict_types=1);

namespace App\Domain\Users\Notifications;

use App\Domain\Users\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * The e-mail that opens the door for a user LexIA staff or an account admin
 * created.
 *
 * It rides on the framework's password reset token rather than a bespoke
 * invitation table: the link lands on the same `password.reset` screen, so
 * there is a single way to choose a first password and a single expiry rule.
 * Only the wording is ours — nobody "forgot" a password they never had.
 */
final class UserInvitation extends Notification
{
    public function __construct(private readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $account = $notifiable->account;

        return (new MailMessage)
            ->subject('Seu acesso à LexIA')
            ->view('emails.user-invitation', [
                'user' => $notifiable,
                'accountName' => $account->displayName(),
                'roleLabel' => $notifiable->role->label(),
                'url' => $this->resetUrl($notifiable),
                'expiresInMinutes' => $this->expiresInMinutes(),
            ]);
    }

    /**
     * The e-mail travels in the query string because the reset screen needs it
     * to submit the new password, and the token alone does not carry it.
     */
    private function resetUrl(User $user): string
    {
        return route('password.reset', ['token' => $this->token]).'?'.http_build_query([
            'email' => $user->email,
        ]);
    }

    private function expiresInMinutes(): int
    {
        $broker = config('auth.defaults.passwords');

        return (int) config("auth.passwords.{$broker}.expire", 60);
    }
}

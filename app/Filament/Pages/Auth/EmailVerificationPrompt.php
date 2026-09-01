<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;

/**
 * @property-read Action $resendNotificationAction
 * @property-read Action $signOutAction
 */
final class EmailVerificationPrompt extends \Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt
{
    private const int RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Seconds left before another verification email may be sent. Kept on the
     * server so the countdown survives a reload instead of restarting at zero.
     */
    #[Locked]
    public int $resendCooldownSeconds = 0;

    public function getMaxWidth(): Width
    {
        return Width::Medium;
    }

    public function hasLogo(): bool
    {
        return false;
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function dehydrate(): void
    {
        $this->resendCooldownSeconds = $this->getResendCooldownSeconds();
    }

    public function getResendCooldownSeconds(): int
    {
        $key = $this->resendCooldownKey();

        return $key === null ? 0 : RateLimiter::availableIn($key);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Html::make(fn (): string => view('filament.auth.email-verification-prompt', [
                    'email' => $this->getVerifiable()->getEmailForVerification(),
                    'resendAction' => $this->resendNotificationAction,
                    'signOutAction' => $this->signOutAction,
                    'cooldownSeconds' => $this->getResendCooldownSeconds(),
                ])->render()),
            ]);
    }

    public function resendNotificationAction(): Action
    {
        return parent::resendNotificationAction()
            ->label(__('auth.verify_email.resend'))
            ->color('gray')
            ->button()
            ->size(Size::Medium)
            ->extraAttributes(['class' => 'w-full justify-center']);
    }

    public function signOutAction(): Action
    {
        return Action::make('signOut')
            ->label(__('auth.verify_email.sign_out'))
            ->color('gray')
            ->link()
            ->size(Size::Small)
            ->url(Filament::getLogoutUrl())
            ->postToUrl();
    }

    protected function sendEmailVerificationNotification(MustVerifyEmail $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        parent::sendEmailVerificationNotification($user);

        $key = $this->resendCooldownKey();

        if ($key === null) {
            return;
        }

        RateLimiter::hit($key, self::RESEND_COOLDOWN_SECONDS);
    }

    private function resendCooldownKey(): ?string
    {
        $id = Filament::auth()->id();

        return $id === null ? null : 'verification-resend:'.$id;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Concerns\DetectsTeamInvitation;
use App\Models\User;
use App\Rules\RegistrableEmail;
use App\Rules\TurnstileChallenge;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class Register extends BaseRegister
{
    use DetectsTeamInvitation;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Html::make(fn (): string => $this->getInvitationContentHtml()),
                RenderHook::make(PanelsRenderHook::AUTH_REGISTER_FORM_BEFORE),
                $this->getFormContentComponent(),
                RenderHook::make(PanelsRenderHook::AUTH_REGISTER_FORM_AFTER),
            ]);
    }

    protected function getEmailFormComponent(): TextInput
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/register.form.email.label'))
            ->email()
            ->rules(RegistrableEmail::rules())
            ->required()
            ->maxLength(255)
            ->unique($this->getUserModel());
    }

    protected function getTurnstileFormComponent(): ViewField
    {
        return ViewField::make('cf_turnstile_response')
            ->hiddenLabel()
            ->view('filament.forms.components.turnstile')
            ->dehydrated(false)
            ->required()
            ->validationMessages(['required' => __('auth.turnstile.required')])
            ->rules([new TurnstileChallenge])
            ->visible(TurnstileChallenge::isConfigured(...));
    }

    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        return $schema->components([
            ...$schema->getComponents(withHidden: true),
            $this->getTurnstileFormComponent(),
        ]);
    }

    public function register(): ?RegistrationResponse
    {
        try {
            return parent::register();
        } catch (ValidationException $exception) {
            $this->resetTurnstileChallenge();

            throw $exception;
        }
    }

    public function getRegisterFormAction(): Action
    {
        return Action::make('register')
            ->size(Size::Medium)
            ->label(__('filament-panels::auth/pages/register.form.actions.register.label'))
            ->submit('register');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        /** @var User $user */
        $user = $this->getUserModel()::query()->create($data);

        $invitation = $this->getTeamInvitationFromSession();

        if ($invitation && $invitation->email === $data['email'] && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        session()->put('fathom.track_signup', true);

        return $user;
    }

    /**
     * Cloudflare turnstile tokens are single-use, and Laravel validates every
     * field on every submit — so the token is already spent by the time any
     * other field (duplicate email, password mismatch, disposable domain)
     * reports its error. Blanking the state fires the widget's Livewire
     * watcher, which resets the challenge and issues a fresh token; without it
     * the next submit replays the spent one and fails with
     * "timeout-or-duplicate" beside a widget showing a green tick.
     */
    private function resetTurnstileChallenge(): void
    {
        if (! is_array($this->data)) {
            return;
        }

        if (! array_key_exists('cf_turnstile_response', $this->data)) {
            return;
        }

        $this->data['cf_turnstile_response'] = null;
    }
}

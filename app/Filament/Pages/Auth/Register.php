<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Concerns\DetectsTeamInvitation;
use App\Models\User;
use App\Rules\RegistrableEmail;
use Filament\Actions\Action;
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
use RyanChandler\LaravelCloudflareTurnstile\Rules\Turnstile as TurnstileRule;

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
            ->rules([new TurnstileRule])
            ->visible(fn (): bool => filled(config('services.turnstile.key')) && filled(config('services.turnstile.secret')));
    }

    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        return $schema->components([
            ...$schema->getComponents(withHidden: true),
            $this->getTurnstileFormComponent(),
        ]);
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
}

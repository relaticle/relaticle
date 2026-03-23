<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Concerns\DetectsTeamInvitation;
use Filament\Actions\Action;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Model;

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
            ->rules(['email:rfc,dns'])
            ->required()
            ->maxLength(255)
            ->unique($this->getUserModel());
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
        $user = $this->getUserModel()::query()->create($data);

        $invitation = $this->getTeamInvitationFromSession();

        if ($invitation && $invitation->email === $data['email']) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }
}

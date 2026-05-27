<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Relaticle\EmailIntegration\Actions\UpdateTeamEmailPrivacySettingsAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;

final class EmailPrivacySettingsPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'email-integration::filament.pages.email-privacy-settings';

    protected static ?string $slug = 'settings/email-privacy';

    protected static ?string $title = 'Privacy';

    protected static ?int $navigationSort = 11;

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): string
    {
        return __('filament/pages/email-privacy-settings.navigation.group');
    }

    public string $default_email_sharing_tier = 'metadata_only';

    /** @var array<int, string> */
    public array $protected_emails = [];

    /** @var array<int, string> */
    public array $protected_domains = [];

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        $this->default_email_sharing_tier = ($team->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY)->value;

        $rows = ProtectedRecipient::query()->where('team_id', $team->getKey())->get();

        $this->protected_emails = $rows->where('type', 'email')->pluck('value')->values()->all();
        $this->protected_domains = $rows->where('type', 'domain')->pluck('value')->values()->all();
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label(__('filament/pages/email-privacy-settings.actions.save.label'))
            ->action(function (): void {
                /** @var User $user */
                $user = auth()->user();

                resolve(UpdateTeamEmailPrivacySettingsAction::class)->execute(
                    $user->currentTeam,
                    $user,
                    EmailPrivacyTier::from($this->default_email_sharing_tier),
                    $this->protected_emails,
                    $this->protected_domains,
                );

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-privacy-settings.notifications.saved.title'))
                    ->send();
            });
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Workspace Default Sharing Tier')
                ->description(__('filament/pages/email-privacy-settings.workspace_default.description'))
                ->schema([
                    Select::make('default_email_sharing_tier')
                        ->label(__('filament/pages/email-privacy-settings.workspace_default.fields.default_email_sharing_tier.label'))
                        ->options(EmailPrivacyTier::class)
                        ->required(),
                ])->compact(),

            Section::make('Auto-hide Internal Emails')
                ->description(__('filament/pages/email-privacy-settings.auto_hide_internal.description'))
                ->compact()
                ->schema([
                    Placeholder::make('internal_emails_info')
                        ->hiddenLabel()
                        ->content(__('filament/pages/email-privacy-settings.auto_hide_internal.fields.internal_emails_info.content')),
                ]),

            Section::make('Protected Recipients')
                ->compact()
                ->description(__('filament/pages/email-privacy-settings.protected_recipients.description'))
                ->schema([
                    TagsInput::make('protected_emails')
                        ->label(__('filament/pages/email-privacy-settings.protected_recipients.fields.protected_emails.label'))
                        ->placeholder(__('filament/pages/email-privacy-settings.protected_recipients.fields.protected_emails.placeholder'))
                        ->afterLabel('Press Enter(⏎) to add each address.'),
                    TagsInput::make('protected_domains')
                        ->label(__('filament/pages/email-privacy-settings.protected_recipients.fields.protected_domains.label'))
                        ->placeholder(__('filament/pages/email-privacy-settings.protected_recipients.fields.protected_domains.placeholder'))
                        ->afterLabel('All emails from these domains will be protected.'),
                ]),
        ]);
    }
}

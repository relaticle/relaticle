<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Enums\TeamRole;
use App\Features\EmailIntegration;
use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Actions\UpdateTeamEmailPrivacySettingsAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;

final class EmailPrivacySettingsPage extends Page implements HasSchemas
{
    use HasWorkspaceSettingsNavigation;
    use InteractsWithSchemas;

    /**
     * Workspace-wide privacy settings may only be viewed and changed by the team
     * owner or an admin. Mirrors the write guard in
     * {@see UpdateTeamEmailPrivacySettingsAction}; other roles use the
     * per-user "My Email Privacy" page instead.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        if (! Feature::active(EmailIntegration::class) || ! parent::canAccess()) {
            return false;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $team = $user->currentTeam;

        return $team instanceof Team
            && ($user->ownsTeam($team) || $user->hasTeamRole($team, TeamRole::Admin->value));
    }

    protected string $view = 'email-integration::filament.pages.workspace-email-settings';

    protected static ?string $slug = 'team/email';

    protected static ?string $title = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getTitle(): string
    {
        return __('teams.tabs.email');
    }

    public static function getLabel(): string
    {
        return __('teams.tabs.email');
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
            ->label(__('filament/pages/email-privacy-settings.actions.save'))
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
                    ->title(__('filament/pages/email-privacy-settings.notifications.saved'))
                    ->send();
            });
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Grid::make(2)->schema([
                Section::make(__('filament/pages/email-privacy-settings.workspace_default.heading'))
                    ->description(__('filament/pages/email-privacy-settings.workspace_default.description'))
                    ->schema([
                        ViewField::make('default_email_sharing_tier')
                            ->label(__('filament/pages/email-privacy-settings.workspace_default.tier_label'))
                            ->view('email-integration::forms.sharing-tier-cards')
                            ->viewData([
                                'ariaLabel' => __('filament/pages/email-privacy-settings.workspace_default.tier_label'),
                            ]),
                    ])->compact(),

                Section::make(__('filament/pages/email-privacy-settings.privacy_protections.heading'))
                    ->description(__('filament/pages/email-privacy-settings.privacy_protections.description'))
                    ->compact()
                    ->schema([
                        TagsInput::make('protected_emails')
                            ->label(__('filament/pages/email-privacy-settings.protected_recipients.emails_label'))
                            ->placeholder(__('filament/pages/email-privacy-settings.protected_recipients.emails_placeholder'))
                            ->afterLabel(__('filament/pages/email-privacy-settings.protected_recipients.emails_after_label')),
                        TagsInput::make('protected_domains')
                            ->label(__('filament/pages/email-privacy-settings.protected_recipients.domains_label'))
                            ->placeholder(__('filament/pages/email-privacy-settings.protected_recipients.domains_placeholder'))
                            ->afterLabel(__('filament/pages/email-privacy-settings.protected_recipients.domains_after_label')),
                    ]),
            ]),
        ]);
    }
}

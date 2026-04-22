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
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;

final class EmailPrivacySettingsPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'email-integration::filament.pages.email-privacy-settings';

    protected static ?string $slug = 'settings/email-privacy';

    protected static ?string $title = 'Privacy';

    protected static ?int $navigationSort = 11;

    protected static string|\UnitEnum|null $navigationGroup = 'Emails';

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
            ->label('Save')
            ->action(function (): void {
                /** @var User $user */
                $user = auth()->user();
                $team = $user->currentTeam;

                $team->update([
                    'default_email_sharing_tier' => $this->default_email_sharing_tier,
                ]);

                ProtectedRecipient::query()->where('team_id', $team->getKey())->delete();

                foreach ($this->protected_emails as $email) {
                    if (filled($email)) {
                        ProtectedRecipient::query()->create([
                            'team_id' => $team->getKey(),
                            'type' => 'email',
                            'value' => strtolower(trim($email)),
                            'created_by' => $user->getKey(),
                        ]);
                    }
                }

                foreach ($this->protected_domains as $domain) {
                    if (filled($domain)) {
                        ProtectedRecipient::query()->create([
                            'team_id' => $team->getKey(),
                            'type' => 'domain',
                            'value' => strtolower(trim($domain)),
                            'created_by' => $user->getKey(),
                        ]);
                    }
                }

                Notification::make()
                    ->success()
                    ->title('Privacy settings saved.')
                    ->send();
            });
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Workspace Default Sharing Tier')
                ->description('Applied to all newly synced emails unless a team member sets their own preference.')
                ->schema([
                    Select::make('default_email_sharing_tier')
                        ->label('Default Sharing Tier for Connected Email Accounts')
                        ->options(EmailPrivacyTier::class)
                        ->required(),
                ])->compact(),

            Section::make('Auto-hide Internal Emails')
                ->description('Internal emails are automatically hidden from teammates\' views.')
                ->compact()
                ->schema([
                    Placeholder::make('internal_emails_info')
                        ->label('')
                        ->content('Emails where every participant is a member of this workspace are classified as internal and are automatically hidden from all teammates. Only the syncing user can see them. This behaviour is always on and cannot be disabled.'),
                ]),

            Section::make('Protected Recipients')
                ->compact()
                ->description('Emails involving these addresses or domains are hidden from all teammates workspace-wide. Only the syncing user can see them.')
                ->schema([
                    TagsInput::make('protected_emails')
                        ->label('Email addresses')
                        ->placeholder('e.g. legal@acme.com')
                        ->afterLabel('Press Enter(⏎) to add each address.'),
                    TagsInput::make('protected_domains')
                        ->label('Domains')
                        ->placeholder('e.g. acme.com')
                        ->afterLabel('All emails from these domains will be protected.'),
                ]),
        ]);
    }
}

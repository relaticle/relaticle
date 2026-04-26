<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Jetstream\CreateTeam as CreateTeamAction;
use App\Actions\Jetstream\InviteTeamMember;
use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Enums\TeamRole;
use App\Filament\Resources\CompanyResource;
use App\Models\Team;
use App\Models\User;
use App\Rules\ValidTeamSlug;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Override;

final class CreateTeam extends RegisterTenant
{
    protected string $view = 'filament.pages.create-team';

    protected array $extraBodyAttributes = [
        'class' => 'fi-onboarding-wizard',
    ];

    public function getMaxContentWidth(): Width
    {
        return Width::FiveExtraLarge;
    }

    #[Override]
    public static function getLabel(): string
    {
        return 'Create Team';
    }

    #[Override]
    public function getHeading(): string
    {
        return '';
    }

    #[Override]
    public function getSubheading(): ?string
    {
        return null;
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    $this->getWorkspaceStep(),
                    $this->getAttributionStep(),
                    $this->getUseCaseStep(),
                    $this->getInviteStep(),
                ])
                    ->view('components.onboarding.wizard')
                    ->hiddenHeader()
                    ->contained(false)
                    ->nextAction(
                        fn (Action $action): Action => $action
                            ->label('Continue')
                            ->size(Size::Large)
                            ->extraAttributes(['class' => 'w-full'])
                    )
                    ->submitAction(
                        Action::make('register')
                            ->label('Send invites')
                            ->size(Size::Large)
                            ->submit('register')
                            ->extraAttributes(['class' => 'w-full'])
                    ),
            ]);
    }

    private function getWorkspaceStep(): Step
    {
        return Step::make('Workspace')
            ->schema([
                Placeholder::make('workspace_heading')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<h3 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">Create your workspace</h3>'
                    ))
                    ->dehydrated(false),
                ...$this->getWorkspaceFormComponents(),
            ]);
    }

    private function getAttributionStep(): Step
    {
        return Step::make('Attribution')
            ->schema([
                Placeholder::make('attribution_heading')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<h3 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">How did you hear about us?</h3>'
                        .'<p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Please select below where you found out about Relaticle. This step is optional.</p>'
                    ))
                    ->dehydrated(false),

                ToggleButtons::make('onboarding_referral_source')
                    ->hiddenLabel()
                    ->options(
                        collect(OnboardingReferralSource::cases())
                            ->mapWithKeys(fn (OnboardingReferralSource $source): array => [
                                $source->value => $source->getLabel(),
                            ])
                            ->all()
                    )
                    ->icons(
                        collect(OnboardingReferralSource::cases())
                            ->mapWithKeys(fn (OnboardingReferralSource $source): array => [
                                $source->value => $source->getIcon(),
                            ])
                            ->all()
                    )
                    ->inline(),
            ]);
    }

    private function getUseCaseStep(): Step
    {
        return Step::make('Use case')
            ->key('onboarding-use-case')
            ->schema([
                Placeholder::make('use_case_heading')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<h3 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">Help us customize your workspace</h3>'
                        .'<p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Relaticle is all about empowering you to build the exact CRM you need, no matter how complex.</p>'
                        .'<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Tell us about your use case to get started with templates, or start with a blank canvas.</p>'
                    ))
                    ->dehydrated(false),

                ToggleButtons::make('onboarding_use_case')
                    ->label('What will you be using Relaticle for?')
                    ->required()
                    ->options(
                        collect(OnboardingUseCase::cases())
                            ->mapWithKeys(fn (OnboardingUseCase $case): array => [
                                $case->value => $case->getLabel(),
                            ])
                            ->all()
                    )
                    ->icons(
                        collect(OnboardingUseCase::cases())
                            ->mapWithKeys(fn (OnboardingUseCase $case): array => [
                                $case->value => $case->getIcon(),
                            ])
                            ->all()
                    )
                    ->inline()
                    ->live(),

                ToggleButtons::make('onboarding_context')
                    ->label('Please tell us more about your use case.')
                    ->required()
                    ->options(function (Get $get): array {
                        $useCase = OnboardingUseCase::tryFrom($get('onboarding_use_case') ?? '');

                        if (! $useCase) {
                            return [];
                        }

                        return $useCase->getSubOptions();
                    })
                    ->inline()
                    ->multiple()
                    ->visible(function (Get $get): bool {
                        $useCase = OnboardingUseCase::tryFrom($get('onboarding_use_case') ?? '');

                        return $useCase !== null && $useCase->getSubOptions() !== [];
                    }),
            ]);
    }

    private function getInviteStep(): Step
    {
        return Step::make('Invite')
            ->schema([
                Placeholder::make('invite_heading')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<h3 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">Collaborate with your team</h3>'
                        .'<p class="mt-1 text-sm text-gray-500 dark:text-gray-400">The more your teammates use Relaticle, the more powerful it becomes.</p>'
                    ))
                    ->dehydrated(false),

                Placeholder::make('invite_subheading')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<p class="text-sm font-medium text-gray-700 dark:text-gray-300">Invite your team to collaborate</p>'
                    ))
                    ->dehydrated(false),

                Repeater::make('invites')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Email'),
                        TableColumn::make('Role')
                            ->width('140px'),
                    ])
                    ->schema([
                        TextInput::make('email')
                            ->email()
                            ->placeholder('colleague@company.com'),

                        Select::make('role')
                            ->options([
                                TeamRole::Editor->value => 'Member',
                                TeamRole::Admin->value => 'Admin',
                            ])
                            ->default(TeamRole::Editor->value)
                            ->selectablePlaceholder(false),
                    ])
                    ->defaultItems(2)
                    ->maxItems(5)
                    ->reorderable(false)
                    ->compact()
                    ->addActionLabel('Add more'),

                Actions::make([
                    Action::make('copyInviteLink')
                        ->label('Copy invite link')
                        ->icon(Heroicon::OutlinedLink)
                        ->color('gray')
                        ->link()
                        ->action(function (): void {
                            if (! $this->tenant instanceof Model) {
                                try {
                                    $data = $this->form->getState();
                                } catch (ValidationException) {
                                    Notification::make()
                                        ->title('Complete the previous steps first')
                                        ->body('Fill in your workspace details and use case before generating an invite link.')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                /** @var User $user */
                                $user = auth('web')->user();

                                $this->tenant = resolve(CreateTeamAction::class)->create($user, $data);
                            }

                            /** @var Team $team */
                            $team = $this->tenant;

                            $url = route('teams.join', [
                                'token' => $team->invite_link_token,
                            ]);

                            $this->js('navigator.clipboard.writeText('.json_encode($url, JSON_THROW_ON_ERROR).')');

                            Notification::make()
                                ->title('Invite link copied')
                                ->body('Share this link with your teammates. Anyone with the link can join this team.')
                                ->success()
                                ->send();
                        }),
                ])->alignment(Alignment::End),
            ]);
    }

    /**
     * @return array<Component>
     */
    private function getWorkspaceFormComponents(): array
    {
        $appHost = parse_url(url()->getAppUrl(), PHP_URL_HOST);

        return [
            TextInput::make('name')
                ->label('Company name')
                ->required()
                ->maxLength(255)
                ->placeholder('Acme Corp')
                ->autofocus()
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    if ($get('slug_auto_generated') === true || blank($get('slug'))) {
                        $set('slug', Str::slug($state ?? ''));
                        $set('slug_auto_generated', true);
                    }
                }),

            TextInput::make('slug')
                ->label('Workspace handle')
                ->required()
                ->maxLength(255)
                ->rules([new ValidTeamSlug])
                ->unique(
                    table: Team::class,
                    column: 'slug',
                    ignorable: fn (): ?Team => $this->tenant instanceof Team ? $this->tenant : null,
                )
                ->prefix("{$appHost}/")
                ->helperText('Only lowercase letters, numbers, and hyphens are allowed.')
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set): void {
                    $set('slug_auto_generated', false);
                }),

            Hidden::make('slug_auto_generated')
                ->default(true)
                ->dehydrated(false),
        ];
    }

    protected function afterRegister(): void
    {
        /** @var Team $tenant */
        $tenant = $this->tenant;

        Notification::make()
            ->title('Workspace created')
            ->body("Your workspace \"{$tenant->name}\" is ready to go.")
            ->success()
            ->send();
    }

    #[Override]
    protected function getRedirectUrl(): string
    {
        return CompanyResource::getUrl('index', ['tenant' => $this->tenant]);
    }

    #[Override]
    protected function handleRegistration(array $data): Model
    {
        /** @var User $user */
        $user = auth('web')->user();

        // The tenant may already be set if the user clicked "Copy invite link" earlier
        // in the wizard, which pre-creates the team so the invite URL can exist.
        // Reconcile name/slug here so later edits don't silently disappear — a regression
        // the UI currently blocks via ->hiddenHeader(), but kept as defense-in-depth.
        if ($this->tenant instanceof Team) {
            $team = $this->tenant;

            $updates = array_filter(
                [
                    'name' => $data['name'] ?? null,
                    'slug' => $data['slug'] ?? null,
                ],
                fn (?string $value, string $key): bool => $value !== null && $team->{$key} !== $value,
                ARRAY_FILTER_USE_BOTH,
            );

            if ($updates !== []) {
                $team->update($updates);
            }
        } else {
            /** @var Team $team */
            $team = resolve(CreateTeamAction::class)->create($user, $data);
        }

        $this->sendOnboardingInvites($user, $team, $data);

        return $team;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sendOnboardingInvites(User $user, Team $team, array $data): void
    {
        /** @var array<int, array{email: string|null, role: string|null}> $rawInvites */
        $rawInvites = $data['invites'] ?? [];

        $invites = array_filter(
            $rawInvites,
            fn (array $invite): bool => filled($invite['email'] ?? null)
                && filter_var($invite['email'], FILTER_VALIDATE_EMAIL) !== false,
        );

        /** @var list<array{email: string, reason: string}> $failed */
        $failed = [];

        foreach ($invites as $invite) {
            try {
                resolve(InviteTeamMember::class)->invite(
                    $user,
                    $team,
                    $invite['email'],
                    $invite['role'] ?? TeamRole::Editor->value,
                );
            } catch (ValidationException $exception) {
                $firstError = collect($exception->errors())->flatten()->first();

                $failed[] = [
                    'email' => (string) $invite['email'],
                    'reason' => is_string($firstError) ? $firstError : 'Validation failed',
                ];
            }
        }

        if ($failed !== []) {
            $body = collect($failed)
                ->map(fn (array $failure): string => "{$failure['email']}: {$failure['reason']}")
                ->implode("\n");

            Notification::make()
                ->title('Some invites could not be sent')
                ->body($body)
                ->warning()
                ->send();
        }
    }

    /**
     * @return array<Action|ActionGroup>
     */
    #[Override]
    protected function getFormActions(): array
    {
        return [];
    }

    #[Override]
    public function getRegisterFormAction(): Action
    {
        return Action::make('register')
            ->size(Size::Large)
            ->label('Get started')
            ->submit('register')
            ->extraAttributes(['class' => 'w-full']);
    }

    /**
     * @return array<string, string>
     */
    public function getUseCaseLabelsForPreview(): array
    {
        return collect(OnboardingUseCase::cases())
            ->mapWithKeys(fn (OnboardingUseCase $case): array => [
                $case->value => $case->getLabel(),
            ])
            ->all();
    }
}

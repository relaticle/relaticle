<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Jetstream\CreateTeam as CreateTeamAction;
use App\Actions\Jetstream\InviteTeamMember;
use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Rules\ValidTeamSlug;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
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
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class CreateTeam extends RegisterTenant
{
    /**
     * Marks a wizard run whose workspace already exists because the user copied the
     * invite link. Scoped to that run: mount() clears it whenever the wizard restarts.
     */
    private const string COMPLETING_SESSION_KEY = 'onboarding.completing_workspace';

    protected string $view = 'filament.pages.create-team';

    protected array $extraBodyAttributes = [
        'class' => 'fi-onboarding-wizard',
    ];

    public function getMaxContentWidth(): Width
    {
        return Width::FiveExtraLarge;
    }

    #[Override]
    public function mount(): void
    {
        // A pre-created workspace belongs to the wizard run that made it, so a fresh
        // visit always starts from the real cap.
        session()->forget(self::COMPLETING_SESSION_KEY);

        // Filament answers an over-cap visit with a bare 404, which reads as a broken
        // link rather than a limit the user can act on.
        if (! self::canView()) {
            Notification::make()
                ->title(__('filament/pages/teams.create_team.notifications.workspace_limit_reached.title'))
                ->body(__('filament/pages/teams.create_team.notifications.workspace_limit_reached.body'))
                ->warning()
                ->send();

            $this->redirect($this->getCancelUrl() ?? Filament::getUrl());

            return;
        }

        parent::mount();
    }

    /**
     * Filament re-checks this on every hydrate() and again inside register(). Once
     * "Copy invite link" has created the workspace, the user sits at the cap *because
     * of this wizard*, so without this exemption the page 404s on its own next request
     * and Livewire has nowhere to surface it: the form simply stops responding.
     */
    #[Override]
    public static function canView(): bool
    {
        if (session()->has(self::COMPLETING_SESSION_KEY)) {
            return true;
        }

        return parent::canView();
    }

    #[Override]
    public static function getLabel(): string
    {
        return __('filament/pages/teams.create_team.label');
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

    /**
     * Where "Cancel" returns to when the user backs out of creating another
     * workspace. Null during first-run onboarding — a user with no workspace
     * has nowhere to go back to, so no cancel affordance is offered.
     */
    public function getCancelUrl(): ?string
    {
        // "Copy invite link" pre-creates the workspace, so from that point there is
        // nothing left to cancel. Point at the workspace that now exists rather than
        // at whichever tenant happens to be the default.
        if ($this->tenant instanceof Team) {
            return Dashboard::getUrl(['tenant' => $this->tenant]);
        }

        /** @var User $user */
        $user = auth('web')->user();

        $tenant = Filament::getUserDefaultTenant($user);

        return $tenant instanceof Team
            ? Dashboard::getUrl(['tenant' => $tenant])
            : null;
    }

    /**
     * Once the workspace has been pre-created, "Cancel" would be a lie: the link
     * leads into a workspace that already exists and cannot be discarded here.
     */
    public function getCancelLabel(): string
    {
        return $this->tenant instanceof Team
            ? __('filament/pages/teams.create_team.actions.go_to_workspace')
            : __('filament/pages/teams.create_team.actions.cancel');
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
                            ->label(__('filament/pages/teams.create_team.actions.continue'))
                            ->size(Size::Large)
                            ->extraAttributes(['class' => 'w-full'])
                    )
                    ->submitAction(
                        Action::make('register')
                            ->label(fn (): string => $this->hasPendingInvites()
                                ? __('filament/pages/teams.create_team.actions.send_invites')
                                : __('filament/pages/teams.create_team.actions.get_started'))
                            ->size(Size::Large)
                            ->submit('register')
                            ->extraAttributes(['class' => 'w-full'])
                    ),
            ]);
    }

    private function getWorkspaceStep(): Step
    {
        return Step::make(__('filament/pages/teams.create_team.steps.workspace'))
            ->schema([
                Placeholder::make('workspace_heading')
                    ->hiddenLabel()
                    ->content($this->stepHeading(__('filament/pages/teams.create_team.headings.workspace')))
                    ->dehydrated(false),
                ...$this->getWorkspaceFormComponents(),
            ]);
    }

    private function getAttributionStep(): Step
    {
        return Step::make(__('filament/pages/teams.create_team.steps.attribution'))
            ->schema([
                Placeholder::make('attribution_heading')
                    ->hiddenLabel()
                    ->content($this->stepHeading(
                        __('filament/pages/teams.create_team.headings.attribution'),
                        __('filament/pages/teams.create_team.headings.attribution_description'),
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
        return Step::make(__('filament/pages/teams.create_team.steps.use_case'))
            ->key('onboarding-use-case')
            ->schema([
                Placeholder::make('use_case_heading')
                    ->hiddenLabel()
                    ->content($this->stepHeading(
                        __('filament/pages/teams.create_team.headings.use_case'),
                        __('filament/pages/teams.create_team.headings.use_case_description'),
                        __('filament/pages/teams.create_team.headings.use_case_hint'),
                    ))
                    ->dehydrated(false),

                ToggleButtons::make('onboarding_use_case')
                    ->label(__('filament/pages/teams.create_team.form.use_case_label'))
                    ->validationAttribute(__('filament/pages/teams.create_team.form.use_case_validation_attribute'))
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
                    ->label(__('filament/pages/teams.create_team.form.use_case_context_label'))
                    ->validationAttribute(__('filament/pages/teams.create_team.form.use_case_context_validation_attribute'))
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
        return Step::make(__('filament/pages/teams.create_team.steps.invite'))
            ->schema([
                Placeholder::make('invite_heading')
                    ->hiddenLabel()
                    ->content($this->stepHeading(
                        __('filament/pages/teams.create_team.headings.invite'),
                        __('filament/pages/teams.create_team.headings.invite_description'),
                    ))
                    ->dehydrated(false),

                Placeholder::make('invite_subheading')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<p class="text-sm font-medium text-gray-700 dark:text-gray-300">'
                        .e(__('filament/pages/teams.create_team.headings.invite_subheading'))
                        .'</p>'
                    ))
                    ->dehydrated(false),

                Repeater::make('invites')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make(__('filament/pages/teams.create_team.form.invite_table_column_email')),
                        TableColumn::make(__('filament/pages/teams.create_team.form.invite_table_column_role'))
                            ->width('140px'),
                    ])
                    ->schema([
                        TextInput::make('email')
                            ->email()
                            ->placeholder(__('filament/pages/teams.create_team.form.invite_email_placeholder'))
                            // Drives the submit label below: "Send invites" only once
                            // there is actually something to send.
                            ->live(onBlur: true),

                        Select::make('role')
                            ->options([
                                TeamRole::Editor->value => __('filament/pages/teams.create_team.form.invite_role_member'),
                                TeamRole::Admin->value => __('filament/pages/teams.create_team.form.invite_role_admin'),
                            ])
                            ->default(TeamRole::Editor->value)
                            ->selectablePlaceholder(false),
                    ])
                    ->defaultItems(2)
                    ->maxItems(5)
                    ->reorderable(false)
                    ->compact()
                    // Removing an empty row is not a destructive act; full danger red
                    // gave two blank rows more visual weight than the primary CTA.
                    ->deleteAction(fn (Action $action): Action => $action->color('gray'))
                    ->addActionLabel(__('filament/pages/teams.create_team.actions.add_more')),

                Actions::make([
                    Action::make('copyInviteLink')
                        ->label(__('filament/pages/teams.create_team.actions.copy_invite_link'))
                        ->icon(Heroicon::OutlinedLink)
                        ->color('gray')
                        ->link()
                        ->action(function (): void {
                            if (! $this->tenant instanceof Model) {
                                try {
                                    $data = $this->form->getState();
                                } catch (ValidationException) {
                                    Notification::make()
                                        ->title(__('filament/pages/teams.create_team.notifications.complete_previous_steps.title'))
                                        ->body(__('filament/pages/teams.create_team.notifications.complete_previous_steps.body'))
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                /** @var User $user */
                                $user = auth('web')->user();

                                $this->tenant = resolve(CreateTeamAction::class)->create($user, $data);

                                // The workspace now counts against the user's cap. Keep
                                // the wizard usable so they can finish the run.
                                session()->put(self::COMPLETING_SESSION_KEY, $this->tenant->getKey());
                            }

                            /** @var Team $team */
                            $team = $this->tenant;

                            $url = route('teams.join', [
                                'token' => $team->invite_link_token,
                            ]);

                            $this->js('navigator.clipboard.writeText('.json_encode($url, JSON_THROW_ON_ERROR).')');

                            Notification::make()
                                ->title(__('filament/pages/teams.create_team.notifications.invite_link_copied.title'))
                                ->body(__('filament/pages/teams.create_team.notifications.invite_link_copied.body'))
                                ->success()
                                ->send();
                        }),
                ])->alignment(Alignment::End),
            ]);
    }

    /**
     * Mirrors the fallback in Team::getSlugOptions(). Names that transliterate to
     * nothing (CJK, Hebrew, Thai, emoji) otherwise leave the handle blank, and the
     * user is blocked by a bare "required" error on a field they never touched.
     */
    private function generateHandleFrom(?string $name): string
    {
        if (blank($name)) {
            return '';
        }

        $slug = Str::slug($name);

        return $slug === '' ? Str::lower(Str::random(8)) : $slug;
    }

    private function stepHeading(string $title, string ...$paragraphs): HtmlString
    {
        $html = '<h3 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">'.e($title).'</h3>';

        foreach ($paragraphs as $index => $paragraph) {
            $spacing = $index === 0 ? 'mt-1' : 'mt-2';

            $html .= '<p class="'.$spacing.' text-sm text-gray-500 dark:text-gray-400">'.e($paragraph).'</p>';
        }

        return new HtmlString($html);
    }

    /**
     * @return array<Component>
     */
    private function getWorkspaceFormComponents(): array
    {
        $appHost = parse_url(url()->getAppUrl(), PHP_URL_HOST);

        return [
            TextInput::make('name')
                ->label(__('filament/pages/teams.create_team.form.company_name.label'))
                ->required()
                ->maxLength(255)
                ->placeholder(__('filament/pages/teams.create_team.form.company_name.placeholder'))
                ->autofocus()
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    if ($get('slug_auto_generated') !== true && filled($get('slug'))) {
                        return;
                    }

                    $set('slug', $this->generateHandleFrom($state));
                    $set('slug_auto_generated', true);
                }),

            TextInput::make('slug')
                ->label(__('filament/pages/teams.create_team.form.workspace_handle.label'))
                ->required()
                ->maxLength(255)
                ->rules([new ValidTeamSlug])
                ->unique(
                    table: Team::class,
                    column: 'slug',
                    ignorable: fn (): ?Team => $this->tenant instanceof Team ? $this->tenant : null,
                )
                ->prefix("{$appHost}/")
                ->helperText(__('filament/pages/teams.create_team.form.workspace_handle.helper_text'))
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set): void {
                    $set('slug_auto_generated', false);
                }),

            Hidden::make('slug_auto_generated')
                ->default(true)
                ->dehydrated(false),
        ];
    }

    /**
     * Whether the invite step currently holds an address worth sending, which decides
     * between the "Send invites" and "Get started" submit labels.
     */
    private function hasPendingInvites(): bool
    {
        $invites = $this->data['invites'] ?? [];

        if (! is_array($invites)) {
            return false;
        }

        return array_any($invites, fn (mixed $invite): bool => is_array($invite) && filled($invite['email'] ?? null));
    }

    /**
     * "Skip for now" on the invite step must not send the invites the user just
     * decided to skip. Both footer buttons used to call register() directly, so a
     * filled-in address went out regardless of which one was clicked.
     */
    public function skipInvites(): void
    {
        $this->data['invites'] = [];

        $this->register();
    }

    protected function afterRegister(): void
    {
        session()->forget(self::COMPLETING_SESSION_KEY);

        /** @var Team $tenant */
        $tenant = $this->tenant;

        Notification::make()
            ->title(__('filament/pages/teams.create_team.notifications.workspace_created.title'))
            ->body(__('filament/pages/teams.create_team.notifications.workspace_created.body', ['name' => $tenant->name]))
            ->success()
            ->send();
    }

    #[Override]
    protected function getRedirectUrl(): string
    {
        return Dashboard::getUrl(['tenant' => $this->tenant]);
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

        /** @var list<array{email: string, reason: string}> $failed */
        $failed = [];

        // Retrying a dead mail server once per address means waiting out the socket
        // timeout up to five times inside this request. One refusal is enough.
        $transportIsDown = false;

        foreach ($rawInvites as $invite) {
            $email = $invite['email'] ?? null;

            if (blank($email)) {
                continue;
            }

            // The field's `email` rule is looser than filter_var (it accepts
            // `user@example`), so an address can clear the form and still be
            // unusable here. Report it rather than dropping it silently.
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $failed[] = [
                    'email' => $email,
                    'reason' => __('filament/pages/teams.create_team.notifications.some_invites_failed.invalid_email'),
                ];

                continue;
            }

            // Never attempted, so there is no invitation to resend: this address has to
            // be invited from scratch once mail is working again.
            if ($transportIsDown) {
                $failed[] = [
                    'email' => $email,
                    'reason' => __('filament/pages/teams.create_team.notifications.some_invites_failed.send_skipped'),
                ];

                continue;
            }

            try {
                resolve(InviteTeamMember::class)->invite(
                    $user,
                    $team,
                    $email,
                    $invite['role'] ?? TeamRole::Editor->value,
                );
            } catch (ValidationException $exception) {
                $firstError = collect($exception->errors())->flatten()->first();

                $failed[] = [
                    'email' => $email,
                    'reason' => is_string($firstError)
                        ? $firstError
                        : __('filament/pages/teams.create_team.notifications.some_invites_failed.generic'),
                ];
            } catch (TransportExceptionInterface $exception) {
                // The workspace already exists by now, and the invitation row is written
                // before the send, so the owner can resend from settings. Letting this
                // escape would strand the user on a half-finished registration.
                report($exception);

                $transportIsDown = true;

                $failed[] = [
                    'email' => $email,
                    'reason' => __('filament/pages/teams.create_team.notifications.some_invites_failed.send_failed'),
                ];
            }
        }

        if ($failed !== []) {
            $body = collect($failed)
                ->map(fn (array $failure): string => "{$failure['email']}: {$failure['reason']}")
                ->implode("\n");

            Notification::make()
                ->title(__('filament/pages/teams.create_team.notifications.some_invites_failed.title'))
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
            ->label(__('filament/pages/teams.create_team.actions.get_started'))
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

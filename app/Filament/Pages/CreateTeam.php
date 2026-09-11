<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Jetstream\CreateTeam as CreateTeamAction;
use App\Actions\User\UpdateUserName;
use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Models\Team;
use App\Models\User;
use App\Rules\ValidTeamSlug;
use App\Support\WorkspaceUrlPrefix;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
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
    public function mount(): void
    {
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
     * workspace. Null during first-run onboarding: a user with no workspace
     * has nowhere to go back to, so no cancel affordance is offered.
     */
    public function getCancelUrl(): ?string
    {
        /** @var User $user */
        $user = auth('web')->user();
        $tenant = Filament::getUserDefaultTenant($user);

        return $tenant instanceof Team
            ? Dashboard::getUrl(['tenant' => $tenant])
            : null;
    }

    public function getCancelLabel(): string
    {
        return __('filament/pages/teams.create_team.actions.cancel');
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
                            ->label(__('filament/pages/teams.create_team.actions.get_started'))
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
                    ->label(__('filament/pages/teams.create_team.headings.workspace'))
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
                    ->label(__('filament/pages/teams.create_team.headings.attribution'))
                    ->hiddenLabel()
                    ->content($this->stepHeading(
                        __('filament/pages/teams.create_team.headings.attribution'),
                        __('filament/pages/teams.create_team.headings.attribution_description'),
                    ))
                    ->dehydrated(false),

                ToggleButtons::make('onboarding_referral_source')
                    ->label(__('filament/pages/teams.create_team.headings.attribution'))
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
                    ->label(__('filament/pages/teams.create_team.headings.use_case'))
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

                TextInput::make('onboarding_other_use_case')
                    ->label(__('filament/pages/teams.create_team.form.other_use_case_label'))
                    ->placeholder(__('filament/pages/teams.create_team.form.other_use_case_placeholder'))
                    ->validationAttribute(__('filament/pages/teams.create_team.form.other_use_case_validation_attribute'))
                    ->maxLength(120)
                    ->visible(fn (Get $get): bool => $get('onboarding_use_case') === OnboardingUseCase::Other->value),
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

    // The default handle is the same for everyone; without a suffix the unique
    // rule rejects a field the user never touched.
    private function uniqueHandleFor(string $handle): string
    {
        if (! Team::query()->where('slug', $handle)->exists()) {
            return $handle;
        }

        $highest = (int) Team::query()
            ->where('slug', 'like', "{$handle}-%")
            ->selectRaw('max(substring(slug from ?)::int) as highest', ['^'.$handle.'-(\d{1,9})$'])
            ->value('highest');

        return "{$handle}-".max($highest + 1, 2);
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
        return [
            TextInput::make('user_name')
                ->label(__('filament/pages/teams.create_team.form.your_name.label'))
                ->required()
                ->maxLength(255)
                ->placeholder(__('filament/pages/teams.create_team.form.your_name.placeholder'))
                ->autofocus()
                ->default(function (): string {
                    /** @var User $user */
                    $user = auth('web')->user();

                    return $user->name;
                }),

            TextInput::make('name')
                ->label(__('filament/pages/teams.create_team.form.workspace_name.label'))
                ->required()
                ->maxLength(255)
                ->placeholder(__('filament/pages/teams.create_team.form.workspace_name.placeholder'))
                ->default(fn (): string => __('filament/pages/teams.create_team.form.workspace_name.default'))
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    if ($get('slug_auto_generated') !== true && filled($get('slug'))) {
                        return;
                    }

                    $set('slug', $this->uniqueHandleFor($this->generateHandleFrom($state)));
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
                ->default(fn (): string => $this->uniqueHandleFor(
                    $this->generateHandleFrom(__('filament/pages/teams.create_team.form.workspace_name.default')),
                ))
                ->prefix(WorkspaceUrlPrefix::get())
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

    protected function afterRegister(): void
    {
        /** @var User $user */
        $user = auth('web')->user();

        // Flagged here, not inside CreateTeamAction: getRedirectUrl() sends the user to
        // the dashboard next, so this one event marks the workspace as created AND the
        // user as landed.
        //
        // First workspace only. A later one is expansion, not conversion: its
        // referrer is whatever brought the user back that day rather than the
        // channel that acquired them, so counting it would misattribute the
        // channel AND push signup-to-workspace above 100%. How many workspaces
        // a user owns is a question the teams table already answers exactly.
        if ($user->ownedTeams()->count() === 1) {
            session()->put('fathom.track_workspace_created', true);
        }

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

        $this->updateUserNameIfChanged($user, $data);

        return resolve(CreateTeamAction::class)->create($user, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateUserNameIfChanged(User $user, array $data): void
    {
        $name = $data['user_name'] ?? null;

        if (! is_string($name) || $name === $user->name) {
            return;
        }

        resolve(UpdateUserName::class)->execute($user, $name);
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

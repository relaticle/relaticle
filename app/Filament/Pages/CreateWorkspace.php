<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Jetstream\CreateWorkspace as CreateWorkspaceAction;
use App\Actions\User\UpdateUserName;
use App\Enums\OnboardingReferralSource;
use App\Filament\Components\Forms\WorkspaceLogoUpload;
use App\Filament\Pages\Concerns\BuildsOnboardingPreview;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ValidWorkspaceSlug;
use App\Support\WorkspaceUrlPrefix;
use BackedEnum;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Override;

final class CreateWorkspace extends RegisterTenant
{
    use BuildsOnboardingPreview;

    protected string $view = 'filament.pages.create-workspace';

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
                ->title(__('filament/pages/workspaces.create_workspace.notifications.workspace_limit_reached.title'))
                ->body(__('filament/pages/workspaces.create_workspace.notifications.workspace_limit_reached.body'))
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
        return __('filament/pages/workspaces.create_workspace.label');
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

        return $tenant instanceof Workspace
            ? Dashboard::getUrl(['tenant' => $tenant])
            : null;
    }

    public function getCancelLabel(): string
    {
        return __('filament/pages/workspaces.create_workspace.actions.cancel');
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    $this->getWorkspaceStep(),
                    ...($this->ownsNoWorkspace() ? [$this->getAttributionStep()] : []),
                ])
                    ->view('components.onboarding.wizard')
                    ->hiddenHeader()
                    ->contained(false)
                    ->nextAction(
                        fn (Action $action): Action => $action
                            ->label(__('filament/pages/workspaces.create_workspace.actions.continue'))
                            ->size(Size::Large)
                            ->extraAttributes(['class' => 'w-full'])
                    )
                    ->submitAction(
                        Action::make('register')
                            ->label(__('filament/pages/workspaces.create_workspace.actions.continue'))
                            ->size(Size::Large)
                            ->submit('register')
                            ->extraAttributes(['class' => 'w-full'])
                    ),
            ]);
    }

    private function getWorkspaceStep(): Step
    {
        return Step::make(__('filament/pages/workspaces.create_workspace.steps.workspace'))
            ->schema([
                Placeholder::make('workspace_heading')
                    ->label(__('filament/pages/workspaces.create_workspace.headings.workspace'))
                    ->hiddenLabel()
                    ->content($this->stepHeading(__('filament/pages/workspaces.create_workspace.headings.workspace')))
                    ->dehydrated(false),
                ...$this->getWorkspaceFormComponents(),
            ]);
    }

    private function getAttributionStep(): Step
    {
        return Step::make(__('filament/pages/workspaces.create_workspace.steps.attribution'))
            ->key('onboarding-attribution')
            ->schema([
                Placeholder::make('attribution_heading')
                    ->label(__('filament/pages/workspaces.create_workspace.headings.attribution'))
                    ->hiddenLabel()
                    ->content($this->stepHeading(
                        __('filament/pages/workspaces.create_workspace.headings.attribution'),
                        __('filament/pages/workspaces.create_workspace.headings.attribution_description'),
                    ))
                    ->dehydrated(false),

                ToggleButtons::make('onboarding_referral_source')
                    ->label(__('filament/pages/workspaces.create_workspace.headings.attribution'))
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
                    ->inline()
                    ->live(),

                ...$this->getReferralFollowUpComponents(),
            ]);
    }

    /**
     * @return array<Component>
     */
    private function getReferralFollowUpComponents(): array
    {
        $subOptions = fn (Get $get): array => OnboardingReferralSource::tryFrom($get('onboarding_referral_source') ?? '')?->getSubOptions() ?? [];

        return [
            ToggleButtons::make('onboarding_referral_detail')
                ->label(__('filament/pages/workspaces.create_workspace.form.referral_detail_label'))
                ->options($subOptions)
                ->inline()
                ->visible(fn (Get $get): bool => $subOptions($get) !== []),

            TextInput::make('onboarding_referral_prompt')
                ->label(__('filament/pages/workspaces.create_workspace.form.referral_prompt_label'))
                ->placeholder(__('filament/pages/workspaces.create_workspace.form.referral_prompt_placeholder'))
                ->validationAttribute(__('filament/pages/workspaces.create_workspace.form.referral_prompt_validation_attribute'))
                ->maxLength(200)
                ->visible(fn (Get $get): bool => $subOptions($get) !== []),
        ];
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

    private function handleIsDerived(Get $get): bool
    {
        $slug = $get('slug');

        return blank($slug) || $slug === $get('slug_derived');
    }

    private function ownsNoWorkspace(): bool
    {
        /** @var User $user */
        $user = auth('web')->user();

        return ! $user->ownedWorkspaces()->exists();
    }

    private function isFirstWorkspace(): bool
    {
        /** @var User $user */
        $user = auth('web')->user();

        return ! Filament::getUserDefaultTenant($user) instanceof Workspace;
    }

    /**
     * @return array<Component>
     */
    private function getWorkspaceFormComponents(): array
    {
        return [
            WorkspaceLogoUpload::make('logo')
                ->label(__('filament/pages/workspaces.create_workspace.form.company_logo.label')),

            TextInput::make('user_name')
                ->label(__('filament/pages/workspaces.create_workspace.form.your_name.label'))
                ->required()
                ->maxLength(255)
                ->placeholder(__('filament/pages/workspaces.create_workspace.form.your_name.placeholder'))
                ->live(onBlur: true)
                ->visible(fn (): bool => $this->isFirstWorkspace())
                ->default(function (): string {
                    /** @var User $user */
                    $user = auth('web')->user();

                    return $user->name;
                }),

            TextInput::make('name')
                ->label(__('filament/pages/workspaces.create_workspace.form.workspace_name.label'))
                ->autofocus()
                ->required()
                ->maxLength(255)
                ->placeholder(__('filament/pages/workspaces.create_workspace.form.workspace_name.placeholder'))
                // Typed, not blurred: the handle has to track the name as it is written.
                // That unanchors the derive from focus, so ownership is decided by
                // comparing the handle to the last value this derived, never by which
                // field's update Livewire happens to process first.
                ->live(debounce: 400)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    if (! $this->handleIsDerived($get)) {
                        return;
                    }

                    $derived = Workspace::availableSlugFor($state);

                    $set('slug', $derived);
                    $set('slug_derived', $derived);
                }),

            TextInput::make('slug')
                ->label(__('filament/pages/workspaces.create_workspace.form.workspace_handle.label'))
                ->required()
                ->maxLength(255)
                ->rules([new ValidWorkspaceSlug])
                ->rules(
                    [fn (): Unique => Rule::unique(Workspace::class, 'slug')],
                    condition: fn (Get $get): bool => ! $this->handleIsDerived($get),
                )
                ->prefix(WorkspaceUrlPrefix::get())
                ->placeholder(__('filament/pages/workspaces.create_workspace.form.workspace_handle.placeholder'))
                ->helperText(__('filament/pages/workspaces.create_workspace.form.workspace_handle.helper_text'))
                // Undebounced so a typed handle reaches the server before the name's
                // own debounce fires; a pending update is invisible to the name's hook.
                ->live(),

            Hidden::make('slug_derived')
                ->dehydrated(false),
        ];
    }

    protected function afterRegister(): void
    {
        /** @var User $user */
        $user = auth('web')->user();

        // Flagged here, not inside CreateWorkspaceAction: the redirect below sends the user to
        // the setup page next, so this one event marks the workspace as created AND the
        // user as landed.
        //
        // First workspace only. A later one is expansion, not conversion: its
        // referrer is whatever brought the user back that day rather than the
        // channel that acquired them, so counting it would misattribute the
        // channel AND push signup-to-workspace above 100%. How many workspaces
        // a user owns is a question the workspaces table already answers exactly.
        if ($user->ownedWorkspaces()->count() === 1) {
            session()->put('fathom.track_workspace_created', true);
        }

        /** @var Workspace $tenant */
        $tenant = $this->tenant;

        // A full page load: the wizard's Alpine state does not survive a client-side hop,
        // and the torn-down component logs a burst of undefined-variable errors.
        $this->redirect(SetupWorkspace::getUrl(['tenant' => $tenant]));
    }

    #[Override]
    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    #[Override]
    protected function handleRegistration(array $data): Model
    {
        /** @var User $user */
        $user = auth('web')->user();

        $this->updateUserNameIfChanged($user, $data);

        if (($this->data['slug_derived'] ?? null) === $data['slug'] && Workspace::query()->where('slug', $data['slug'])->exists()) {
            $data['slug'] = null;
        }

        return resolve(CreateWorkspaceAction::class)->create($user, $data);
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
            ->label(__('filament/pages/workspaces.create_workspace.actions.get_started'))
            ->submit('register')
            ->extraAttributes(['class' => 'w-full']);
    }

    /**
     * @return array{
     *     companyPlaceholder: string,
     *     workspaceName: string,
     *     workspaceAvatarUrl: string,
     *     userAvatarUrl: string,
     *     greeting: string,
     *     navigationIcons: array<string, string|BackedEnum|Htmlable|null>,
     *     stages: list<array{name: string, color: string}>
     * }
     */
    public function getPreview(): array
    {
        /** @var User $user */
        $user = auth('web')->user();

        $companyPlaceholder = (string) __('filament/pages/workspaces.create_workspace.preview.company_placeholder');
        $workspaceName = trim((string) ($this->data['name'] ?? '')) ?: $companyPlaceholder;
        $userName = trim((string) ($this->data['user_name'] ?? '')) ?: $user->name;

        return $this->onboardingPreview($workspaceName, $this->previewLogoUrl(), $userName, []);
    }

    private function previewLogoUrl(): ?string
    {
        $files = $this->data['logo'] ?? [];

        if (! is_array($files)) {
            return null;
        }

        foreach ($files as $file) {
            if ($file instanceof TemporaryUploadedFile && $file->isPreviewable()) {
                return $file->temporaryUrl();
            }
        }

        return null;
    }
}

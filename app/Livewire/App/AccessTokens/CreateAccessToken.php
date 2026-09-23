<?php

declare(strict_types=1);

namespace App\Livewire\App\AccessTokens;

use App\Livewire\BaseLivewireComponent;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Jetstream\Jetstream;
use Laravel\Sanctum\NewAccessToken;

final class CreateAccessToken extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $plainTextToken = null;

    private const string DEFAULT_EXPIRATION_DAYS = '180';

    public function mount(): void
    {
        $this->form->fill($this->initialFormState());
    }

    /**
     * @return array<string, mixed>
     */
    private function initialFormState(): array
    {
        return [
            'workspace_id' => $this->authUser()->currentWorkspace?->getKey(),
            'permissions' => Jetstream::$defaultPermissions,
            'expiration' => self::DEFAULT_EXPIRATION_DAYS,
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('access-tokens.sections.create.title'))
                    ->description(
                        __('access-tokens.sections.create.description'),
                    )
                    ->schema([
                        TextInput::make('name')
                            ->label(__('access-tokens.form.name'))
                            ->required()
                            ->maxLength(255)
                            ->rules([
                                Rule::unique('personal_access_tokens', 'name')
                                    ->where(
                                        'tokenable_type',
                                        $this->authUser()->getMorphClass(),
                                    )
                                    ->where(
                                        'tokenable_id',
                                        $this->authUser()->getKey(),
                                    ),
                            ]),
                        Select::make('workspace_id')
                            ->label(__('access-tokens.form.workspace'))
                            ->required()
                            ->options(
                                $this->authUser()->allWorkspaces()->pluck('name', 'id'),
                            )
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set): mixed => $set(
                                'permissions',
                                array_values(array_intersect((array) $get('permissions'), self::grantablePermissions($get('workspace_id')))),
                            )),
                        Select::make('expiration')
                            ->label(__('access-tokens.form.expiration'))
                            ->required()
                            ->placeholder(__('access-tokens.form.expiration_placeholder'))
                            ->options([
                                '1' => '1 Day',
                                '7' => '7 Days',
                                '30' => '30 Days',
                                '60' => '60 Days',
                                '90' => '90 Days',
                                '180' => '180 Days',
                                '365' => '1 Year',
                                '0' => 'No Expiration',
                            ]),
                        self::permissionsCheckboxList(),
                        Actions::make([
                            Action::make('create')
                                ->label(__('access-tokens.actions.create'))
                                ->action('createToken'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function showTokenAction(): Action
    {
        return Action::make('showToken')
            ->modalHeading(__('access-tokens.modals.show_token.title'))
            ->modalDescription(
                __('access-tokens.modals.show_token.description'),
            )
            ->modalWidth(Width::Large)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('access-tokens.modals.show_token.cancel_label'))
            ->schema([
                TextInput::make('plainTextToken')
                    ->label(__('access-tokens.form.token'))
                    ->default($this->plainTextToken ?? '')
                    ->readOnly()
                    ->suffixAction(
                        Action::make('copyToken')
                            ->icon('heroicon-o-clipboard')
                            ->tooltip(__('access-tokens.modals.show_token.copy_to_clipboard_tooltip'))
                            ->alpineClickHandler(sprintf(
                                'window.navigator.clipboard.writeText($wire.plainTextToken); $tooltip(%s);',
                                Js::from(__('access-tokens.modals.show_token.copied_tooltip')),
                            )),
                    ),
            ])
            ->after(fn (): null => ($this->plainTextToken = null));
    }

    public function createToken(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        $state = $this->form->getState();

        $user = $this->authUser();
        $workspaceId = $state['workspace_id'];

        if ($user->allWorkspaces()->doesntContain('id', $workspaceId)) {
            $this->sendNotification(title: 'You do not belong to this workspace.', type: 'danger');

            return;
        }

        $expiration = (int) $state['expiration'];
        $expiresAt = $expiration > 0 ? now()->addDays($expiration) : null;

        /** @var NewAccessToken $token */
        $token = DB::transaction(function () use ($user, $state, $workspaceId, $expiresAt): NewAccessToken {
            $token = $user->createToken(
                $state['name'],
                array_values(array_intersect(
                    Jetstream::validPermissions($state['permissions'] ?? []),
                    self::grantablePermissions($workspaceId),
                )),
            );

            // Sanctum's createToken() does not accept extra attributes, so we update after creation
            $token->accessToken->fill([
                'workspace_id' => $workspaceId,
                'expires_at' => $expiresAt,
            ])->save();

            return $token;
        });

        $this->plainTextToken = explode('|', $token->plainTextToken, 2)[1];

        $this->form->fill($this->initialFormState());

        $this->dispatch('tokenCreated');

        $this->mountAction('showToken');
    }

    public static function permissionsCheckboxList(): CheckboxList
    {
        return CheckboxList::make('permissions')
            ->label(__('access-tokens.form.permissions'))
            ->required()
            ->options(
                fn (Get $get): array => collect(self::grantablePermissions($get('workspace_id')))
                    ->mapWithKeys(
                        fn (string $permission): array => [
                            $permission => ucfirst($permission),
                        ],
                    )
                    ->all(),
            )
            ->columns(2);
    }

    // An unpinned token follows whichever workspace a request names, so only a
    // pinned one is bounded by the holder's role there.
    /** @return list<string> */
    public static function grantablePermissions(mixed $workspaceId): array
    {
        if (! is_string($workspaceId) || $workspaceId === '') {
            return array_values(Jetstream::$permissions);
        }

        $user = auth()->user();
        $allowed = $user instanceof User ? $user->workspaceTokenPermissions($workspaceId) : [];

        return array_values(array_intersect(Jetstream::$permissions, $allowed));
    }

    public function render(): View
    {
        return view('livewire.app.access-tokens.create-access-token');
    }
}

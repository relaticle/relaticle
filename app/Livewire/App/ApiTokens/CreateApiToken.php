<?php

declare(strict_types=1);

namespace App\Livewire\App\ApiTokens;

use App\Livewire\BaseLivewireComponent;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Jetstream\Jetstream;
use Laravel\Sanctum\NewAccessToken;

final class CreateApiToken extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $plainTextToken = null;

    public function mount(): void
    {
        $this->form->fill([
            'team_id' => $this->authUser()->currentTeam?->getKey(),
            'permissions' => Jetstream::$defaultPermissions,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('access-tokens.sections.create.title'))
                    ->aside()
                    ->description(
                        __('access-tokens.sections.create.description'),
                    )
                    ->schema([
                        TextInput::make('name')
                            ->label('Token Name')
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
                        Select::make('team_id')
                            ->label('Team')
                            ->required()
                            ->options(
                                $this->authUser()->allTeams()->pluck('name', 'id'),
                            ),
                        Select::make('expiration')
                            ->label('Expiration')
                            ->required()
                            ->placeholder('Select expiration...')
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
                                ->label('Create')
                                ->submit('createToken'),
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
            ->modalCancelActionLabel('Close')
            ->schema([
                TextInput::make('plainTextToken')
                    ->label('Token')
                    ->default($this->plainTextToken ?? '')
                    ->readOnly()
                    ->suffixAction(
                        Action::make('copyToken')
                            ->icon('heroicon-o-clipboard')
                            ->tooltip('Copy to clipboard')->alpineClickHandler("
                                window.navigator.clipboard.writeText(\$wire.plainTextToken);
                                \$tooltip('Copied!');
                            "),
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
        $teamId = $state['team_id'];

        if ($user->allTeams()->doesntContain('id', $teamId)) {
            $this->sendNotification(title: 'You do not belong to this team.', type: 'danger');

            return;
        }

        $expiration = (int) $state['expiration'];
        $expiresAt = $expiration > 0 ? now()->addDays($expiration) : null;

        /** @var NewAccessToken $token */
        $token = $user->createToken(
            $state['name'],
            Jetstream::validPermissions($state['permissions'] ?? []),
        );

        // Sanctum's createToken() does not accept extra attributes, so we update after creation
        $token->accessToken->fill([
            'team_id' => $teamId,
            'expires_at' => $expiresAt,
        ])->save();

        $this->plainTextToken = explode('|', $token->plainTextToken, 2)[1];

        $this->form->fill([
            'team_id' => $user->currentTeam?->getKey(),
            'permissions' => Jetstream::$defaultPermissions,
        ]);

        $this->dispatch('tokenCreated');

        $this->mountAction('showToken');
    }

    public static function permissionsCheckboxList(): CheckboxList
    {
        return CheckboxList::make('permissions')
            ->label('Permissions')
            ->required()
            ->options(
                collect(Jetstream::$permissions)
                    ->mapWithKeys(
                        fn (string $permission): array => [
                            $permission => ucfirst($permission),
                        ],
                    )
                    ->all(),
            )
            ->columns(2);
    }

    public function render(): View
    {
        return view('livewire.app.api-tokens.create-api-token');
    }
}

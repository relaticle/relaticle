<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Jetstream\CreateTeam as CreateTeamAction;
use App\Filament\Resources\CompanyResource;
use App\Models\Team;
use App\Models\User;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Override;

final class CreateTeam extends RegisterTenant
{
    #[Override]
    public static function getLabel(): string
    {
        return 'Create Team';
    }

    #[Override]
    public function getHeading(): string
    {
        return 'Create your workspace';
    }

    #[Override]
    public function getSubheading(): string
    {
        return 'Choose a name for your team. This will also be used in your workspace URL.';
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        $appHost = parse_url(url()->getAppUrl(), PHP_URL_HOST);

        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if ($get('slug_auto_generated') === true || blank($get('slug'))) {
                            $set('slug', Str::slug($state ?? ''));
                            $set('slug_auto_generated', true);
                        }
                    }),

                TextInput::make('slug')
                    ->required()
                    ->minLength(3)
                    ->maxLength(255)
                    ->regex(Team::SLUG_REGEX)
                    ->unique(Team::class, 'slug')
                    ->prefix("{$appHost}/")
                    ->helperText('Only lowercase letters, numbers, and hyphens are allowed.')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set): void {
                        $set('slug_auto_generated', false);
                    }),

                Hidden::make('slug_auto_generated')
                    ->default(true)
                    ->dehydrated(false),
            ]);
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

        return resolve(CreateTeamAction::class)->create($user, $data);
    }
}

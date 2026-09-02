<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Team\UpdateTeamBranding as UpdateTeamBrandingAction;
use App\Enums\TeamAccent;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;

final class UpdateTeamBranding extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public Team $team;

    public function mount(Team $team): void
    {
        $this->team = $team;

        $this->form->fill([
            'logo_path' => $team->logo_path,
            'accent_color' => $team->accent_color,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('teams.sections.update_team_branding.title'))
                    ->aside()
                    ->description(__('teams.sections.update_team_branding.description'))
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label(__('teams.form.team_logo.label'))
                            ->helperText(__('teams.form.team_logo.helper_text'))
                            ->image()
                            ->imageEditor()
                            ->disk(config('jetstream.profile_photo_disk'))
                            ->directory('team-logos')
                            ->visibility('public'),
                        Select::make('accent_color')
                            ->label(__('teams.form.accent_color.label'))
                            ->helperText(__('teams.form.accent_color.helper_text'))
                            ->placeholder(__('teams.form.accent_color.placeholder'))
                            ->options(collect(TeamAccent::cases())->mapWithKeys(
                                fn (TeamAccent $accent): array => [$accent->value => $accent->label()],
                            ))
                            ->native(false),
                        Actions::make([
                            Action::make('save')
                                ->label(__('profile.actions.save'))
                                ->submit('updateBranding'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function updateBranding(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        $data = $this->form->getState();

        $this->deleteReplacedLogo($this->team->logo_path, $data['logo_path']);

        resolve(UpdateTeamBrandingAction::class)->execute(
            $this->team,
            $data['logo_path'],
            $data['accent_color'] ? TeamAccent::from($data['accent_color']) : null,
        );

        $this->sendNotification();
    }

    private function deleteReplacedLogo(?string $oldPath, ?string $newPath): void
    {
        if ($oldPath === null || $oldPath === $newPath) {
            return;
        }

        Storage::disk(config('jetstream.profile_photo_disk'))->delete($oldPath);
    }
}

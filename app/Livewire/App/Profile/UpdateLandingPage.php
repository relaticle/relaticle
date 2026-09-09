<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\User\UpdateLandingPage as UpdateLandingPageAction;
use App\Enums\LandingPage;
use App\Livewire\BaseLivewireComponent;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class UpdateLandingPage extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'landing_page' => LandingPage::fromUser($this->authUser())->value,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('profile.sections.update_landing_page.title'))
                    ->aside()
                    ->description(__('profile.sections.update_landing_page.description'))
                    ->schema([
                        Select::make('landing_page')
                            ->label(__('profile.form.landing_page.label'))
                            ->helperText(__('profile.form.landing_page.helper_text'))
                            ->options(collect(LandingPage::cases())->mapWithKeys(
                                fn (LandingPage $page): array => [$page->value => $page->label()],
                            ))
                            ->native(false),
                        Actions::make([
                            Action::make('save')
                                ->label(__('profile.actions.save'))
                                ->submit('updateLandingPage'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function updateLandingPage(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        $data = $this->form->getState();

        resolve(UpdateLandingPageAction::class)->execute(
            $this->authUser(),
            LandingPage::from($data['landing_page']),
        );

        $this->sendNotification();
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\Fortify\UpdateUserProfileInformation as UpdateUserProfileInformationAction;
use App\Livewire\BaseLivewireComponent;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class UpdateProfileInformation extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $data = $this->authUser()->only(['name', 'email']);

        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('profile.sections.update_profile_information.title'))
                    ->aside()
                    ->description(__('profile.sections.update_profile_information.description'))
                    ->schema([
                        FileUpload::make('profile_photo_path')
                            ->label(__('profile.form.profile_photo.label'))
                            ->avatar()
                            ->image()
                            ->imageEditor()
                            ->disk(config('jetstream.profile_photo_disk'))
                            ->directory('profile-photos')
                            ->visibility('public')
                            ->formatStateUsing(fn () => auth('web')->user()?->profile_photo_path),
                        TextInput::make('name')
                            ->label(__('profile.form.name.label'))
                            ->string()
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('email')
                            ->label(__('profile.form.email.label'))
                            ->email()
                            ->required()
                            ->unique(Filament::auth()->user() !== null ? Filament::auth()->user()::class : self::class, ignorable: $this->authUser()),
                        Actions::make([
                            Action::make('save')
                                ->label(__('profile.actions.save'))
                                ->submit('updateProfile'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function updateProfile(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        $data = $this->form->getState();

        resolve(UpdateUserProfileInformationAction::class)->update($this->authUser(), $data);

        $this->sendNotification();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.app.profile.update-profile-information');
    }
}

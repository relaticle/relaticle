<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\User\UpdateNotificationPreferences as UpdateNotificationPreferencesAction;
use App\Data\NotificationPreferences;
use App\Enums\Notifications\DigestCadence;
use App\Livewire\BaseLivewireComponent;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;

final class UpdateNotificationPreferences extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $preferences = $this->authUser()->notificationPreferences();

        $this->form->fill([
            'task_assigned_in_app' => $preferences->taskAssignedInApp,
            'task_assigned_email' => $preferences->taskAssignedEmail,
            'digest_cadence' => $preferences->digestCadence->value,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('profile.sections.notifications.title'))
                    ->aside()
                    ->description(__('profile.sections.notifications.description'))
                    ->schema([
                        Toggle::make('task_assigned_in_app')
                            ->label(__('profile.notifications.task_assigned_in_app')),
                        Toggle::make('task_assigned_email')
                            ->label(__('profile.notifications.task_assigned_email')),
                        Select::make('digest_cadence')
                            ->label(__('profile.notifications.digest_cadence'))
                            ->options([
                                DigestCadence::Daily->value => __('profile.notifications.cadence.daily'),
                                DigestCadence::Weekly->value => __('profile.notifications.cadence.weekly'),
                                DigestCadence::Off->value => __('profile.notifications.cadence.off'),
                            ])
                            ->selectablePlaceholder(false)
                            ->required(),
                        Actions::make([
                            Action::make('saveNotifications')
                                ->label(__('profile.actions.save'))
                                ->submit('save'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        $data = $this->form->getState();

        resolve(UpdateNotificationPreferencesAction::class)->execute(
            $this->authUser(),
            new NotificationPreferences(
                taskAssignedInApp: (bool) $data['task_assigned_in_app'],
                taskAssignedEmail: (bool) $data['task_assigned_email'],
                digestCadence: DigestCadence::from((string) $data['digest_cadence']),
            ),
        );

        $this->sendNotification();
    }

    public function render(): View
    {
        return view('livewire.app.profile.update-notification-preferences');
    }
}

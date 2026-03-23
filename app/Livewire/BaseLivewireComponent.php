<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * @property Schema $form
 */
abstract class BaseLivewireComponent extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithRateLimiting;

    public function authUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    protected function sendRateLimitedNotification(TooManyRequestsException $exception): void
    {
        Notification::make()
            ->title('Too Many Requests')
            ->body("Please wait {$exception->secondsUntilAvailable} seconds before trying again.")
            ->danger()
            ->send();
    }

    protected function sendNotification(string $title = 'Saved', ?string $message = null, string $type = 'success'): void
    {
        Notification::make()
            ->title(__($title))
            ->body(__($message))
            ->{$type}()
            ->send();
    }
}

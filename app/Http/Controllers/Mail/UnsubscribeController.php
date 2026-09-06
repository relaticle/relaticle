<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Actions\User\UpdateNotificationPreferences;
use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Filament\Pages\NotificationPreferences;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

final readonly class UnsubscribeController
{
    public function __construct(private UpdateNotificationPreferences $updatePreferences) {}

    public function show(User $user, NotificationType $type): View
    {
        return view('mail.unsubscribe', [
            'user' => $user,
            'type' => $type,
            'subscribed' => $user->wantsNotification($type, NotificationChannel::Email),
            'settingsUrl' => $this->settingsUrl($user),
        ]);
    }

    public function store(Request $request, User $user, NotificationType $type): Response|View
    {
        $this->updatePreferences->execute($user, $type, NotificationChannel::Email, false);

        if ($request->expectsJson() || $request->has('List-Unsubscribe')) {
            return response()->noContent(Response::HTTP_OK);
        }

        return view('mail.unsubscribe', [
            'user' => $user,
            'type' => $type,
            'subscribed' => false,
            'settingsUrl' => $this->settingsUrl($user),
        ]);
    }

    private function settingsUrl(User $user): string
    {
        $tenant = $user->currentTeam ?? $user->allTeams()->first();

        return $tenant === null
            ? url()->getAppUrl()
            : NotificationPreferences::getUrl(panel: 'app', tenant: $tenant);
    }
}

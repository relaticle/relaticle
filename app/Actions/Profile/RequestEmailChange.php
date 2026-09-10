<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use App\Support\EmailAddress;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use League\Uri\Components\Query;

final readonly class RequestEmailChange
{
    public function execute(User $user, string $newEmail): void
    {
        $newEmail = EmailAddress::canonicalize($newEmail);

        Validator::make(['email' => $newEmail], [
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->getKey())],
        ])->validate();

        $user = DB::transaction(function () use ($user, $newEmail): User {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            AuthenticationSession::consumeOperation($lockedUser, 'change_email', $newEmail);

            return $lockedUser;
        });

        $panel = Filament::getPanel('app');
        $notification = resolve(VerifyEmailChange::class);
        $notification->url = $panel->getVerifyEmailChangeUrl($user, $newEmail);
        $verificationSignature = Query::new($notification->url)->get('signature');

        cache()->put($verificationSignature, true, ttl: now()->addHour());

        $user->notify(resolve(NoticeOfEmailChangeRequest::class, [
            'blockVerificationUrl' => $panel->getBlockEmailChangeVerificationUrl($user, $newEmail, $verificationSignature),
            'newEmail' => $newEmail,
        ]));

        Notification::route('mail', $newEmail)->notify($notification);
    }
}

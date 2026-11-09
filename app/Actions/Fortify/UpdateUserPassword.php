<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Profile\DestroyOtherBrowserSessions;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

final readonly class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private DestroyOtherBrowserSessions $destroyOtherBrowserSessions) {}

    /**
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validateWithBag('updatePassword');

        DB::transaction(function () use ($user, $input): void {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            AuthenticationSession::consumeOperation($lockedUser, 'set_password', null);

            $lockedUser->forceFill([
                'password' => Hash::make($input['password']),
            ])->save();

            $this->destroyOtherBrowserSessions->execute($lockedUser, session()->getId());
        });

        $user->refresh();
    }
}

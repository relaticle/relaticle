<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

final readonly class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'profile_photo_path' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
        ])->validateWithBag('updateProfileInformation');

        $newPhotoPath = $input['profile_photo_path'] ?? null;

        if (filled($newPhotoPath) && $newPhotoPath !== $user->profile_photo_path) {
            $user->updateProfilePhoto($newPhotoPath);
        }

        if ($input['email'] !== $user->email) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
                ...$this->timezoneAttribute($input),
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
            ...$this->timezoneAttribute($input),
        ])->save();

        $user->sendEmailVerificationNotification();
    }

    /**
     * Clearing the select writes null — that is a deliberate "use the app default".
     * An absent key means the caller is not managing the timezone at all, so the
     * stored value is left alone.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function timezoneAttribute(array $input): array
    {
        if (! array_key_exists('timezone', $input)) {
            return [];
        }

        return ['timezone' => blank($input['timezone']) ? null : (string) $input['timezone']];
    }
}

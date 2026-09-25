<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            'profile_photo_path' => [
                'bail',
                'nullable',
                'string',
                'max:255',
                'regex:#^'.User::PROFILE_PHOTO_DIRECTORY.'/[^/]+$#',
                Rule::unique('users', 'profile_photo_path')->ignore($user->id),
                $this->rasterPhotoOnDisk($user),
            ],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
        ])->validateWithBag('updateProfileInformation');

        $this->assertEmailChangeIsVerified($user, (string) $input['email']);

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

    // The path arrives from the client, and updateProfilePhoto() later deletes whatever the
    // previous path named, so it must be a raster upload in the photo directory nobody else holds.
    private function rasterPhotoOnDisk(User $user): Closure
    {
        return function (string $attribute, string $value, Closure $fail) use ($user): void {
            if ($value === $user->profile_photo_path) {
                return;
            }

            if (! in_array(Storage::disk($user->profilePhotoDisk())->mimeType($value), User::PROFILE_PHOTO_MIME_TYPES, true)) {
                $fail('validation.image')->translate();
            }
        };
    }

    /**
     * A panel that verifies email changes owns the only legitimate path to a new
     * address: prove the mailbox, then swap. Writing the address straight through
     * here would hand any authenticated request an account takeover, since the
     * swap also clears email_verified_at and redirects the verification mail.
     */
    private function assertEmailChangeIsVerified(User $user, string $email): void
    {
        if ($email === $user->email) {
            return;
        }

        if (! Filament::getPanel('app')->hasEmailChangeVerification()) {
            return;
        }

        throw new HttpException(423, __('auth.confirm.required'));
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
     * Clearing the select writes null, a deliberate "use the app default".
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

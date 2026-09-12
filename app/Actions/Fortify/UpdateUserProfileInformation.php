<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\ChatLocales;
use Filament\Facades\Filament;
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
            'profile_photo_path' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
            'locale' => ['sometimes', 'required', 'string', Rule::in(ChatLocales::CODES)],
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
                ...$this->clearableAttribute($input, 'timezone'),
                ...$this->clearableAttribute($input, 'locale'),
            ])->save();
        }
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
            ...$this->clearableAttribute($input, 'timezone'),
            ...$this->clearableAttribute($input, 'locale'),
        ])->save();

        $user->sendEmailVerificationNotification();
    }

    /**
     * A present but empty value writes null, a deliberate "use the default". An
     * absent key means the caller is not managing the attribute, so it is left alone.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function clearableAttribute(array $input, string $key): array
    {
        if (! array_key_exists($key, $input)) {
            return [];
        }

        return [$key => blank($input[$key]) ? null : (string) $input[$key]];
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Livewire\Component;
use Livewire\WithFileUploads;

final class UpdateProfileInformationForm extends Component
{
    use WithFileUploads;

    /**
     * The state of the form.
     *
     * @var array<string, mixed>
     */
    public array $state = [];

    /**
     * The new avatar for the user.
     */
    public mixed $photo = null;

    /**
     * Determine if the verification email was sent.
     */
    public bool $verificationLinkSent = false;

    /**
     * Prepare the component.
     */
    public function mount(): void
    {
        $user = Auth::guard('web')->user();

        $this->state = array_merge([
            'email' => $user->email,
        ], $user->withoutRelations()->toArray());
    }

    /**
     * Update the user's profile information.
     */
    public function updateProfileInformation(UpdatesUserProfileInformation $updater): void
    {
        $this->resetErrorBag();

        $updater->update(
            Auth::guard('web')->user(),
            $this->photo
                ? array_merge($this->state, ['photo' => $this->photo])
                : $this->state
        );

        $this->dispatch('saved');

        $this->dispatch('refresh-navigation-menu');
    }

    /**
     * Delete user's profile photo.
     */
    public function deleteProfilePhoto(): void
    {
        Auth::guard('web')->user()->deleteProfilePhoto();

        $this->dispatch('refresh-navigation-menu');
    }

    /**
     * Sent the email verification.
     */
    public function sendEmailVerification(): void
    {
        Auth::guard('web')->user()->sendEmailVerificationNotification();

        $this->verificationLinkSent = true;
    }

    /**
     * Get the current user of the application.
     */
    #[\Livewire\Attributes\Computed]
    public function user(): Authenticatable
    {
        return Auth::guard('web')->user();
    }

    /**
     * Render the component.
     */
    public function render(): View
    {
        return view('profile.update-profile-information-form');
    }
}

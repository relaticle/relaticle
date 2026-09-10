<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\Fortify\UpdateUserProfileInformation as UpdateUserProfileInformationAction;
use App\Actions\Profile\RemoveUserProfilePhoto;
use App\Actions\Profile\RequestEmailChange;
use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\BaseLivewireComponent;
use App\Support\Auth\AuthenticationSession;
use App\Support\EmailAddress;
use App\Support\SameOriginUrl;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToCheckFileExistence;
use Throwable;

final class UpdateProfileInformation extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $data = $this->authUser()->only(['name', 'email', 'timezone']);
        $data['email'] = $this->confirmedEmailTarget() ?? $data['email'];

        $this->form->fill($data);
    }

    /**
     * IANA identifiers labelled with their current UTC offset, so the list is
     * scannable without knowing every region name by heart.
     *
     * @return array<string, string>
     */
    private function timezoneOptions(): array
    {
        $options = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $offset = Date::now($identifier)->format('P');

            $options[$identifier] = "{$identifier} (UTC{$offset})";
        }

        return $options;
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
                            ->formatStateUsing(fn () => auth('web')->user()?->profile_photo_path)
                            ->getUploadedFileUsing($this->resolveProfilePhotoUploadInfo(...)),
                        Actions::make([
                            Action::make('removeProfilePhoto')
                                ->label(__('profile.actions.remove_photo'))
                                ->color('danger')
                                ->requiresConfirmation()
                                ->visible(fn (): bool => filled($this->authUser()->profile_photo_path))
                                ->action(fn () => $this->removeProfilePhoto()),
                        ]),
                        TextInput::make('name')
                            ->label(__('profile.form.name.label'))
                            ->string()
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('email')
                            ->label(__('profile.form.email.label'))
                            ->email()
                            ->mutateStateForValidationUsing(fn (?string $state): string => EmailAddress::canonicalize((string) $state))
                            ->dehydrateStateUsing(fn (?string $state): string => EmailAddress::canonicalize((string) $state))
                            ->required()
                            ->unique(Filament::auth()->user() !== null ? Filament::auth()->user()::class : self::class, ignorable: $this->authUser()),
                        Select::make('timezone')
                            ->label(__('profile.form.timezone.label'))
                            ->helperText(__('profile.form.timezone.helper_text'))
                            ->placeholder(__('profile.form.timezone.placeholder'))
                            ->options($this->timezoneOptions())
                            ->searchable()
                            ->native(false),
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
        $newEmail = $data['email'];
        $data['email'] = $this->authUser()->email;

        resolve(UpdateUserProfileInformationAction::class)->update($this->authUser(), $data);

        if ($newEmail !== $this->authUser()->email) {
            $this->mountAction('confirmEmailChange');

            return;
        }

        $this->sendNotification();
    }

    public function confirmEmailChangeAction(): ConfirmIdentityAction
    {
        return ConfirmIdentityAction::make('confirmEmailChange')
            ->modalHeading(__('auth.confirm.heading'))
            ->modalDescription(fn (): string => __('auth.confirm.email_change_description', [
                'email' => AuthenticationSession::pendingOperation()['target_id'] ?? '',
            ]))
            ->alwaysConfirm()
            ->operation('change_email', fn (): string => EmailAddress::canonicalize((string) ($this->data['email'] ?? '')))
            ->beforeFormFilled(function (): void {
                $this->form->validate();
            })
            ->confirmedUsing(function (?string $operationTarget): void {
                $this->sendEmailChangeVerification((string) $operationTarget);
            });
    }

    public function removeProfilePhoto(): void
    {
        try {
            resolve(RemoveUserProfilePhoto::class)->execute($this->authUser());
        } catch (Throwable $e) {
            Log::withContext(['user_id' => $this->authUser()->getKey()]);

            report($e);

            Notification::make()
                ->danger()
                ->title(__('profile.notifications.photo_remove_failed'))
                ->send();

            return;
        }

        $this->form->fill([
            ...$this->authUser()->only(['name', 'email', 'timezone']),
            'profile_photo_path' => null,
        ]);

        Notification::make()
            ->success()
            ->title(__('profile.notifications.photo_removed'))
            ->send();
    }

    /**
     * Build the FilePond preview payload for an already-uploaded profile photo,
     * mirroring Filament's default getUploadedFileUsing logic but rewriting the
     * URL through SameOriginUrl so cross-subdomain previews load same-origin.
     *
     * @param  string|array<string, string>|null  $storedFileNames
     * @return array{name: string, size: int, type: string|null, url: string}|null
     */
    private function resolveProfilePhotoUploadInfo(FileUpload $component, string $file, string|array|null $storedFileNames): ?array
    {
        /** @var FilesystemAdapter $storage */
        $storage = $component->getDisk();

        $shouldFetchFileInformation = $component->shouldFetchFileInformation();

        if ($shouldFetchFileInformation) {
            try {
                if (! $storage->exists($file)) {
                    return null;
                }
            } catch (UnableToCheckFileExistence) {
                return null;
            }
        }

        $url = null;

        if ($component->getVisibility() === 'private') {
            try {
                $url = $storage->temporaryUrl(
                    $file,
                    now()->addMinutes(config('filament.temporary_file_url_expiry_minutes', 30))->endOfHour(),
                );
            } catch (Throwable) {
                // Driver does not support temporary URLs; fall back to public URL.
            }
        }

        $url ??= $storage->url($file);

        $resolvedName = $component->isMultiple()
            ? ($storedFileNames[$file] ?? null)
            : (is_string($storedFileNames) ? $storedFileNames : null);

        $mimeType = $shouldFetchFileInformation ? $storage->mimeType($file) : null;

        return [
            'name' => $resolvedName ?? basename($file),
            'size' => $shouldFetchFileInformation ? $storage->size($file) : 0,
            'type' => $mimeType === false ? null : $mimeType,
            'url' => SameOriginUrl::rewrite((string) $url),
        ];
    }

    private function sendEmailChangeVerification(string $newEmail): void
    {
        $user = $this->authUser();

        resolve(RequestEmailChange::class)->execute($user, $newEmail);

        Notification::make()
            ->success()
            ->title(__('filament-panels::auth/pages/edit-profile.notifications.email_change_verification_sent.title', ['email' => $newEmail]))
            ->body(__('filament-panels::auth/pages/edit-profile.notifications.email_change_verification_sent.body', ['email' => $newEmail]))
            ->send();

        $this->data['email'] = $user->email;
    }

    private function confirmedEmailTarget(): ?string
    {
        $operation = AuthenticationSession::pendingOperation();

        if ($operation === [] || $operation['operation'] !== 'change_email' || $operation['target_id'] === null) {
            return null;
        }

        try {
            AuthenticationSession::requireOperation($this->authUser(), 'change_email', $operation['target_id']);
        } catch (ValidationException) {
            return null;
        }

        return $operation['target_id'];
    }

    public function render(): View
    {
        return view('livewire.app.profile.update-profile-information');
    }
}

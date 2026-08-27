<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\AvatarService;
use App\Support\SameOriginUrl;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Storage;

trait HasProfilePhoto
{
    public function updateProfilePhoto(string $photo): void
    {
        tap($this->profile_photo_path, function ($previous) use ($photo): void {
            $this->forceFill(['profile_photo_path' => $photo])->save();

            if ($previous) {
                Storage::disk($this->profilePhotoDisk())->delete($previous);
            }
        });
    }

    public function deleteProfilePhoto(): void
    {
        if (is_null($this->profile_photo_path)) {
            return;
        }

        Storage::disk($this->profilePhotoDisk())->delete($this->profile_photo_path);

        $this->forceFill([
            'profile_photo_path' => null,
        ])->save();
    }

    /**
     * @return Attribute<string, never>
     */
    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::get(fn (): string => $this->profile_photo_path
            ? $this->resolveSameOriginUrl($this->profile_photo_path)
            : $this->defaultProfilePhotoUrl());
    }

    protected function defaultProfilePhotoUrl(): string
    {
        return resolve(AvatarService::class)->generateAuto($this->name);
    }

    protected function profilePhotoDisk(): string
    {
        return config('jetstream.profile_photo_disk', 'public');
    }

    protected function getAvatarAttribute(): string
    {
        return $this->getFilamentAvatarUrl();
    }

    public function getFilamentAvatarUrl(): string
    {
        return $this->profile_photo_path
            ? $this->resolveSameOriginUrl($this->profile_photo_path)
            : $this->defaultProfilePhotoUrl();
    }

    private function resolveSameOriginUrl(string $path): string
    {
        return SameOriginUrl::rewrite(
            (string) Storage::disk($this->profilePhotoDisk())->url($path)
        );
    }
}

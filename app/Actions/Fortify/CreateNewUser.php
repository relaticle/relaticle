<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Locales;
use Illuminate\Support\Str;

final readonly class CreateNewUser
{
    public function execute(string $email, string $password): User
    {
        return User::query()->create([
            'name' => $this->guessNameFromEmail($email),
            'email' => $email,
            'password' => $password,
            'locale' => Locales::fromRequest(request()),
        ]);
    }

    private function guessNameFromEmail(string $email): string
    {
        $localPart = Str::before($email, '@');
        $untaggedPart = Str::before($localPart, '+');

        return Str::title(str_replace(['.', '_'], ' ', $untaggedPart === '' ? $localPart : $untaggedPart));
    }
}

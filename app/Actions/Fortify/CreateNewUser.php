<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Str;

final readonly class CreateNewUser
{
    public function execute(string $email, string $password): User
    {
        return User::query()->create([
            'name' => $this->guessNameFromEmail($email),
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function guessNameFromEmail(string $email): string
    {
        $localPart = Str::before($email, '@');

        return Str::title(str_replace(['.', '_'], ' ', $localPart));
    }
}

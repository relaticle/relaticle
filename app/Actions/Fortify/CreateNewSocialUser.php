<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Contracts\User\CreatesNewSocialUsers;
use App\Models\User;
use Filament\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

final readonly class CreateNewSocialUser implements CreatesNewSocialUsers
{
    /**
     * Create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws Throwable
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
        ])->validate();

        return DB::transaction(fn () => tap(User::query()->create([
            'name' => $input['name'],
            'email' => $input['email'],
        ]), function (User $user): void {
            event(new Registered($user));
            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }
        }));
    }
}

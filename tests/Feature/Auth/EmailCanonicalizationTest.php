<?php

declare(strict_types=1);

use App\Casts\AsCanonicalEmail;
use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Support\Facades\DB;

mutates(EmailAddress::class, AsCanonicalEmail::class);

it('canonicalizes an email written through the model cast', function (): void {
    $user = User::factory()->create(['email' => '  Mixed.Case@Example.COM  ']);

    expect(DB::table('users')->where('id', $user->id)->value('email'))->toBe('mixed.case@example.com');
});

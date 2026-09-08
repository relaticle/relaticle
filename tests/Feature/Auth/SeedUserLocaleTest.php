<?php

declare(strict_types=1);

use App\Listeners\SeedUserLocaleListener;
use App\Models\User;
use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Http\Request;

mutates(SeedUserLocaleListener::class);

function loginWithAcceptLanguage(User $user, ?string $header): void
{
    $request = Request::create('/login', 'POST');
    $request->headers->remove('Accept-Language');

    if ($header !== null) {
        $request->headers->set('Accept-Language', $header);
    }

    app()->instance('request', $request);

    event(new LoginEvent('web', $user, false));
}

test('first login stores the primary subtag of the preferred browser language', function (): void {
    $user = User::factory()->withTeam()->create(['locale' => null]);

    loginWithAcceptLanguage($user, 'da-DK,da;q=0.9,en;q=0.8');

    expect($user->refresh()->locale)->toBe('da');
});

test('a region-tagged first language is reduced to its language', function (string $header, string $expected): void {
    $user = User::factory()->withTeam()->create(['locale' => null]);

    loginWithAcceptLanguage($user, $header);

    expect($user->refresh()->locale)->toBe($expected);
})->with([
    ['zh-CN,zh;q=0.9', 'zh'],
    ['pt-BR', 'pt'],
    ['EN-us', 'en'],
]);

test('a stored locale is never overwritten by a later login', function (): void {
    $user = User::factory()->withTeam()->create(['locale' => 'fr']);

    loginWithAcceptLanguage($user, 'da-DK');

    expect($user->refresh()->locale)->toBe('fr');
});

test('a wildcard, malformed, missing or unsupported header leaves the column null', function (?string $header): void {
    $user = User::factory()->withTeam()->create(['locale' => null]);

    loginWithAcceptLanguage($user, $header);

    expect($user->refresh()->locale)->toBeNull();
})->with([
    ['*'],
    ['garbage value here'],
    [''],
    [null],
    ['ne-NP,ne;q=0.9'],
    ['ja'],
]);

test('logging in through the login endpoint seeds the locale from the request', function (): void {
    $user = User::factory()->withTeam()->create(['locale' => null]);

    $this->withHeader('Accept-Language', 'da-DK')
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticated();

    expect($user->refresh()->locale)->toBe('da');
});

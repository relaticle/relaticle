<?php

declare(strict_types=1);

use App\Rules\ValidWorkspaceSlug;
use Illuminate\Support\Facades\Validator;

it('rejects slugs shorter than 3 characters', function (string $slug) {
    $validator = Validator::make(
        ['slug' => $slug],
        ['slug' => [new ValidWorkspaceSlug]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('slug'))->toBe('The slug must be at least 3 characters.');
})->with([
    'ab',
    'a',
]);

it('rejects invalid slug format', function (string $slug) {
    $validator = Validator::make(
        ['slug' => $slug],
        ['slug' => [new ValidWorkspaceSlug]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('slug'))->toBe('The slug may only contain lowercase letters, numbers, and hyphens.');
})->with([
    'has spaces',
    'has@special',
    'HAS-UPPERCASE',
    'has_underscore',
    '-starts-with-hyphen',
    'ends-with-hyphen-',
]);

it('rejects reserved slugs', function (string $slug) {
    $validator = Validator::make(
        ['slug' => $slug],
        ['slug' => [new ValidWorkspaceSlug]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('slug'))->toBe('The slug is reserved and cannot be used.');
})->with([
    'login',
    'admin',
    'settings',
    'api',
    'billing',
    'workspaces',
    'dashboard',
    'companies',
    'people',
    'tasks',
]);

it('allows valid non-reserved slugs', function (string $slug) {
    $validator = Validator::make(
        ['slug' => $slug],
        ['slug' => [new ValidWorkspaceSlug]],
    );

    expect($validator->passes())->toBeTrue();
})->with([
    'acme-corp',
    'my-workspace',
    'relaticle',
    'team123',
]);

it('allows reserved slug when it matches the ignored value', function () {
    $validator = Validator::make(
        ['slug' => 'admin'],
        ['slug' => [new ValidWorkspaceSlug(ignoreValue: 'admin')]],
    );

    expect($validator->passes())->toBeTrue();
});

it('still rejects different reserved slug even with ignored value', function () {
    $validator = Validator::make(
        ['slug' => 'login'],
        ['slug' => [new ValidWorkspaceSlug(ignoreValue: 'admin')]],
    );

    expect($validator->fails())->toBeTrue();
});

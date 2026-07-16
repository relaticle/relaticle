<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Relaticle\Chat\Support\RecordReferenceResolver;

mutates(RecordReferenceResolver::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->switchTeam($this->user->ownedTeams()->first());
    $this->actingAs($this->user);
    // Deliberately do NOT call Filament::setTenant() — this mirrors the queued job context
});

it('resolves a company URL without Filament tenant bound, using auth user currentTeam', function (): void {
    $company = Company::factory()->for($this->user->currentTeam)->create(['name' => 'Acme Corp']);

    $resolver = resolve(RecordReferenceResolver::class);
    $ref = $resolver->resolve('company', (string) $company->getKey());

    expect($ref)->not->toBeNull()
        ->and($ref['url'])->toBeString()
        ->and($ref['url'])->toContain('/companies/')
        ->and($ref['label'])->toBe('Acme Corp');
});

it('resolves a people URL without Filament tenant bound', function (): void {
    $person = People::factory()->for($this->user->currentTeam)->create(['name' => 'Jane Smith']);

    $ref = resolve(RecordReferenceResolver::class)->resolve('people', (string) $person->getKey());

    expect($ref)->not->toBeNull()
        ->and($ref['url'])->toContain('/people/');
});

it('resolves an opportunity URL without Filament tenant bound', function (): void {
    $opportunity = Opportunity::factory()->for($this->user->currentTeam)->create(['name' => 'Big Deal']);

    $ref = resolve(RecordReferenceResolver::class)->resolve('opportunity', (string) $opportunity->getKey());

    expect($ref)->not->toBeNull()
        ->and($ref['url'])->toContain('/opportunities/');
});

it('resolves task to a record edit deep-link without Filament tenant bound', function (): void {
    $ref = resolve(RecordReferenceResolver::class)->resolve('task', 'any-id');

    expect($ref)->not->toBeNull()
        ->and($ref['url'])->toBeString()
        ->and($ref['url'])->toContain('/tasks')
        ->and($ref['url'])->toContain('tableAction=edit')
        ->and($ref['url'])->toContain('tableActionRecord=any-id');
});

it('resolves note to a record edit deep-link without Filament tenant bound', function (): void {
    $ref = resolve(RecordReferenceResolver::class)->resolve('note', 'any-id');

    expect($ref)->not->toBeNull()
        ->and($ref['url'])->toBeString()
        ->and($ref['url'])->toContain('/notes')
        ->and($ref['url'])->toContain('tableAction=edit')
        ->and($ref['url'])->toContain('tableActionRecord=any-id');
});

it('returns null when the user has no current team', function (): void {
    // Remove from session so currentTeam is null
    auth()->logout();

    $resolver = resolve(RecordReferenceResolver::class);
    $ref = $resolver->resolve('company', 'some-id');

    expect($ref)->toBeNull();
});

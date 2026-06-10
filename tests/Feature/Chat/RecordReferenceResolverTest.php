<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\Chat\Support\RecordReferenceResolver;

it('resolves a people record reference to id, type, and url', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    $person = People::factory()->for($user->currentTeam)->create(['name' => 'Angel']);

    $resolver = resolve(RecordReferenceResolver::class);
    $ref = $resolver->resolve('people', (string) $person->getKey());

    expect($ref)->toMatchArray([
        'id' => (string) $person->getKey(),
        'type' => 'people',
        'url' => PeopleResource::getUrl('view', ['record' => (string) $person->getKey()]),
    ]);
});

it('returns null for unknown entity types', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    expect(resolve(RecordReferenceResolver::class)->resolve('unknown', 'whatever'))->toBeNull();
});

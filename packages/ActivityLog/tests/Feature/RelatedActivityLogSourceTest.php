<?php

declare(strict_types=1);

use Relaticle\ActivityLog\Tests\Fixtures\Models\Email;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Note;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;
use Relaticle\ActivityLog\Timeline\Sources\RelatedActivityLogSource;
use Relaticle\ActivityLog\Timeline\Window;

it('returns activity entries for related model rows', function (): void {
    $person = Person::factory()->create();
    $email = Email::factory()->for($person)->create();
    $note = Note::factory()->for($person)->create();

    $source = new RelatedActivityLogSource(priority: 10, relations: ['emails', 'notes']);
    $entries = collect($source->resolve($person, new Window(cap: 100)));

    expect($entries)->toHaveCount(2);
    expect($entries->pluck('relatedModel.id')->sort()->values()->all())
        ->toBe([$email->id, $note->id]);
});

it('uses related_class:related_id:second dedup key', function (): void {
    $person = Person::factory()->create();
    $email = Email::factory()->for($person)->create();

    $source = new RelatedActivityLogSource(priority: 10, relations: ['emails']);
    $entry = collect($source->resolve($person, new Window(cap: 10)))->first();

    expect($entry->dedupKey)->toStartWith(Email::class.':'.$email->id.':');
});

it('throws on unknown relation', function (): void {
    $person = Person::factory()->create();
    $source = new RelatedActivityLogSource(priority: 10, relations: ['unknown']);

    expect(fn (): array => iterator_to_array($source->resolve($person, new Window(cap: 10))))
        ->toThrow(InvalidArgumentException::class);
});

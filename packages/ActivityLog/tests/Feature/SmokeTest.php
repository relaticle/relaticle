<?php

declare(strict_types=1);

use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;

it('boots testbench with fixture models', function (): void {
    $person = Person::factory()->create();
    expect($person->id)->toBeInt();
});

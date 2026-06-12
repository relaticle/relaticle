<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('has team_id column on agent_conversations', function (): void {
    expect(Schema::hasColumn('agent_conversations', 'team_id'))->toBeTrue();
});

it('indexes team_id + user_id + updated_at on agent_conversations', function (): void {
    $indexes = collect(Schema::getIndexes('agent_conversations'))
        ->pluck('columns')
        ->map(fn (array $cols): array => array_values($cols));

    expect($indexes)->toContain(['team_id', 'user_id', 'updated_at']);
});

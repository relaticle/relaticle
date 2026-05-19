<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates the passkeys table with expected columns', function (): void {
    expect(Schema::hasTable('passkeys'))->toBeTrue()
        ->and(Schema::hasColumns('passkeys', [
            'id', 'user_id', 'name', 'credential_id', 'credential', 'last_used_at', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

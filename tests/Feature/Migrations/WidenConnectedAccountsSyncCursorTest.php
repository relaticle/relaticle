<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

test('a Microsoft Graph delta cursor longer than 255 characters persists', function (): void {
    DB::table('migrations')->where('migration', '2026_09_22_000000_widen_connected_accounts_sync_cursor')->delete();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $cursor = json_encode([
        'v' => 1,
        'cursors' => [
            'inbox' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken='.str_repeat('a', 240),
            'sentitems' => 'https://graph.microsoft.com/v1.0/me/mailFolders/sentitems/messages/delta?$deltatoken='.str_repeat('b', 240),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create(['sync_cursor' => $cursor]));

    expect(strlen($cursor))->toBeGreaterThan(255)
        ->and($account->fresh()?->sync_cursor)->toBe($cursor)
        ->and(collect(Schema::getColumns('connected_accounts'))->firstWhere('name', 'sync_cursor')['type'])->toBe('text');
});

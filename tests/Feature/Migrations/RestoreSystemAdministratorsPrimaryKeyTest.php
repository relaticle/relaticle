<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

test('upgrades administrators without a primary key before creating staff passkeys', function (): void {
    $administrator = SystemAdministrator::factory()->create();
    $attributes = $administrator->refresh()->getRawOriginal();

    Schema::drop('system_administrator_passkeys');
    Schema::table('system_administrators', function (Blueprint $table): void {
        $table->dropPrimary();
    });

    DB::table('migrations')->whereIn('migration', [
        '2026_09_16_094741_restore_system_administrators_primary_key',
        '2026_09_16_094742_create_system_administrator_passkeys_table',
    ])->delete();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect($administrator->fresh()->getRawOriginal())->toBe($attributes);

    $passkeyId = DB::table('system_administrator_passkeys')->insertGetId([
        'user_id' => $administrator->id,
        'name' => 'Staff security key',
        'credential_id' => 'staff-credential',
        'credential' => '{}',
    ]);

    DB::table('system_administrators')->where('id', $administrator->id)->delete();

    $this->assertDatabaseMissing('system_administrator_passkeys', ['id' => $passkeyId]);
});

test('preserves existing administrators and passkeys when the primary key already exists', function (): void {
    $administrator = SystemAdministrator::factory()->create();
    $attributes = $administrator->refresh()->getRawOriginal();
    $indexes = Schema::getIndexes('system_administrators');
    $passkey = [
        'user_id' => $administrator->id,
        'name' => 'Staff security key',
        'credential_id' => 'staff-credential',
        'credential' => '{}',
    ];
    $passkeyId = DB::table('system_administrator_passkeys')->insertGetId($passkey);
    DB::table('migrations')->where('migration', '2026_09_16_094741_restore_system_administrators_primary_key')->delete();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect($administrator->fresh()->getRawOriginal())->toBe($attributes)
        ->and(Schema::getIndexes('system_administrators'))->toBe($indexes);
    expect((array) DB::table('system_administrator_passkeys')->where('id', $passkeyId)->first(array_keys($passkey)))
        ->toBe($passkey);
});

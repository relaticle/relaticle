<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('widens media owners without changing existing rows or nullability', function (?string $ownerId, bool $nullable): void {
    Schema::table('media', function (Blueprint $table) use ($nullable): void {
        $table->char('model_id', 26)->nullable($nullable)->change();
    });

    $mediaId = DB::table('media')->insertGetId([
        'model_type' => 'workspace',
        'model_id' => $ownerId,
        'collection_name' => 'logo',
        'name' => 'Workspace logo',
        'file_name' => 'logo.png',
        'disk' => 'public',
        'size' => 100,
        'manipulations' => '{}',
        'custom_properties' => '{}',
        'generated_conversions' => '{}',
        'responsive_images' => '{}',
    ]);
    $media = (array) DB::table('media')->find($mediaId);
    $indexes = Schema::getIndexes('media');
    DB::table('migrations')->where('migration', '2026_09_16_100000_widen_media_model_id_for_uuid_owners')->delete();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect((array) DB::table('media')->find($mediaId))->toBe($media)
        ->and(Schema::getIndexes('media'))->toBe($indexes)
        ->and(collect(Schema::getColumns('media'))->firstWhere('name', 'model_id')['nullable'])->toBe($nullable);

    $conversationId = 'b4e3e885-5f1a-4d16-bbd0-659c3cd27de4';
    DB::table('media')->where('id', $mediaId)->update([
        'model_type' => 'agent_conversation',
        'model_id' => $conversationId,
    ]);

    $this->assertDatabaseHas('media', ['id' => $mediaId, 'model_id' => $conversationId]);
})->with([
    'legacy null owner' => [null, true],
    'legacy ULID owner' => ['01K00000000000000000000000', true],
    'fresh ULID owner' => ['01K00000000000000000000000', false],
]);

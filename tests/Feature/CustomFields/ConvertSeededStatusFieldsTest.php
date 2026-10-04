<?php

declare(strict_types=1);

use App\Actions\CustomFields\ConvertSeededStatusFields;
use App\Console\Commands\BackfillCustomFieldCategoriesCommand;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Enums\OptionCategory;

mutates(ConvertSeededStatusFields::class, BackfillCustomFieldCategoriesCommand::class);

function runConvertSeededStatusFieldsMigration(): void
{
    resolve(ConvertSeededStatusFields::class)->execute();
}

function seedThreeXStatusFields(): Workspace
{
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;

    $fieldIds = DB::table('custom_fields')
        ->where('tenant_id', $workspace->id)
        ->whereIn('entity_type', ['task', 'opportunity'])
        ->whereIn('code', ['status', 'stage'])
        ->pluck('id');

    DB::table('custom_fields')->whereIn('id', $fieldIds)->update(['type' => 'select']);

    DB::table('custom_field_options')
        ->whereIn('custom_field_id', $fieldIds)
        ->update(['settings' => DB::raw("(settings::jsonb - 'category')::json")]);

    return $workspace;
}

function statusOption(Workspace $workspace, string $entityType, string $code, string $name): object
{
    $option = DB::table('custom_field_options')
        ->whereIn('custom_field_id', DB::table('custom_fields')
            ->where('tenant_id', $workspace->id)
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->select('id'))
        ->where('name', $name)
        ->first();

    throw_if($option === null, RuntimeException::class, "option {$name} missing");

    return $option;
}

function fieldType(Workspace $workspace, string $entityType, string $code): string
{
    return (string) DB::table('custom_fields')
        ->where('tenant_id', $workspace->id)
        ->where('entity_type', $entityType)
        ->where('code', $code)
        ->value('type');
}

it('converts a 3.x seeded workspace to the status type and categorises its options', function (): void {
    $workspace = seedThreeXStatusFields();

    expect(fieldType($workspace, 'task', 'status'))->toBe('select');

    runConvertSeededStatusFieldsMigration();

    expect(fieldType($workspace, 'task', 'status'))->toBe('status')
        ->and(fieldType($workspace, 'opportunity', 'stage'))->toBe('status');

    $toDo = statusOption($workspace, 'task', 'status', 'To do');
    $done = statusOption($workspace, 'task', 'status', 'Done');
    $won = statusOption($workspace, 'opportunity', 'stage', 'Closed Won');
    $lost = statusOption($workspace, 'opportunity', 'stage', 'Closed Lost');

    expect(json_decode((string) $toDo->settings, true))
        ->toBe(['color' => '#c4b5fd', 'category' => OptionCategory::Unstarted->value])
        ->and(json_decode((string) $done->settings, true)['category'])->toBe(OptionCategory::Completed->value)
        ->and(json_decode((string) $won->settings, true)['category'])->toBe(OptionCategory::Completed->value)
        ->and(json_decode((string) $lost->settings, true)['category'])->toBe(OptionCategory::Cancelled->value);
});

it('leaves an already converted workspace untouched', function (): void {
    $workspace = seedThreeXStatusFields();

    runConvertSeededStatusFieldsMigration();

    $before = statusOption($workspace, 'task', 'status', 'Done');

    runConvertSeededStatusFieldsMigration();

    $after = statusOption($workspace, 'task', 'status', 'Done');

    expect(fieldType($workspace, 'task', 'status'))->toBe('status')
        ->and($after->settings)->toBe($before->settings)
        ->and($after->updated_at)->toBe($before->updated_at);
});

it('leaves a renamed option uncategorised and reports it', function (): void {
    $workspace = seedThreeXStatusFields();

    DB::table('custom_field_options')
        ->where('id', statusOption($workspace, 'task', 'status', 'Done')->id)
        ->update(['name' => 'Finished']);

    $summaries = app(ConvertSeededStatusFields::class)->execute((string) $workspace->id);

    $finished = statusOption($workspace, 'task', 'status', 'Finished');

    expect(json_decode((string) $finished->settings, true))->toBe(['color' => '#2A9764'])
        ->and($summaries[$workspace->id]['unmatched'])->toBe(['status: Finished'])
        ->and($summaries[$workspace->id]['categorised'])->toBe(12);
});

it('writes nothing on a dry run and converts on a real run', function (): void {
    $workspace = seedThreeXStatusFields();

    $this->artisan('custom-fields:backfill-categories', ['--workspace' => $workspace->id, '--dry-run' => true])
        ->assertSuccessful();

    expect(fieldType($workspace, 'task', 'status'))->toBe('select')
        ->and(json_decode((string) statusOption($workspace, 'task', 'status', 'Done')->settings, true))
        ->toBe(['color' => '#2A9764']);

    $this->artisan('custom-fields:backfill-categories', ['--workspace' => $workspace->id])
        ->assertSuccessful();

    expect(fieldType($workspace, 'task', 'status'))->toBe('status')
        ->and(json_decode((string) statusOption($workspace, 'task', 'status', 'Done')->settings, true)['category'])
        ->toBe(OptionCategory::Completed->value);
});

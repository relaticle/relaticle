<?php

declare(strict_types=1);

use App\Actions\CustomFields\ConvertSeededStatusFields;
use App\Console\Commands\BackfillCustomFieldCategoriesCommand;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Enums\OptionCategory;

mutates(ConvertSeededStatusFields::class, BackfillCustomFieldCategoriesCommand::class);

function runConvertSeededStatusFieldsMigration(): void
{
    $migration = require database_path('migrations/2026_09_06_120000_convert_seeded_status_fields_to_status_type.php');
    $migration->up();
}

function seedThreeXStatusFields(): Team
{
    $team = User::factory()->withTeam()->create()->currentTeam;

    $fieldIds = DB::table('custom_fields')
        ->where('tenant_id', $team->id)
        ->whereIn('entity_type', ['task', 'opportunity'])
        ->whereIn('code', ['status', 'stage'])
        ->pluck('id');

    DB::table('custom_fields')->whereIn('id', $fieldIds)->update(['type' => 'select']);

    DB::table('custom_field_options')
        ->whereIn('custom_field_id', $fieldIds)
        ->update(['settings' => DB::raw("(settings::jsonb - 'category')::json")]);

    return $team;
}

function statusOption(Team $team, string $entityType, string $code, string $name): object
{
    $option = DB::table('custom_field_options')
        ->whereIn('custom_field_id', DB::table('custom_fields')
            ->where('tenant_id', $team->id)
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->select('id'))
        ->where('name', $name)
        ->first();

    throw_if($option === null, RuntimeException::class, "option {$name} missing");

    return $option;
}

function fieldType(Team $team, string $entityType, string $code): string
{
    return (string) DB::table('custom_fields')
        ->where('tenant_id', $team->id)
        ->where('entity_type', $entityType)
        ->where('code', $code)
        ->value('type');
}

it('converts a 3.x seeded workspace to the status type and categorises its options', function (): void {
    $team = seedThreeXStatusFields();

    expect(fieldType($team, 'task', 'status'))->toBe('select');

    runConvertSeededStatusFieldsMigration();

    expect(fieldType($team, 'task', 'status'))->toBe('status')
        ->and(fieldType($team, 'opportunity', 'stage'))->toBe('status');

    $toDo = statusOption($team, 'task', 'status', 'To do');
    $done = statusOption($team, 'task', 'status', 'Done');
    $won = statusOption($team, 'opportunity', 'stage', 'Closed Won');
    $lost = statusOption($team, 'opportunity', 'stage', 'Closed Lost');

    expect(json_decode((string) $toDo->settings, true))
        ->toBe(['color' => '#c4b5fd', 'category' => OptionCategory::Unstarted->value])
        ->and(json_decode((string) $done->settings, true)['category'])->toBe(OptionCategory::Completed->value)
        ->and(json_decode((string) $won->settings, true)['category'])->toBe(OptionCategory::Completed->value)
        ->and(json_decode((string) $lost->settings, true)['category'])->toBe(OptionCategory::Cancelled->value);
});

it('leaves an already converted workspace untouched', function (): void {
    $team = seedThreeXStatusFields();

    runConvertSeededStatusFieldsMigration();

    $before = statusOption($team, 'task', 'status', 'Done');

    runConvertSeededStatusFieldsMigration();

    $after = statusOption($team, 'task', 'status', 'Done');

    expect(fieldType($team, 'task', 'status'))->toBe('status')
        ->and($after->settings)->toBe($before->settings)
        ->and($after->updated_at)->toBe($before->updated_at);
});

it('leaves a renamed option uncategorised and reports it', function (): void {
    $team = seedThreeXStatusFields();

    DB::table('custom_field_options')
        ->where('id', statusOption($team, 'task', 'status', 'Done')->id)
        ->update(['name' => 'Finished']);

    $summaries = app(ConvertSeededStatusFields::class)->execute((string) $team->id);

    $finished = statusOption($team, 'task', 'status', 'Finished');

    expect(json_decode((string) $finished->settings, true))->toBe(['color' => '#2A9764'])
        ->and($summaries[$team->id]['unmatched'])->toBe(['status: Finished'])
        ->and($summaries[$team->id]['categorised'])->toBe(12);
});

it('writes nothing on a dry run and converts on a real run', function (): void {
    $team = seedThreeXStatusFields();

    $this->artisan('custom-fields:backfill-categories', ['--team' => $team->id, '--dry-run' => true])
        ->assertSuccessful();

    expect(fieldType($team, 'task', 'status'))->toBe('select')
        ->and(json_decode((string) statusOption($team, 'task', 'status', 'Done')->settings, true))
        ->toBe(['color' => '#2A9764']);

    $this->artisan('custom-fields:backfill-categories', ['--team' => $team->id])
        ->assertSuccessful();

    expect(fieldType($team, 'task', 'status'))->toBe('status')
        ->and(json_decode((string) statusOption($team, 'task', 'status', 'Done')->settings, true)['category'])
        ->toBe(OptionCategory::Completed->value);
});

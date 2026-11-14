<?php

declare(strict_types=1);

use App\Filament\Components\Forms\MergeRecordPicker;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Tests\Helpers\WorkspaceCustomField;

function mergeRecordAction(): TestAction
{
    return TestAction::make('mergeRecord')->schemaComponent('recordActions', schema: 'infolist');
}

/**
 * @param  class-string  $page
 */
function mergeSearch(string $page, Company|People $kept, string $search): Testable
{
    $livewire = livewire($page, ['record' => $kept->getKey()])->mountAction(mergeRecordAction());
    $instance = $livewire->instance();
    $picker = $instance->getSchema($instance->getMountedActionSchemaName())
        ->getComponent(fn (Component $component): bool => $component instanceof MergeRecordPicker);

    return $livewire->call('callSchemaComponentMethod', $picker->getKey(), 'searchRecords', ['search' => $search]);
}

/**
 * @param  class-string  $page
 * @return list<string>
 */
function mergeSearchNames(string $page, Company|People $kept, string $search): array
{
    return array_column(mergeSearch($page, $kept, $search)->effects['returns'][0], 'name');
}

function mergeCompanyRecord(Workspace $workspace, User $owner, array $attributes = [], array $customFields = []): Company
{
    $company = Company::factory()->recycle([$owner, $workspace])->create($attributes);

    foreach ($customFields as $code => $value) {
        $company->saveCustomFieldValue(WorkspaceCustomField::byCode($workspace->getKey(), 'company', $code), $value);
    }

    return $company;
}

function mergePersonRecord(Workspace $workspace, User $owner, array $attributes = [], array $customFields = []): People
{
    $person = People::factory()->recycle([$owner, $workspace])->create($attributes);

    foreach ($customFields as $code => $value) {
        $person->saveCustomFieldValue(WorkspaceCustomField::byCode($workspace->getKey(), 'people', $code), $value);
    }

    return $person;
}

function mergeLinkedIds(string $table, string $itemColumn, Model $record): array
{
    return DB::table($table)
        ->where(Str::singular($table).'_type', $record->getMorphClass())
        ->where(Str::singular($table).'_id', $record->getKey())
        ->pluck($itemColumn)
        ->sort()
        ->values()
        ->all();
}

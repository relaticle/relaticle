<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\WorkspaceCustomField;

function mergeRecordAction(): TestAction
{
    return TestAction::make('mergeRecord')->schemaComponent('recordActions', schema: 'infolist');
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

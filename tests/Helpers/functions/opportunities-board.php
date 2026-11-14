<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\TablePreference;
use App\Models\User;
use Illuminate\Support\Once;
use Livewire\Features\SupportTesting\Testable;

function opportunityInStage(object $test, string $name, string $stage, ?Company $company = null): Opportunity
{
    $opportunity = Opportunity::factory()->recycle([$test->user, $test->workspace])->create(['name' => $name, 'company_id' => $company?->getKey()]);
    $opportunity->saveCustomFieldValue($test->stageField, $test->stageField->options->firstWhere('name', $stage)->getKey());

    return $opportunity;
}

function opportunityStageCardIds(Testable $component, string $stage): array
{
    $option = CustomFieldOption::query()->withoutGlobalScopes()->where('name', $stage)->firstOrFail();

    return $component->instance()->getBoard()->getBoardRecords((string) $option->getKey())->pluck('id')->all();
}

/**
 * @return list<string>
 */
function stageIds(CustomField $field): array
{
    return CustomFieldOption::query()->withoutGlobalScopes()->where('custom_field_id', $field->getKey())->orderBy('sort_order')->pluck('id')->map(strval(...))->all();
}

/**
 * @return list<string>
 */
function boardColumnOrder(Testable $component): array
{
    return $component->instance()->getBoard()->getColumnIdentifiers();
}

function freshOpportunitiesBoard(): Testable
{
    Once::flush();

    return livewire(OpportunitiesBoard::class);
}

function opportunitySelectField(object $test, string $name, array $options): CustomField
{
    return resolve(CreateCustomField::class)->execute($test->user, ['name' => $name, 'type' => 'select', 'entity_type' => 'opportunity', 'options' => array_map(fn (string $option): array => ['name' => $option], $options)]);
}

function rememberedBoard(object $test, CrmEntity $entity, BoardStateData $state, ?User $user = null): TablePreference
{
    return TablePreference::factory()->create([
        'user_id' => ($user ?? $test->user)->getKey(),
        'workspace_id' => $test->workspace->getKey(),
        'entity' => $entity,
        'board_state' => $state,
    ]);
}

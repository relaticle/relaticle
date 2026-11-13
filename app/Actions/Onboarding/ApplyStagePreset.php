<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\CustomFields\OpportunityField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\Workspace;
use App\Onboarding\FieldOptionRows;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Facades\Entities;
use Relaticle\CustomFields\Services\Visibility\BackendVisibilityService;

final readonly class ApplyStagePreset
{
    /**
     * @param  array<string, string>  $preset
     */
    public function execute(Workspace $workspace, array $preset): void
    {
        $entityType = Entities::getEntity(Opportunity::class)?->getAlias() ?? Opportunity::class;

        $stage = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspace->getKey())
            ->where('entity_type', $entityType)
            ->where('code', OpportunityField::STAGE->value)
            ->first();

        if (! $stage instanceof CustomField) {
            return;
        }

        if (Opportunity::query()->withoutGlobalScopes()->where('workspace_id', $workspace->getKey())->exists()) {
            return;
        }

        // insert() and a builder delete fire no model events: a preset is a default, not an edit.
        DB::transaction(function () use ($workspace, $stage, $preset): void {
            CustomFieldOption::query()->withoutGlobalScopes()->where('custom_field_id', $stage->getKey())->delete();

            CustomFieldOption::query()->insert(FieldOptionRows::build(
                (string) $workspace->getKey(),
                (string) $stage->getKey(),
                (string) $stage->getRawOriginal('type'),
                array_keys($preset),
                $preset,
                now(),
            ));
        });

        // Builder writes fire no option events, and those are what clear the schema caches.
        $stage->touch();

        BackendVisibilityService::clearCache($entityType);
    }
}

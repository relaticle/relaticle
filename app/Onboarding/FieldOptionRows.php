<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Enums\CustomFieldType;
use App\Models\CustomFieldOption;
use Carbon\CarbonImmutable;
use Relaticle\CustomFields\Data\CustomFieldOptionSettingsData;
use Relaticle\CustomFields\Enums\OptionCategory;
use Relaticle\CustomFields\Exceptions\FieldTypeNotOptionableException;

final readonly class FieldOptionRows
{
    /**
     * @param  array<int|string, string>  $names
     * @param  array<string, string>  $colors
     * @param  array<string, OptionCategory>  $categories
     * @return list<array<string, mixed>>
     */
    public static function build(string $workspaceId, string $fieldId, string $fieldType, array $names, array $colors, array $categories, CarbonImmutable $now): array
    {
        if ($names === []) {
            return [];
        }

        throw_unless(CustomFieldType::from($fieldType)->isChoice(), FieldTypeNotOptionableException::class);

        $rows = [];

        foreach ($names as $sortOrder => $name) {
            $rows[] = [
                'id' => (new CustomFieldOption)->newUniqueId(),
                'custom_field_id' => $fieldId,
                'tenant_id' => $workspaceId,
                'name' => $name,
                'sort_order' => $sortOrder,
                'settings' => isset($colors[$name]) || isset($categories[$name])
                    ? json_encode(new CustomFieldOptionSettingsData(
                        color: $colors[$name] ?? null,
                        category: $categories[$name] ?? null,
                    ))
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }
}

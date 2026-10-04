<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Relaticle\CustomFields\CustomFields;
use Relaticle\CustomFields\Enums\CustomFieldsFeature;
use Relaticle\CustomFields\FeatureSystem\FeatureManager;
use Relaticle\CustomFields\Support\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        if (! FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_RELATIONSHIPS)) {
            return;
        }

        Schema::create(config('custom-fields.database.table_names.custom_field_relationships'), function (Blueprint $table): void {
            $uniqueColumns = ['code'];

            KeyType::primary($table);

            if (FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_MULTI_TENANCY)) {
                $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

                KeyType::foreign($table, $tenantKey)->nullable()->index();

                $uniqueColumns[] = $tenantKey;
            }

            $table->string('code');
            $table->string('from_entity_type');
            $table->string('to_entity_type');
            $table->string('cardinality');

            // Slot keys follow the swapped CustomField model, not database.key_type: a host can
            // run ULID custom fields while the tables added in 4.0 stay on bigint.
            $customFields = config('custom-fields.database.table_names.custom_fields');

            $table->foreignIdFor(CustomFields::customFieldModel(), 'from_field_id')
                ->nullable()
                ->unique()
                ->constrained($customFields)
                ->nullOnDelete();

            $table->foreignIdFor(CustomFields::customFieldModel(), 'to_field_id')
                ->nullable()
                ->unique()
                ->constrained($customFields)
                ->nullOnDelete();

            $table->boolean('is_symmetric')->default(false);

            $table->timestamps();

            $table->unique($uniqueColumns);
        });
    }
};

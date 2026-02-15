<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Relaticle\CustomFields\Enums\CustomFieldsFeature;
use Relaticle\CustomFields\FeatureSystem\FeatureManager;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Custom Field Sections
         */
        Schema::create(config('custom-fields.database.table_names.custom_field_sections'), function (Blueprint $table): void {
            $uniqueColumns = ['entity_type', 'code'];

            $table->ulid('id')->primary();

            if (FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_MULTI_TENANCY)) {
                $table->foreignUlid(config('custom-fields.database.column_names.tenant_foreign_key'))->nullable()->index();
                $uniqueColumns[] = config('custom-fields.database.column_names.tenant_foreign_key');
            }

            $table->string('width')->nullable();

            $table->string('code');
            $table->string('name');
            $table->string('type');
            $table->string('entity_type');
            $table->unsignedBigInteger('sort_order')->nullable();

            $table->string('description')->nullable();

            $table->boolean('active')->default(true);
            $table->boolean('system_defined')->default(false);

            $table->json('settings')->nullable();

            $table->unique($uniqueColumns);

            if (FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_MULTI_TENANCY)) {
                $table->index([config('custom-fields.database.column_names.tenant_foreign_key'), 'entity_type', 'active'], 'custom_field_sections_tenant_entity_active_idx');
            } else {
                $table->index(['entity_type', 'active'], 'custom_field_sections_entity_active_idx');
            }

            $table->timestamps();
        });

        /**
         * Custom Fields
         */
        Schema::create(config('custom-fields.database.table_names.custom_fields'), function (Blueprint $table): void {
            $uniqueColumns = ['code', 'entity_type'];

            $table->ulid('id')->primary();

            $table->foreignUlid('custom_field_section_id')->nullable();
            $table->string('width')->nullable();

            if (FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_MULTI_TENANCY)) {
                $table->foreignUlid(config('custom-fields.database.column_names.tenant_foreign_key'))->nullable()->index();
                $uniqueColumns[] = config('custom-fields.database.column_names.tenant_foreign_key');
            }

            $table->string('code');
            $table->string('name');
            $table->string('type');
            $table->string('lookup_type')->nullable();
            $table->string('entity_type');
            $table->unsignedBigInteger('sort_order')->nullable();
            $table->json('validation_rules')->nullable();

            $table->boolean('active')->default(true);
            $table->boolean('system_defined')->default(false);

            $table->json('settings')->nullable();

            $table->unique($uniqueColumns);

            if (FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_MULTI_TENANCY)) {
                $table->index([config('custom-fields.database.column_names.tenant_foreign_key'), 'entity_type', 'active'], 'custom_fields_tenant_entity_active_idx');
            } else {
                $table->index(['entity_type', 'active'], 'custom_fields_entity_active_idx');
            }

            $table->timestamps();
        });

        /**
         * Custom Field Options
         */
        Schema::create(config('custom-fields.database.table_names.custom_field_options'), function (Blueprint $table): void {
            $uniqueColumns = ['custom_field_id', 'name'];

            $table->ulid('id')->primary();

            if (FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_MULTI_TENANCY)) {
                $table->foreignUlid(config('custom-fields.database.column_names.tenant_foreign_key'))->nullable()->index();
                $uniqueColumns[] = config('custom-fields.database.column_names.tenant_foreign_key');
            }

            $table->foreignUlid('custom_field_id')
                ->constrained(config('custom-fields.database.table_names.custom_fields'))
                ->cascadeOnDelete();

            $table->string('name')->nullable();
            $table->unsignedBigInteger('sort_order')->nullable();
            $table->json('settings')->nullable();

            $table->timestamps();

            $table->unique($uniqueColumns);
        });

        /**
         * Custom Field Values
         */
        Schema::create(config('custom-fields.database.table_names.custom_field_values'), function (Blueprint $table): void {
            $uniqueColumns = ['entity_type', 'entity_id', 'custom_field_id'];

            $table->ulid('id')->primary();

            if (FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_MULTI_TENANCY)) {
                $table->foreignUlid(config('custom-fields.database.column_names.tenant_foreign_key'))->nullable()->index();
                $uniqueColumns[] = config('custom-fields.database.column_names.tenant_foreign_key');
            }

            $table->ulidMorphs('entity');
            $table->foreignUlid('custom_field_id')
                ->constrained(config('custom-fields.database.table_names.custom_fields'))
                ->cascadeOnDelete();

            $table->text('string_value')->nullable();
            $table->longText('text_value')->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->bigInteger('integer_value')->nullable();
            $table->double('float_value')->nullable();
            $table->date('date_value')->nullable();
            $table->dateTime('datetime_value')->nullable();
            $table->json('json_value')->nullable();

            $table->unique($uniqueColumns, 'custom_field_values_entity_type_unique');

            if (FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_MULTI_TENANCY)) {
                $table->index([config('custom-fields.database.column_names.tenant_foreign_key'), 'entity_type', 'entity_id'], 'custom_field_values_tenant_entity_idx');
            } else {
                $table->index(['entity_type', 'entity_id'], 'custom_field_values_entity_idx');
            }

            $table->index(['entity_id', 'custom_field_id'], 'custom_field_values_entity_id_custom_field_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('custom-fields.database.table_names.custom_field_values'));
        Schema::dropIfExists(config('custom-fields.database.table_names.custom_field_options'));
        Schema::dropIfExists(config('custom-fields.database.table_names.custom_fields'));
        Schema::dropIfExists(config('custom-fields.database.table_names.custom_field_sections'));
    }
};

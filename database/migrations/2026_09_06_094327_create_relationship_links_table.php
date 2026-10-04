<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relaticle\CustomFields\Enums\CustomFieldsFeature;
use Relaticle\CustomFields\FeatureSystem\FeatureManager;
use Relaticle\CustomFields\Models\CustomFieldLink;
use Relaticle\CustomFields\Support\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        if (! FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_RELATIONSHIPS)) {
            return;
        }

        $links = config('custom-fields.database.table_names.custom_field_links');

        Schema::create($links, function (Blueprint $table): void {
            KeyType::primary($table);

            if (FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_MULTI_TENANCY)) {
                KeyType::foreign($table, config('custom-fields.database.column_names.tenant_foreign_key'))
                    ->nullable()
                    ->index();
            }

            KeyType::foreign($table, 'relationship_id')
                ->constrained(config('custom-fields.database.table_names.custom_field_relationships'))
                ->cascadeOnDelete();

            KeyType::morphs($table, 'from_entity');
            KeyType::morphs($table, 'to_entity');

            $table->unsignedInteger('sort_order')->nullable();

            $table->dateTime('active_from');
            $table->dateTime('active_until')->nullable();

            KeyType::morphs($table, 'created_by', nullable: true);

            $table->string('source', 32)->default('user');
            $table->float('confidence')->nullable();

            $table->index(['relationship_id', 'from_entity_id', 'active_until'], 'cf_links_from_idx');
            $table->index(['relationship_id', 'to_entity_id', 'active_until'], 'cf_links_to_idx');
        });

        // The only driver switch in this package: a partial unique index is the duplicate-edge
        // wall, and the MySQL family has none. There the writer alone enforces it (spec 1.2).
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (relationship_id, from_entity_type, from_entity_id, to_entity_type, to_entity_id) WHERE active_until IS NULL',
                CustomFieldLink::ACTIVE_EDGE_INDEX,
                Schema::getConnection()->getSchemaGrammar()->wrapTable($links),
            ));
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->index();

            $table->foreignUlid('relationship_id')->constrained('custom_field_relationships')->cascadeOnDelete();

            $table->ulidMorphs('from_entity');
            $table->ulidMorphs('to_entity');

            $table->unsignedInteger('sort_order')->nullable();

            $table->dateTime('active_from');
            $table->dateTime('active_until')->nullable();

            $table->nullableUlidMorphs('created_by');

            $table->string('source', 32)->default('user');
            $table->float('confidence')->nullable();
            $table->json('metadata')->nullable();

            $table->index(['relationship_id', 'from_entity_id', 'active_until'], 'cf_links_from_idx');
            $table->index(['relationship_id', 'to_entity_id', 'active_until'], 'cf_links_to_idx');
        });

        DB::statement('create unique index cf_links_active_edge_unique on custom_field_links (relationship_id, from_entity_type, from_entity_id, to_entity_type, to_entity_id) where active_until is null');
    }
};

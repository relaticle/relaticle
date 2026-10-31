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
        Schema::create('table_layouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity');
            $table->string('name');
            $table->boolean('is_shared')->default(false);
            $table->jsonb('state');
            $table->timestamps();

            $table->index(['workspace_id', 'entity']);
        });

        DB::statement('create unique index table_layouts_owner_name_unique on table_layouts (user_id, workspace_id, entity, lower(name))');

        Schema::table('table_preferences', function (Blueprint $table): void {
            $table->foreignUlid('active_layout_id')->nullable()->constrained('table_layouts')->nullOnDelete();
            $table->foreignUlid('default_layout_id')->nullable()->constrained('table_layouts')->nullOnDelete();
            $table->string('applied_layout_hash')->nullable();
        });
    }
};

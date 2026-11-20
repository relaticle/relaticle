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
        Schema::create('favorites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->ulid('parent_id')->nullable();
            $table->string('name')->nullable();
            $table->string('record_type')->nullable();
            $table->ulid('record_id')->nullable();
            $table->decimal('position', 20, 10);
            $table->timestamps();

            $table->index(['user_id', 'workspace_id', 'parent_id', 'position']);
            $table->index(['record_type', 'record_id']);
            $table->index('parent_id');
            $table->unique(['user_id', 'workspace_id', 'record_type', 'record_id']);
        });

        Schema::table('favorites', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('favorites')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            alter table favorites add constraint favorites_kind_shape check (
                (kind = 'folder' and name is not null and record_type is null and record_id is null)
                or (kind = 'record' and name is null and record_type is not null and record_id is not null)
            )
            SQL);
    }
};

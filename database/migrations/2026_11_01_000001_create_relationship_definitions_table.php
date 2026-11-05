<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_relationships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->index();

            $table->string('code');
            $table->string('from_entity_type');
            $table->string('to_entity_type');
            $table->string('cardinality');

            $table->foreignUlid('from_field_id')->nullable()->unique()->constrained('custom_fields')->nullOnDelete();
            $table->foreignUlid('to_field_id')->nullable()->unique()->constrained('custom_fields')->nullOnDelete();

            $table->boolean('is_symmetric')->default(false);

            $table->timestamps();

            $table->unique(['code', 'tenant_id']);
        });
    }
};

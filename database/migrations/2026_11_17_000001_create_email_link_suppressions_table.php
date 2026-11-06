<?php

declare(strict_types=1);

use App\Support\Migrations\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::create('email_link_suppressions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            TenantMigration::addForeignKey($table);
            $table->foreignUlid('email_id')->constrained('emails')->cascadeOnDelete();
            $table->ulidMorphs('emailable');
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['email_id', 'emailable_type', 'emailable_id']);
            $table->index(['workspace_id', 'emailable_type', 'emailable_id']);
        });
    }
};

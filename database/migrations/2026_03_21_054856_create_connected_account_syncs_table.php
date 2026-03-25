<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_account_syncs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('connected_account_id')
                ->constrained('connected_accounts')
                ->cascadeOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('emails_synced')->default(0);
            $table->unsignedInteger('errors_encountered')->default(0);
            $table->string('cursor_before')->nullable();
            $table->string('cursor_after')->nullable();
            $table->string('status', 20)->default('completed'); // completed | failed | partial
            $table->string('contact_creation_mode', 20)
                ->default('none')
                ->comment('Controls whether new Person records are auto-created from email participants.');
            $table->boolean('auto_create_companies')
                ->default(false)
                ->comment('When true, auto-create Company records for unrecognised business domains.');
            $table->text('error_details')->nullable();

            $table->index(['connected_account_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_account_syncs');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_participants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_id')->constrained('emails')->cascadeOnDelete();

            $table->string('email_address');
            $table->string('name')->nullable();
            $table->string('role', 10);                      // from | to | cc | bcc

            // Resolved FKs — nullable, filled by auto-linking logic
            // Named contact_id per codebase convention (see opportunities.contact_id → people.id)
            $table->foreignUlid('contact_id')
                ->nullable()
                ->constrained('people')
                ->nullOnDelete();

            // Direct domain-matched company linking
            $table->foreignUlid('company_id')
                ->nullable()
                ->constrained('companies')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['email_id', 'role']);
            $table->index('email_address');
            $table->index('contact_id');
        });
    }
};

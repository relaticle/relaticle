<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_access_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('owner_id')->constrained('users')->cascadeOnDelete();

            // Single-email request (nullable)
            $table->foreignUlid('email_id')
                ->nullable()
                ->constrained('emails')
                ->nullOnDelete();

            // Record-level request (nullable) — e.g. "all emails on this Person"
            $table->string('emailable_type')->nullable();
            $table->ulid('emailable_id')->nullable();

            $table->string('tier_requested', 30);            // metadata_only | subject | full
            $table->string('status', 20)->default('pending'); // pending | approved | denied

            $table->timestamps();

            $table->index(['owner_id', 'status']);
            $table->index('email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_access_requests');
    }
};

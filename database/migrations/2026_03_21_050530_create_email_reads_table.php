<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-viewer read state. A row's existence means "this user has read this
        // email"; read_at records when. Read state is per (email, user) so the
        // owner's provider-synced state and each teammate's in-app reads stay
        // independent — the single emails.read_at column could not express that.
        Schema::create('email_reads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_id')->constrained('emails')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['email_id', 'user_id']);
            $table->index(['user_id', 'email_id']);
        });
    }
};

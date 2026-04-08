<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_threads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('connected_account_id')->constrained('connected_accounts')->cascadeOnDelete();

            $table->string('thread_id');                     // provider thread/conversation ID
            $table->string('subject')->nullable();
            $table->unsignedInteger('email_count')->default(0);
            $table->unsignedInteger('participant_count')->default(0);
            $table->timestamp('first_email_at')->nullable();
            $table->timestamp('last_email_at')->nullable();

            $table->timestamps();

            $table->unique(['connected_account_id', 'thread_id']);
            $table->index(['team_id', 'last_email_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_threads');
    }
};

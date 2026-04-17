<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_shares', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_id')->constrained('emails')->cascadeOnDelete();
            $table->foreignUlid('shared_by')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('shared_with')->constrained('users')->cascadeOnDelete();
            $table->string('tier', 30);                      // metadata_only | subject | full
            $table->timestamps();

            $table->index(['shared_with', 'email_id']);
            $table->unique(['email_id', 'shared_with'], 'email_shares_email_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_shares');
    }
};

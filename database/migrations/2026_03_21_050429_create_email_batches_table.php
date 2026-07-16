<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->teams();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('connected_account_id')->constrained('connected_accounts')->cascadeOnDelete();
            $table->string('subject');
            $table->unsignedInteger('total_recipients');
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 20)->default('queued'); // queued | sending | completed | partial_failure
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }
};

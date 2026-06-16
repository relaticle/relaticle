<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 36)->nullable();
            $table->string('message_id', 36)->nullable();
            $table->string('action_class');
            $table->string('operation');
            $table->string('entity_type');
            $table->jsonb('action_data');
            $table->jsonb('display_data');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('resolved_at')->nullable();
            $table->jsonb('result_data')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['conversation_id', 'status']);
            $table->index('expires_at');
            $table->index(['status', 'expires_at']);
            $table->index(['team_id', 'user_id', 'status']);

            $table->index('message_id');

            $table->foreign('conversation_id')->references('id')->on('agent_conversations')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('agent_conversation_messages')->nullOnDelete();
        });
    }
};

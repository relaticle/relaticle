<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_message_feedback', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 36);
            $table->string('message_id', 36);
            $table->string('rating', 8);
            $table->string('category', 32)->nullable();
            $table->string('comment', 1000)->nullable();
            $table->string('model', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'message_id']);
            $table->index(['team_id', 'rating', 'created_at']);

            $table->foreign('conversation_id')->references('id')->on('agent_conversations')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('agent_conversation_messages')->cascadeOnDelete();
        });
    }
};

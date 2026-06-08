<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_conversation_message_mentions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('message_id', 36);
            $table->string('type', 32);
            $table->ulid('record_id');
            $table->string('label', 255);
            $table->timestamps();

            $table->foreign('message_id')
                ->references('id')->on('agent_conversation_messages')
                ->cascadeOnDelete();
            $table->index(['message_id', 'type']);
            $table->index(['type', 'record_id']);
        });
    }
};

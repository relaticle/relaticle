<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->string('email_conversation_key', 64)->nullable();
            $table->string('email_input_hash', 64)->nullable();
        });
        DB::statement("CREATE UNIQUE INDEX agent_conversations_email_anchor_unique ON agent_conversations (workspace_id, participant_id, email_conversation_key) WHERE purpose = 'email'");
    }
};

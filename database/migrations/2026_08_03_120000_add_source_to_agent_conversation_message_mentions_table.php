<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversation_message_mentions', function (Blueprint $table): void {
            $table->string('source', 32)->default('mention')->after('label');
            $table->index(['message_id', 'source']);
        });
    }
};

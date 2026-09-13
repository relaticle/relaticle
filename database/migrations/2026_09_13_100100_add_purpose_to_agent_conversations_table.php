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
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->string('purpose', 32)->nullable();
        });

        DB::statement("create unique index agent_conversations_setup_purpose_unique on agent_conversations (workspace_id) where purpose = 'setup'");
    }
};

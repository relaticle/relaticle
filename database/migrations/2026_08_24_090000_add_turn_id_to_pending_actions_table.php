<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_actions', function (Blueprint $table): void {
            $table->string('turn_id')->nullable()->after('conversation_id');

            $table->index(['conversation_id', 'turn_id']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['people', 'companies', 'opportunities'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->timestamp('last_email_at')->nullable();
                $table->timestamp('last_interaction_at')->nullable();
                $table->unsignedInteger('email_count')->default(0);
                $table->unsignedInteger('inbound_email_count')->default(0);
                $table->unsignedInteger('outbound_email_count')->default(0);
                $table->unsignedInteger('meeting_count')->default(0);
                $table->timestamp('last_meeting_at')->nullable();
            });
        }
    }
};

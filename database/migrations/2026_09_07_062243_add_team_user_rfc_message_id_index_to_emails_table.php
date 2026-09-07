<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table): void {
            $table->index(
                ['team_id', 'user_id', 'rfc_message_id'],
                'emails_team_user_message_id_idx',
            );
        });
    }
};

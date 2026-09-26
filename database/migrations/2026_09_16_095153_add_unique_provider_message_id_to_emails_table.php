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
            $table->unique(
                ['connected_account_id', 'provider_message_id'],
                'idx_emails_account_provider_message_id',
            );
        });
    }
};

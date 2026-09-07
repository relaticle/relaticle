<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->dropUnique('connected_accounts_user_id_provider_email_address_unique');
            $table->unique(
                ['user_id', 'team_id', 'provider', 'email_address'],
                'connected_accounts_user_team_provider_email_unique',
            );
        });
    }
};

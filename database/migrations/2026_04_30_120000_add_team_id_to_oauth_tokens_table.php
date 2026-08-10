<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->char('team_id', 26)->nullable()->index()->after('client_id');
        });

        Schema::table('oauth_access_tokens', function (Blueprint $table): void {
            $table->char('team_id', 26)->nullable()->index()->after('client_id');
        });
    }
};

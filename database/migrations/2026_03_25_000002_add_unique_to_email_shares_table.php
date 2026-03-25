<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_shares', function (Blueprint $table): void {
            $table->unique(['email_id', 'shared_with'], 'email_shares_email_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('email_shares', function (Blueprint $table): void {
            $table->dropUnique('email_shares_email_user_unique');
        });
    }
};

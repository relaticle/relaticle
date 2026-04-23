<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('scheduled_deletion_at')->nullable()->index()->after('remember_token');
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->timestamp('scheduled_deletion_at')->nullable()->index()->after('personal_team');
        });
    }
};

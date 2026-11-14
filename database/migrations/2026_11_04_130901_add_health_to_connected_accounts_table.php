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
            $table->string('health', 24)->default('healthy');
            $table->string('health_reason', 64)->nullable();
            $table->timestamp('health_changed_at')->nullable();
            $table->timestamp('health_notified_at')->nullable();
        });
    }
};

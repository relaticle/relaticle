<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->timestamp('last_email_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->integer('email_count')->default(0);
            $table->integer('inbound_email_count')->default(0);
            $table->integer('outbound_email_count')->default(0);
        });
    }
};

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
        Schema::table('opportunities', function (Blueprint $table) {
            $table->timestamp('last_email_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->integer('email_count')->default(0);
            $table->integer('inbound_email_count')->default(0);
            $table->integer('outbound_email_count')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn('last_email_at');
            $table->dropColumn('last_interaction_at');
            $table->dropColumn('email_count');
            $table->dropColumn('inbound_email_count');
            $table->dropColumn('outbound_email_count');
        });
    }
};

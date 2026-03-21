<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['people', 'companies'] as $tbl) {
            Schema::table($tbl, function (Blueprint $table): void {
                $table->timestamp('last_email_at')->nullable()->after('creation_source');
                $table->timestamp('last_interaction_at')->nullable()->after('last_email_at');
                $table->unsignedInteger('email_count')->default(0)->after('last_interaction_at');
                $table->unsignedInteger('inbound_email_count')->default(0)->after('email_count');
                $table->unsignedInteger('outbound_email_count')->default(0)->after('inbound_email_count');
                $table->float('avg_response_time_hours')->nullable()->after('outbound_email_count');
            });
        }
    }

    public function down(): void
    {
        foreach (['people', 'companies'] as $tbl) {
            Schema::table($tbl, function (Blueprint $table): void {
                $table->dropColumn([
                    'last_email_at',
                    'last_interaction_at',
                    'email_count',
                    'inbound_email_count',
                    'outbound_email_count',
                    'avg_response_time_hours',
                ]);
            });
        }
    }
};

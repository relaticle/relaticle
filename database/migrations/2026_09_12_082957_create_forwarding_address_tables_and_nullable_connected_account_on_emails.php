<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_forwarding_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->teams();
            $table->string('local_part', 100);
            $table->timestamps();

            $table->unique('team_id');
            $table->unique('local_part');
        });

        Schema::create('user_forwarding_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->teams();
            $table->string('sharing_tier', 30)->default('metadata_only');
            $table->timestamps();

            $table->unique(['user_id', 'team_id']);
        });

        Schema::create('user_forwarding_full_access_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->teams();
            $table->foreignUlid('granted_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'team_id', 'granted_user_id'], 'user_forwarding_grants_unique');
        });

        Schema::create('user_forwarding_blocklists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->teams();
            $table->string('type', 20);
            $table->string('value');
            $table->timestamps();

            $table->index(['user_id', 'team_id', 'type', 'value']);
        });

        Schema::table('emails', function (Blueprint $table): void {
            $table->dropForeign(['connected_account_id']);
            $table->dropUnique('idx_emails_account_msgid');
        });

        Schema::table('emails', function (Blueprint $table): void {
            $table->ulid('connected_account_id')->nullable()->change();
        });

        Schema::table('emails', function (Blueprint $table): void {
            $table->foreign('connected_account_id')
                ->references('id')
                ->on('connected_accounts')
                ->nullOnDelete();

            $table->unique(['connected_account_id', 'rfc_message_id'], 'idx_emails_account_msgid');
        });

        DB::statement(
            'CREATE UNIQUE INDEX emails_inbound_message_id_unique ON emails (team_id, user_id, rfc_message_id) WHERE connected_account_id IS NULL AND rfc_message_id IS NOT NULL'
        );
    }
};

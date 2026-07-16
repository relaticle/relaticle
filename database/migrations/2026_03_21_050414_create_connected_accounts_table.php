<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->teams();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 50);                   // gmail | microsoft
            $table->string('provider_account_id')->nullable(); // Google sub / MS oid — prevents duplicate connections
            $table->string('email_address');
            $table->string('display_name')->nullable();

            $table->text('access_token');                     // encrypted at cast level
            $table->text('refresh_token')->nullable();        // encrypted at cast level
            $table->timestamp('token_expires_at')->nullable();

            $table->json('capabilities')->nullable();         // provider-specific feature flags

            $table->string('sync_cursor')->nullable();        // Gmail historyId / MS Graph deltaToken
            $table->timestamp('last_synced_at')->nullable();
            $table->string('status', 50)->default('active');  // active | error | disconnected | reauth_required
            $table->text('last_error')->nullable();

            $table->boolean('sync_inbox')->default(true);
            $table->boolean('sync_sent')->default(true);

            $table->string('contact_creation_mode', 20)->default('none');
            $table->boolean('auto_create_companies')->default(false);

            $table->unsignedInteger('daily_send_limit')->nullable();
            $table->unsignedInteger('hourly_send_limit')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'provider', 'email_address']);
            $table->index(['team_id', 'status']);
        });
    }
};

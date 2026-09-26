<?php

declare(strict_types=1);

use App\Support\Migrations\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $workspaceId = TenantMigration::foreignKeyColumn();

        Schema::create('connected_accounts', function (Blueprint $table) use ($workspaceId): void {
            $table->ulid('id')->primary();
            $table->teams();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 50);
            $table->string('provider_account_id')->nullable();
            $table->string('email_address');
            $table->string('display_name')->nullable();
            $table->boolean('is_default')->default(false);

            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->json('capabilities')->nullable();

            $table->string('sync_cursor')->nullable();
            $table->text('calendar_sync_cursor')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_calendar_synced_at')->nullable();
            $table->unsignedInteger('initial_sync_imported')->default(0);
            $table->unsignedInteger('initial_sync_estimated')->nullable();
            $table->unsignedInteger('initial_calendar_sync_imported')->default(0);

            $table->string('calendar_push_channel_id')->nullable();
            $table->string('calendar_push_resource_id')->nullable();
            $table->text('calendar_push_verification_token')->nullable();
            $table->timestamp('calendar_push_expires_at')->nullable();

            $table->string('status', 50)->default('active');
            $table->text('last_error')->nullable();

            $table->boolean('sync_inbox')->default(true);
            $table->boolean('sync_sent')->default(true);

            $table->unsignedInteger('daily_send_limit')->nullable();
            $table->unsignedInteger('hourly_send_limit')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['user_id', $workspaceId, 'provider', 'email_address'],
                'connected_accounts_user_workspace_provider_email_unique',
            );
            $table->index([$workspaceId, 'status']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX connected_accounts_one_default_per_user_workspace '.
            "ON connected_accounts (user_id, {$workspaceId}) ".
            'WHERE is_default = true AND deleted_at IS NULL'
        );
    }
};

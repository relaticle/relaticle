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
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_account_id');
            $table->string('email_address');
            $table->string('display_name')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('sync_cursor')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('status')->default('active');
            $table->text('last_error')->nullable();
            $table->boolean('sync_inbox')->default(true);
            $table->boolean('sync_sent')->default(true);
            $table->string('contact_creation_mode')->default('none');
            $table->boolean('auto_create_companies')->default(false);
            $table->integer('daily_send_limit')->default(500);
            $table->integer('hourly_send_limit')->default(100);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('email_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('connected_account_id')->constrained()->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->integer('total_recipients')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('emails', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('connected_account_id')->constrained()->cascadeOnDelete();
            $table->string('rfc_message_id')->nullable()->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('thread_id')->nullable()->index();
            $table->string('in_reply_to')->nullable();
            $table->string('subject')->nullable();
            $table->text('snippet')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('direction');
            $table->string('folder')->nullable();
            $table->string('status')->default('synced');
            $table->string('privacy_tier')->default('metadata_only');
            $table->boolean('has_attachments')->default(false);
            $table->boolean('is_internal')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->string('creation_source')->default('sync');
            $table->foreignUlid('batch_id')->nullable()->constrained('email_batches')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('email_bodies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_id')->constrained()->cascadeOnDelete();
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();
            $table->timestamps();
        });

        Schema::create('email_participants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_id')->constrained()->cascadeOnDelete();
            $table->string('email_address');
            $table->string('name')->nullable();
            $table->string('role');
            $table->ulid('contact_id')->nullable();
            $table->ulid('company_id')->nullable();
            $table->timestamps();

            $table->index(['email_address', 'role']);
        });

        Schema::create('email_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_id')->constrained()->cascadeOnDelete();
            $table->string('filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('content_id')->nullable();
            $table->text('provider_attachment_id')->nullable();
            $table->string('storage_path')->nullable();
        });

        Schema::create('email_shares', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('shared_by')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('shared_with')->constrained('users')->cascadeOnDelete();
            $table->string('tier');
            $table->timestamps();

            $table->unique(['email_id', 'shared_with']);
        });

        Schema::create('email_labels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('source')->default('ai');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('email_blocklists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('value');
            $table->timestamps();
        });

        Schema::create('email_access_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('email_id')->constrained()->cascadeOnDelete();
            $table->nullableUlidMorphs('emailable');
            $table->string('tier_requested');
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('email_signatures', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('connected_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('content_html');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('email_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('subject')->nullable();
            $table->text('body_html')->nullable();
            $table->json('variables')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('email_threads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('connected_account_id')->constrained()->cascadeOnDelete();
            $table->string('thread_id')->index();
            $table->string('subject')->nullable();
            $table->integer('email_count')->default(0);
            $table->integer('participant_count')->default(0);
            $table->timestamp('first_email_at')->nullable();
            $table->timestamp('last_email_at')->nullable();
            $table->timestamps();
        });

        Schema::create('connected_account_syncs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('connected_account_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('emails_synced')->default(0);
            $table->integer('errors_encountered')->default(0);
            $table->string('cursor_before')->nullable();
            $table->string('cursor_after')->nullable();
            $table->string('status')->default('running');
            $table->text('error_details')->nullable();
        });

        Schema::create('protected_recipients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('value');
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('public_email_domains', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->timestamps();

            $table->unique(['team_id', 'domain']);
        });

        Schema::create('emailables', function (Blueprint $table): void {
            $table->ulid('email_id');
            $table->ulidMorphs('emailable');

            $table->foreign('email_id')->references('id')->on('emails')->cascadeOnDelete();
            $table->primary(['email_id', 'emailable_id', 'emailable_type']);
        });

        // Add email metrics columns to entity tables
        foreach (['people', 'companies', 'opportunities'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedInteger('email_count')->default(0);
                $table->unsignedInteger('inbound_email_count')->default(0);
                $table->unsignedInteger('outbound_email_count')->default(0);
                $table->timestamp('last_email_at')->nullable();
                $table->timestamp('last_interaction_at')->nullable();
            });
        }

        // Add default email sharing tier to users and teams
        Schema::table('users', function (Blueprint $table): void {
            $table->string('default_email_sharing_tier')->nullable();
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->string('default_email_sharing_tier')->default('metadata_only');
        });
    }
};

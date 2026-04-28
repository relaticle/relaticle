<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->teams();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();    // explicit owner (survives account deletion)
            $table->foreignUlid('connected_account_id')->constrained('connected_accounts')->cascadeOnDelete();

            // Provider identifiers — two different IDs
            $table->string('rfc_message_id')->nullable();        // RFC 2822 Message-ID header (threading)
            $table->string('provider_message_id')->nullable();   // Gmail msg ID / MS Graph msg ID (API ops)
            $table->string('thread_id')->nullable();             // Provider's thread/conversation ID
            $table->string('in_reply_to')->nullable();           // RFC 2822 In-Reply-To (reply chain)

            $table->string('subject')->nullable();
            $table->string('snippet', 255)->nullable();          // Preview text for list views
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();

            $table->string('direction', 20);                     // inbound | outbound
            $table->string('folder', 30)->nullable();            // inbox | sent | drafts | archive

            // Status — add now (default synced) to avoid costly migration when Phase 2 ships
            $table->string('status', 30)->default('synced');     // synced | draft | queued | sending | sent | failed | cancelled
            $table->text('last_error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('priority', 10)->default('bulk');

            // Privacy — owner-set default for team visibility (no shared_with_team boolean)
            $table->string('privacy_tier', 30)->default('metadata_only'); // private | metadata_only | subject | full

            // Computed flags — set during sync
            $table->boolean('has_attachments')->default(false);
            $table->boolean('is_internal')->default(false);      // true when all participants are workspace members
            $table->timestamp('read_at')->nullable();

            $table->string('creation_source', 50)->default('sync'); // EmailCreationSource: sync | compose | forward | bcc_inbound
            $table->foreignUlid('batch_id')->nullable()->constrained('email_batches')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Dedup: one rfc_message_id per connected account (two users can each have the same email)
            $table->unique(['connected_account_id', 'rfc_message_id'], 'idx_emails_account_msgid');

            // Query patterns
            $table->index(['team_id', 'thread_id']);
            $table->index(['team_id', 'sent_at']);
            $table->index(['connected_account_id', 'sent_at']);
            $table->index('provider_message_id');
            $table->index(['user_id', 'privacy_tier']);
            $table->index(['team_id', 'deleted_at', 'creation_source', 'created_at'], 'idx_emails_team_activity');
            $table->index('batch_id');
            $table->index(['connected_account_id', 'status', 'scheduled_for'], 'idx_emails_dispatcher');
            $table->index(['user_id', 'status'], 'idx_emails_user_status');
        });
    }
};

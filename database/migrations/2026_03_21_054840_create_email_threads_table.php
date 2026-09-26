<?php

declare(strict_types=1);

use App\Support\Migrations\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $workspaceId = TenantMigration::foreignKeyColumn();

        Schema::create('email_threads', function (Blueprint $table) use ($workspaceId): void {
            $table->ulid('id')->primary();
            $table->foreignUlid($workspaceId)->constrained(TenantMigration::table())->cascadeOnDelete();
            $table->foreignUlid('connected_account_id')->constrained('connected_accounts')->cascadeOnDelete();

            $table->string('thread_id');                     // provider thread/conversation ID
            $table->text('subject')->nullable();
            $table->unsignedInteger('email_count')->default(0);
            $table->unsignedInteger('participant_count')->default(0);
            $table->timestamp('first_email_at')->nullable();
            $table->timestamp('last_email_at')->nullable();

            $table->timestamps();

            $table->unique(['connected_account_id', 'thread_id']);
            $table->index([$workspaceId, 'last_email_at']);
        });
    }
};

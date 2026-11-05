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
        Schema::create('email_conversations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            TenantMigration::addForeignKey($table);
            $table->string('conversation_key', 64);
            $table->unsignedInteger('message_count');
            $table->timestamp('first_message_at');
            $table->timestamp('last_message_at');
            $table->timestamp('last_external_inbound_at')->nullable();
            $table->timestamp('last_member_outbound_at')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->boolean('is_automated')->default(false);
            $table->timestamps();

            $table->unique(['workspace_id', 'conversation_key']);
            $table->index(['workspace_id', 'last_message_at']);
            $table->index(['workspace_id', 'last_external_inbound_at']);
        });
    }
};

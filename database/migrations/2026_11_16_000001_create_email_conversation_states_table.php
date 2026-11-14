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
        Schema::create('email_conversation_states', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            TenantMigration::addForeignKey($table);
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_key', 64);
            $table->timestamp('done_at');
            $table->timestamp('done_through')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id', 'conversation_key']);
        });
    }
};

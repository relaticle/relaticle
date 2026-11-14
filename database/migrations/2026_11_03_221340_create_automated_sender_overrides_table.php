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
        Schema::create('automated_sender_overrides', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            TenantMigration::addForeignKey($table);
            $table->string('email_address');
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['workspace_id', 'email_address']);
        });
    }
};

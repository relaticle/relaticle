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
        Schema::create('email_follow_ups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            TenantMigration::addForeignKey($table);
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('email_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('task_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['email_id', 'user_id']);
        });
    }
};

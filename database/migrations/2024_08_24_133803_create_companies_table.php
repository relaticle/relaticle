<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUlid('creator_id')->nullable()->constrained('users')->onDelete('set null');

            // Account Owner For Companies: Your team member responsible for managing the company account
            $table->foreignUlid('account_owner_id')->nullable()->constrained('users')->onDelete('set null');

            $table->string('name');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};

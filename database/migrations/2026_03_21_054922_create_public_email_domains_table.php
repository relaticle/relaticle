<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_email_domains', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->timestamps();

            $table->unique(['team_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_email_domains');
    }
};

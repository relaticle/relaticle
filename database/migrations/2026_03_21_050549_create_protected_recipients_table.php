<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protected_recipients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                      // email | domain
            $table->string('value');
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }
};

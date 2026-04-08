<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_blocklists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->teams();
            $table->string('type', 20);                      // email | domain
            $table->string('value');
            $table->timestamps();

            $table->index(['user_id', 'type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_blocklists');
    }
};

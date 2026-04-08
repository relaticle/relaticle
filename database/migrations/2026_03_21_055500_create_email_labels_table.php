<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_labels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_id')->constrained('emails')->cascadeOnDelete();
            $table->string('label');
            $table->string('source', 30);                    // provider | user | system
            $table->timestamp('created_at')->nullable();

            $table->index(['email_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_labels');
    }
};

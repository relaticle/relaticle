<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_bodies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_id')->constrained('emails')->cascadeOnDelete();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->timestamps();

            $table->unique('email_id');
        });
    }
};

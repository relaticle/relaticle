<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_signatures', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->teams();
            $table->foreignUlid('connected_account_id')
                ->constrained('connected_accounts')
                ->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->longText('content_html');
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['team_id', 'connected_account_id']);
        });
    }
};

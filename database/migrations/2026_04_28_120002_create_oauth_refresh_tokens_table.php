<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_refresh_tokens', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->char('access_token_id', 80);
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();

            $table->foreign('access_token_id')
                ->references('id')->on('oauth_access_tokens')
                ->cascadeOnDelete();
        });
    }
};

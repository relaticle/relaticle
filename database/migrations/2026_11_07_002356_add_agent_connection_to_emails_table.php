<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table): void {
            $table->uuid('oauth_client_id')->nullable();
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
        });
    }
};

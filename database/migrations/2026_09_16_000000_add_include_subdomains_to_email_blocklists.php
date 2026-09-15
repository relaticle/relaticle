<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_blocklists', function (Blueprint $table): void {
            $table->boolean('include_subdomains')->default(false);
        });

        Schema::table('workspace_email_blocklists', function (Blueprint $table): void {
            $table->boolean('include_subdomains')->default(false);
        });
    }
};

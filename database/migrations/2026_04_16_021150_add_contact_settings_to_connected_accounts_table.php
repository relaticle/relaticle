<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->string('contact_creation_mode', 20)
                ->default('none')
                ->after('sync_sent');

            $table->boolean('auto_create_companies')
                ->default(false)
                ->after('contact_creation_mode');
        });
    }
};

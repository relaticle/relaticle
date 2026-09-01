<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('contact_creation_mode', 20)
                ->default('bidirectional')
                ->after('default_email_sharing_tier');
            $table->boolean('auto_create_companies')
                ->default(true)
                ->after('contact_creation_mode');
        });
    }
};

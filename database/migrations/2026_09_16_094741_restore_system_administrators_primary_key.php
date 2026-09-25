<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('system_administrators', ['id'], 'primary')) {
            return;
        }

        Schema::table('system_administrators', function (Blueprint $table): void {
            $table->primary('id');
        });
    }
};

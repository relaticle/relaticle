<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('tasks', ['id'], 'primary')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->primary('id');
        });
    }
};

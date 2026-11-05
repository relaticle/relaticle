<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_field_values', function (Blueprint $table): void {
            $table->string('source', 32)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }
};

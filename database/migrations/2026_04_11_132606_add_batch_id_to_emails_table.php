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
            $table->foreignUlid('batch_id')->nullable()->constrained('email_batches')->nullOnDelete()->after('creation_source');
            $table->index('batch_id');
        });
    }
};

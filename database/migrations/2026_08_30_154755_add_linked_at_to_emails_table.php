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
            // Set once CRM linking has completed for this email. LinkEmailAction's
            // counter increments are not idempotent, so a retried job must be able
            // to tell "delivered but never linked" from "already linked".
            $table->timestamp('linked_at')->nullable()->after('attempts');
        });
    }
};

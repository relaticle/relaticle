<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisitions', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignUlid('workspace_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('source', 32);
            $table->string('medium', 20)->nullable();
            $table->string('campaign', 64)->nullable();
            $table->string('landing', 24);
            $table->timestamp('captured_at');
            $table->timestamp('workspace_linked_at')->nullable();
            $table->timestamp('setup_completed_at')->nullable();
            $table->timestamp('first_checkout_at')->nullable();
            $table->string('first_checkout_session_id')->nullable()->unique();
            $table->timestamp('first_paid_at')->nullable();
            $table->string('first_paid_invoice_id')->nullable()->unique();
            $table->timestamp('first_renewal_failed_at')->nullable();
            $table->string('first_renewal_failed_invoice_id')->nullable()->unique();
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('flow_id')->index();
            $table->unsignedInteger('generation');
            $table->string('purpose', 40);
            $table->string('email');
            $table->foreignUlid('user_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->string('flow_digest', 64);
            $table->string('code_digest', 64);
            $table->string('state_fingerprint', 64)->nullable();
            $table->ulid('operation_id')->nullable();
            $table->timestamp('expires_at')->index();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason', 20)->nullable();
            $table->timestamp('created_at');
            $table->unique(['flow_id', 'generation']);
        });
    }
};

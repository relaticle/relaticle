<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_attendees', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();

            $table->string('email_address');
            $table->string('name')->nullable();
            $table->string('response_status')->nullable();
            $table->boolean('is_organizer')->default(false);
            $table->boolean('is_self')->default(false);

            $table->foreignUlid('contact_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->timestamps();

            $table->index(['meeting_id', 'email_address']);
            $table->index('email_address');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_id')->constrained('emails')->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');              // bytes
            $table->string('storage_path');
            $table->string('content_id')->nullable();        // for inline/CID images
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
    }
};

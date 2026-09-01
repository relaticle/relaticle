<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_attachments', function (Blueprint $table): void {
            $table->boolean('is_inline')->default(false);
        });

        DB::table('email_attachments')
            ->whereNotNull('content_id')
            ->where('mime_type', 'like', 'image/%')
            ->update(['is_inline' => true]);
    }
};

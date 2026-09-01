<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('subscriber_profile_hash')->nullable()->after('mailcoach_subscriber_uuid');
            $table->dropColumn('subscriber_recency_bucket');
        });
    }
};

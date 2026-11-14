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
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('email_access_requests', function (Blueprint $table): void {
            $table->string('note', 500)->nullable();
        });

        DB::statement("create unique index email_access_requests_one_pending_record on email_access_requests (workspace_id, emailable_type, emailable_id, owner_id, requester_id) where status = 'pending' and email_id is null");
    }
};

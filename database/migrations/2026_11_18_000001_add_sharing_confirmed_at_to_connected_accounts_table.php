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

        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->timestamp('sharing_confirmed_at')->nullable();
        });

        $confirmedAt = now();

        // A stored level was chosen by the owner, in setup or in the mailbox settings.
        DB::table('connected_accounts')
            ->whereNotNull('sharing_tier')
            ->whereNull('sharing_confirmed_at')
            ->select('id')
            ->eachById(fn (object $mailbox): int => DB::table('connected_accounts')
                ->where('id', $mailbox->id)
                ->update(['sharing_confirmed_at' => $confirmedAt]));
    }
};

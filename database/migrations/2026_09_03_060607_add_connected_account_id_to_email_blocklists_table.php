<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_blocklists', function (Blueprint $table): void {
            $table->foreignUlid('connected_account_id')
                ->nullable()
                ->after('team_id')
                ->constrained('connected_accounts')
                ->cascadeOnDelete();
        });

        $this->backfillConnectedAccountIds();

        Schema::table('email_blocklists', function (Blueprint $table): void {
            $table->ulid('connected_account_id')->nullable(false)->change();
            $table->dropIndex(['user_id', 'type', 'value']);
            $table->index(['connected_account_id', 'type', 'value']);
        });
    }

    private function backfillConnectedAccountIds(): void
    {
        $entries = DB::table('email_blocklists')->get();

        foreach ($entries as $entry) {
            $accountIds = DB::table('connected_accounts')
                ->where('user_id', $entry->user_id)
                ->where('team_id', $entry->team_id)
                ->whereNull('deleted_at')
                ->pluck('id');

            if ($accountIds->isEmpty()) {
                DB::table('email_blocklists')->where('id', $entry->id)->delete();

                continue;
            }

            if ($accountIds->count() === 1) {
                DB::table('email_blocklists')
                    ->where('id', $entry->id)
                    ->update(['connected_account_id' => $accountIds->first()]);

                continue;
            }

            $now = now();

            foreach ($accountIds as $accountId) {
                DB::table('email_blocklists')->insert([
                    'id' => (string) Str::ulid(),
                    'user_id' => $entry->user_id,
                    'team_id' => $entry->team_id,
                    'connected_account_id' => $accountId,
                    'type' => $entry->type,
                    'value' => $entry->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('email_blocklists')->where('id', $entry->id)->delete();
        }
    }
};

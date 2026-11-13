<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string TYPE = 'Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification';

    public function up(): void
    {
        DB::table('notifications')
            ->select('id')
            ->where('type', self::TYPE)
            ->whereNull('archived_at')
            ->chunkById(500, function (Collection $rows): void {
                DB::table('notifications')
                    ->whereIn('id', $rows->pluck('id'))
                    ->update(['archived_at' => now(), 'updated_at' => now()]);
            });
    }
};

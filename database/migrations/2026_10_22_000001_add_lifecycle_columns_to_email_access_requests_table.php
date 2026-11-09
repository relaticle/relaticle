<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const int EXPIRES_AFTER_DAYS = 30;

    public function up(): void
    {
        Schema::table('email_access_requests', function (Blueprint $table): void {
            $table->foreignUlid('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();

            $table->index(['owner_id', 'workspace_id', 'status']);
        });

        $expiresAt = Date::now()->addDays(self::EXPIRES_AFTER_DAYS);

        DB::table('email_access_requests')->eachById(function (object $request) use ($expiresAt): void {
            $workspaceId = $request->email_id === null
                ? null
                : DB::table('emails')->where('id', $request->email_id)->value('workspace_id');

            $update = ['workspace_id' => $workspaceId];

            if ($request->status === 'pending') {
                $update['expires_at'] = $expiresAt;
            }

            if ($request->status === 'pending' && $workspaceId === null) {
                $update['status'] = 'expired';
            }

            DB::table('email_access_requests')->where('id', $request->id)->update($update);
        });

        $this->expireDuplicatePendingRequests();

        DB::statement("create unique index email_access_requests_one_pending_per_requester on email_access_requests (email_id, requester_id) where status = 'pending'");
        DB::statement("create index email_access_requests_pending_expiry on email_access_requests (expires_at) where status = 'pending'");
    }

    private function expireDuplicatePendingRequests(): void
    {
        $ranked = DB::table('email_access_requests')
            ->where('status', 'pending')
            ->selectRaw('id, row_number() over (partition by email_id, requester_id order by created_at desc, id desc) as position');

        $superseded = DB::query()->fromSub($ranked, 'ranked')->select('id')->where('position', '>', 1);

        DB::table('email_access_requests')
            ->whereIn('id', $superseded)
            ->chunkById(500, function (Collection $requests): void {
                DB::table('email_access_requests')
                    ->whereIn('id', $requests->pluck('id'))
                    ->update(['status' => 'expired', 'decided_at' => Date::now()]);
            });
    }
};

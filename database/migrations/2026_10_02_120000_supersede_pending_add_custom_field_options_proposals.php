<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The add-options action is deleted, so approving a proposal filed for it would fail the allowlist.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pending_actions')
            ->where('action_class', 'App\Actions\CustomFields\AddCustomFieldOptions')
            ->where('status', 'pending')
            ->eachById(fn (object $row): int => DB::table('pending_actions')
                ->where('id', $row->id)
                ->update(['status' => 'superseded', 'resolved_at' => now(), 'updated_at' => now()]));
    }
};

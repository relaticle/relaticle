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
        Schema::table('team_email_blocklists', function (Blueprint $table): void {
            $table->string('enforcement_level', 20)->default('blocked')->after('value');
        });

        DB::table('team_email_blocklists')->update([
            'enforcement_level' => 'blocked',
        ]);

        if (! Schema::hasTable('protected_recipients')) {
            return;
        }

        $protectedRows = DB::table('protected_recipients')->get();

        foreach ($protectedRows as $row) {
            $exists = DB::table('team_email_blocklists')
                ->where('team_id', $row->team_id)
                ->where('type', $row->type)
                ->where('value', $row->value)
                ->exists();

            if ($exists) {
                DB::table('team_email_blocklists')
                    ->where('team_id', $row->team_id)
                    ->where('type', $row->type)
                    ->where('value', $row->value)
                    ->update(['enforcement_level' => 'protected']);

                continue;
            }

            DB::table('team_email_blocklists')->insert([
                'id' => (string) Str::ulid(),
                'team_id' => $row->team_id,
                'type' => $row->type,
                'value' => $row->value,
                'enforcement_level' => 'protected',
                'created_by' => $row->created_by,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }
};

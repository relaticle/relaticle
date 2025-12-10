<?php

declare(strict_types=1);

use App\Models\People;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('custom_fields')
            ->where('code', 'emails')
            ->where('entity_type', People::class)
            ->where('type', 'tags-input')
            ->update(['type' => 'email']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('custom_fields')
            ->where('code', 'emails')
            ->where('entity_type', People::class)
            ->where('type', 'email')
            ->update(['type' => 'tags-input']);
    }
};

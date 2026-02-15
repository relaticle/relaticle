<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasForeignKey = collect(Schema::getForeignKeys('sessions'))
            ->contains('name', 'sessions_user_id_foreign');

        if (! $hasForeignKey) {
            return;
        }

        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_administrators', function (Blueprint $table): void {
            // Nullable rather than defaulted to UTC: null means "never chose one", so
            // the panel can keep an unconfigured administrator on server time without
            // that being indistinguishable from a deliberate pick of UTC.
            $table->string('timezone')->nullable()->after('email');
        });
    }
};

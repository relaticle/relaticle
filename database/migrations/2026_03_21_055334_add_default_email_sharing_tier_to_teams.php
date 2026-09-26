<?php

declare(strict_types=1);

use App\Support\Migrations\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $personalColumn = TenantMigration::personalColumn();

        Schema::table(TenantMigration::table(), function (Blueprint $table) use ($personalColumn): void {
            $table->string('default_email_sharing_tier', 30)
                ->default('metadata_only')
                ->after($personalColumn);
            $table->string('contact_creation_mode', 20)
                ->default('selective')
                ->after('default_email_sharing_tier');
            $table->boolean('auto_create_companies')
                ->default(true)
                ->after('contact_creation_mode');
        });
    }
};

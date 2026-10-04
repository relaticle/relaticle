<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Relaticle\CustomFields\Console\Commands\Upgrade\UnmigratedRecordFields;
use Relaticle\CustomFields\Console\Commands\UpgradeCommand;
use Relaticle\CustomFields\Enums\CustomFieldsFeature;
use Relaticle\CustomFields\FeatureSystem\FeatureManager;

/*
 * A record field's target lives on its relationship definition from 4.0 on, so the column
 * that used to hold it goes. It is the last thing `custom-fields:upgrade` reads to build
 * those definitions, which is why this migration refuses to run before that command has:
 * dropping it first would strand every record field with links still in json_value and no
 * definition to read them from.
 *
 * A host with the relationships feature off never ran the two tables this depends on, so it
 * keeps the column. Record fields need the feature in 4.0; see the upgrade guide.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_RELATIONSHIPS)) {
            return;
        }

        $table = (string) config('custom-fields.database.table_names.custom_fields');

        if (! Schema::hasColumn($table, 'lookup_type')) {
            return;
        }

        $this->assertRecordLinksAreMigrated();

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('lookup_type');
        });
    }

    private function assertRecordLinksAreMigrated(): void
    {
        $codes = resolve(UnmigratedRecordFields::class)->withoutDefinition();

        if ($codes === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Cannot drop custom_fields.lookup_type: %s point at another entity through that column with no relationship definition to hold it. Run `php artisan custom-fields:upgrade` (step %s) first, then migrate again.',
            implode(', ', $codes),
            UpgradeCommand::STEP_MIGRATE_RECORD_LINKS,
        ));
    }
};

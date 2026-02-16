<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Consolidated ULID Migration
 *
 * Migrates the database from auto-increment bigint IDs to ULID primary keys.
 *
 * This migration handles:
 * - Core entity tables (users, teams, companies, people, opportunities, tasks, notes)
 * - Related entity tables (imports, exports, team_invitations, user_social_accounts, failed_import_rows)
 * - Pivot tables (team_user, task_user, taskables, noteables) - keep int id, convert foreign keys
 * - Polymorphic tables (notifications, media, ai_summaries, personal_access_tokens, custom_field_values)
 * - Tenant-scoped tables (custom_fields, custom_field_options, custom_field_sections, sessions)
 * - Email field data migration (string_value → json_value conversion for EmailFieldType)
 *
 * Migration Strategy:
 * PHASE A: Add ULID columns to ALL tables and populate values (while old integer IDs still exist)
 * PHASE B: Cutover - drop old columns, rename ULID columns, recreate foreign key constraints
 *
 * IMPORTANT: This migration is idempotent - it skips if tables already use ULIDs.
 */
return new class extends Migration
{
    /**
     * Core entity tables that need ULID primary keys.
     *
     * @var array<string>
     */
    private array $coreEntityTables = [
        'users',
        'teams',
        'companies',
        'people',
        'opportunities',
        'tasks',
        'notes',
    ];

    /**
     * Other entity tables that need ULID primary keys.
     *
     * @var array<string>
     */
    private array $otherEntityTables = [
        'imports',
        'exports',
        'team_invitations',
        'user_social_accounts',
        'failed_import_rows',
        'ai_summaries',
        'system_administrators',
    ];

    /**
     * Custom field tables that need ULID primary keys.
     * These have internal relationships (custom_field_id).
     *
     * @var array<string>
     */
    private array $customFieldTables = [
        'custom_fields',
        'custom_field_sections',
        'custom_field_options',
        'custom_field_values',
    ];

    /**
     * Map morph aliases to their tables.
     * CRITICAL: Use morph aliases (from Relation::enforceMorphMap) as keys,
     * NOT full class names, because the database stores aliases.
     *
     * @var array<string, string>
     */
    private array $morphTypes = [
        'user' => 'users',
        'team' => 'teams',
        'company' => 'companies',
        'people' => 'people',
        'opportunity' => 'opportunities',
        'task' => 'tasks',
        'note' => 'notes',
        'system_administrator' => 'system_administrators',
        'import' => 'imports',
        'export' => 'exports',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Allow long-running migration
        set_time_limit(0);
        ini_set('memory_limit', '2048M');

        // Disable query log to save memory on large datasets
        DB::disableQueryLog();

        $driver = DB::getDriverName();

        // Skip if already using ULID (fresh install or already migrated)
        if ($this->isAlreadyUlid('users', 'id')) {
            return;
        }

        if ($driver === 'sqlite') {
            $this->migrateForSqlite();
        } else {
            // MySQL, PostgreSQL, MariaDB
            $this->migrateForRelationalDb();
        }
    }

    /**
     * Migration for MySQL/PostgreSQL/MariaDB.
     *
     * Two-phase approach:
     * Phase A: Add ULID columns and populate (while old IDs exist for JOINs)
     * Phase B: Cutover (drop old columns, rename new ones)
     */
    private function migrateForRelationalDb(): void
    {
        Schema::disableForeignKeyConstraints();

        // =====================================================================
        // PHASE A: Add ULID columns and populate values (keep old IDs for JOINs)
        // =====================================================================

        // A1: Core entity tables - add ulid column and populate
        $this->phaseA_addUlidToCoreTables();

        // A2: Core entity tables - add foreign key ULID columns and populate
        $this->phaseA_addForeignUlidsToCoreTablesAndPopulate();

        // A3: Other entity tables - add ulid column and foreign ULID columns, populate
        $this->phaseA_addUlidsToOtherEntityTables();

        // A4: Pivot tables - add foreign ULID columns and populate
        $this->phaseA_addUlidsToPivotTables();

        // A5: Polymorphic tables - add morph ULID columns and populate
        // CRITICAL: Must run while old integer IDs still exist in core tables!
        $this->phaseA_addUlidsToPolymorphicTables();

        // A6: Tenant-scoped tables - add foreign ULID columns and populate
        $this->phaseA_addUlidsToTenantScopedTables();

        // A7: Custom field tables - add ulid and foreign ULID columns, populate
        $this->phaseA_addUlidsToCustomFieldTables();

        // A7b: Migrate SINGLE_CHOICE field value references (integer_value → string_value)
        // CRITICAL: Must run while option table still has integer IDs for lookup!
        $this->phaseA7b_migrateOptionValueReferences();

        // A8: Migrate email field data format (string_value → json_value)
        $this->phaseA8_migrateEmailFieldValues();

        // A9: Migrate domain field data format (string_value → json_value)
        $this->phaseA9_migrateDomainFieldValues();

        // =====================================================================
        // PHASE B: Cutover - drop old columns, rename ULID columns
        // =====================================================================

        // B0: Drop ALL foreign key constraints first (MySQL requirement)
        // MySQL won't allow dropping PRIMARY KEY if foreign keys reference it
        $this->phaseB_dropAllForeignKeyConstraints();

        // B1: Core entity tables - cutover primary keys
        $this->phaseB_cutoverCorePrimaryKeys();

        // B2: Core entity tables - cutover foreign keys
        $this->phaseB_cutoverCoreForeignKeys();

        // B3: Other entity tables - cutover
        $this->phaseB_cutoverOtherEntityTables();

        // B4: Pivot tables - cutover foreign keys
        $this->phaseB_cutoverPivotTables();

        // B5: Polymorphic tables - cutover morph columns
        $this->phaseB_cutoverPolymorphicTables();

        // B6: Tenant-scoped tables - cutover
        $this->phaseB_cutoverTenantScopedTables();

        // B7: Custom field tables - cutover primary keys and foreign keys
        $this->phaseB_cutoverCustomFieldTables();

        // B8: Recreate composite unique indexes with new ULID columns
        $this->phaseB_recreateUniqueIndexes();

        // B9: Recreate foreign key constraints with ULID references
        $this->phaseB9_recreateForeignKeyConstraints();

        Schema::enableForeignKeyConstraints();
    }

    // =========================================================================
    // PHASE A: Add ULID columns and populate
    // =========================================================================

    /**
     * A1: Add ULID column to core entity tables and populate with values.
     */
    private function phaseA_addUlidToCoreTables(): void
    {
        foreach ($this->coreEntityTables as $table) {
            // Add ulid column
            Schema::table($table, function (Blueprint $table): void {
                $table->ulid('ulid')->after('id')->nullable()->unique();
            });

            // Populate with ULID values
            DB::table($table)
                ->whereNull('ulid')
                ->lazyById(100)
                ->each(function ($record) use ($table): void {
                    DB::table($table)
                        ->where('id', $record->id)
                        ->update(['ulid' => (string) Str::ulid()]);
                });
        }
    }

    /**
     * A2: Add foreign key ULID columns to core tables and populate.
     */
    private function phaseA_addForeignUlidsToCoreTablesAndPopulate(): void
    {
        // Companies: team_id, creator_id, account_owner_id
        $this->addAndPopulateForeignUlid('companies', 'team_id', 'teams');
        $this->addAndPopulateForeignUlid('companies', 'creator_id', 'users');
        $this->addAndPopulateForeignUlid('companies', 'account_owner_id', 'users');

        // People: team_id, creator_id, company_id
        $this->addAndPopulateForeignUlid('people', 'team_id', 'teams');
        $this->addAndPopulateForeignUlid('people', 'creator_id', 'users');
        $this->addAndPopulateForeignUlid('people', 'company_id', 'companies');

        // Opportunities: team_id, creator_id, company_id, contact_id
        $this->addAndPopulateForeignUlid('opportunities', 'team_id', 'teams');
        $this->addAndPopulateForeignUlid('opportunities', 'creator_id', 'users');
        $this->addAndPopulateForeignUlid('opportunities', 'company_id', 'companies');
        $this->addAndPopulateForeignUlid('opportunities', 'contact_id', 'people');

        // Tasks: team_id, creator_id
        $this->addAndPopulateForeignUlid('tasks', 'team_id', 'teams');
        $this->addAndPopulateForeignUlid('tasks', 'creator_id', 'users');

        // Notes: team_id, creator_id
        $this->addAndPopulateForeignUlid('notes', 'team_id', 'teams');
        $this->addAndPopulateForeignUlid('notes', 'creator_id', 'users');

        // Users: current_team_id
        $this->addAndPopulateForeignUlid('users', 'current_team_id', 'teams');

        // Teams: user_id
        $this->addAndPopulateForeignUlid('teams', 'user_id', 'users');
    }

    /**
     * A3: Add ULID columns to other entity tables and populate.
     */
    private function phaseA_addUlidsToOtherEntityTables(): void
    {
        // imports: id (primary), team_id, user_id
        $this->addUlidPrimaryKeyColumn('imports');
        $this->addAndPopulateForeignUlid('imports', 'team_id', 'teams');
        $this->addAndPopulateForeignUlid('imports', 'user_id', 'users');

        // exports: id (primary), team_id, user_id
        $this->addUlidPrimaryKeyColumn('exports');
        $this->addAndPopulateForeignUlid('exports', 'team_id', 'teams');
        $this->addAndPopulateForeignUlid('exports', 'user_id', 'users');

        // team_invitations: id (primary), team_id
        $this->addUlidPrimaryKeyColumn('team_invitations');
        $this->addAndPopulateForeignUlid('team_invitations', 'team_id', 'teams');

        // user_social_accounts: id (primary), user_id
        $this->addUlidPrimaryKeyColumn('user_social_accounts');
        $this->addAndPopulateForeignUlid('user_social_accounts', 'user_id', 'users');

        // failed_import_rows: id (primary), team_id, import_id
        $this->addUlidPrimaryKeyColumn('failed_import_rows');
        $this->addAndPopulateForeignUlid('failed_import_rows', 'team_id', 'teams');
        // Note: import_id references imports which still has integer id at this point
        $this->addAndPopulateForeignUlid('failed_import_rows', 'import_id', 'imports');

        // ai_summaries: id (primary), team_id
        // Note: summarizable_id is already char(26), so only need primary key and team_id
        $this->addUlidPrimaryKeyColumn('ai_summaries');
        $this->addAndPopulateForeignUlid('ai_summaries', 'team_id', 'teams');

        // system_administrators: id (primary only, no foreign keys)
        $this->addUlidPrimaryKeyColumn('system_administrators');
    }

    /**
     * A4: Add ULID columns to pivot tables and populate.
     * Pivot tables keep their integer primary key, only foreign keys are converted.
     */
    private function phaseA_addUlidsToPivotTables(): void
    {
        // team_user: team_id, user_id
        $this->addAndPopulateForeignUlid('team_user', 'team_id', 'teams');
        $this->addAndPopulateForeignUlid('team_user', 'user_id', 'users');

        // task_user: task_id, user_id
        $this->addAndPopulateForeignUlid('task_user', 'task_id', 'tasks');
        $this->addAndPopulateForeignUlid('task_user', 'user_id', 'users');

        // taskables: task_id, taskable_id (morph)
        $this->addAndPopulateForeignUlid('taskables', 'task_id', 'tasks');
        $this->addAndPopulateMorphUlid('taskables', 'taskable');

        // noteables: note_id, noteable_id (morph)
        $this->addAndPopulateForeignUlid('noteables', 'note_id', 'notes');
        $this->addAndPopulateMorphUlid('noteables', 'noteable');
    }

    /**
     * A5: Add ULID columns to polymorphic tables and populate.
     * CRITICAL: Must run while old integer IDs still exist!
     */
    private function phaseA_addUlidsToPolymorphicTables(): void
    {
        // notifications: notifiable_id
        $this->addAndPopulateMorphUlid('notifications', 'notifiable');

        // media: model_id
        $this->addAndPopulateMorphUlid('media', 'model');

        // ai_summaries: summarizable_id
        $this->addAndPopulateMorphUlid('ai_summaries', 'summarizable');

        // personal_access_tokens: tokenable_id
        $this->addAndPopulateMorphUlid('personal_access_tokens', 'tokenable');

        // custom_field_values: entity_id
        $this->addAndPopulateMorphUlid('custom_field_values', 'entity');
    }

    /**
     * A6: Add ULID columns to tenant-scoped tables and populate.
     */
    private function phaseA_addUlidsToTenantScopedTables(): void
    {
        // sessions: user_id
        $this->addAndPopulateForeignUlid('sessions', 'user_id', 'users');

        // custom_fields: tenant_id
        $this->addAndPopulateForeignUlid('custom_fields', 'tenant_id', 'teams');

        // custom_field_options: tenant_id
        $this->addAndPopulateForeignUlid('custom_field_options', 'tenant_id', 'teams');

        // custom_field_sections: tenant_id
        $this->addAndPopulateForeignUlid('custom_field_sections', 'tenant_id', 'teams');

        // custom_field_values: tenant_id (entity_id already handled in polymorphic)
        $this->addAndPopulateForeignUlid('custom_field_values', 'tenant_id', 'teams');
    }

    /**
     * A7: Add ULID columns to custom field tables and populate.
     */
    private function phaseA_addUlidsToCustomFieldTables(): void
    {
        // Add ULID primary key columns to all custom field tables
        foreach ($this->customFieldTables as $table) {
            $this->addUlidPrimaryKeyColumn($table);
        }

        // custom_fields: custom_field_section_id references custom_field_sections
        $this->addAndPopulateForeignUlid('custom_fields', 'custom_field_section_id', 'custom_field_sections');

        // custom_field_options: custom_field_id references custom_fields
        $this->addAndPopulateForeignUlid('custom_field_options', 'custom_field_id', 'custom_fields');

        // custom_field_values: custom_field_id references custom_fields
        $this->addAndPopulateForeignUlid('custom_field_values', 'custom_field_id', 'custom_fields');
    }

    /**
     * A7b: Migrate SINGLE_CHOICE field value references from integer_value to string_value.
     *
     * Maps old integer option IDs to new ULID option IDs.
     * CRITICAL: Must run while custom_field_options still has both 'id' (integer) and 'ulid' columns.
     *
     * IDEMPOTENT: Only migrates values with non-null integer_value.
     */
    private function phaseA7b_migrateOptionValueReferences(): void
    {
        $fieldTable = 'custom_fields';
        $valueTable = 'custom_field_values';
        $optionTable = 'custom_field_options';

        // Find all SINGLE_CHOICE type fields (select, radio)
        $singleChoiceFieldIds = DB::table($fieldTable)
            ->whereIn('type', ['select', 'radio'])
            ->pluck('id');

        if ($singleChoiceFieldIds->isEmpty()) {
            return; // No single-choice fields
        }

        // Migrate in chunks to avoid memory issues
        DB::table($valueTable)
            ->whereIn('custom_field_id', $singleChoiceFieldIds)
            ->whereNotNull('integer_value')
            ->lazyById(100)
            ->each(function ($value) use ($valueTable, $optionTable): void {
                // Get the option's new ULID using the old integer id stored in integer_value
                // At this point, options still have both 'id' (original integer) and 'ulid' columns
                $optionUlid = DB::table($optionTable)
                    ->where('id', $value->integer_value)
                    ->value('ulid');

                if ($optionUlid) {
                    DB::table($valueTable)
                        ->where('id', $value->id)
                        ->update([
                            'string_value' => $optionUlid,
                            'integer_value' => null,
                        ]);
                }
            });
    }

    /**
     * A8: Migrate email field values from string_value to json_value format.
     *
     * EmailFieldType was changed from STRING data type (string_value) to
     * MULTI_CHOICE (json_value array format) to support multiple emails.
     *
     * IDEMPOTENT: Only migrates values with non-empty string_value and empty json_value.
     */
    private function phaseA8_migrateEmailFieldValues(): void
    {
        $fieldTable = 'custom_fields';
        $valueTable = 'custom_field_values';

        // Find all email type custom fields (using integer id, we're in Phase A)
        $emailFieldIds = DB::table($fieldTable)
            ->where('type', 'email')
            ->pluck('id');

        if ($emailFieldIds->isEmpty()) {
            return; // No email fields
        }

        // Find values needing migration: has string_value, empty/null json_value
        $valuesToMigrate = DB::table($valueTable)
            ->whereIn('custom_field_id', $emailFieldIds)
            ->whereNotNull('string_value')
            ->where('string_value', '!=', '')
            ->where(function (\Illuminate\Contracts\Database\Query\Builder $query): void {
                $query->whereNull('json_value')
                    ->orWhere('json_value', '=', '[]')
                    ->orWhere('json_value', '=', 'null');
            })
            ->select(['id', 'string_value'])
            ->get();

        if ($valuesToMigrate->isEmpty()) {
            return; // Already migrated
        }

        // Migrate in chunks to avoid memory issues
        foreach ($valuesToMigrate->chunk(100) as $chunk) {
            foreach ($chunk as $value) {
                DB::table($valueTable)
                    ->where('id', $value->id)
                    ->update([
                        'json_value' => json_encode([$value->string_value]),
                        'string_value' => null,
                    ]);
            }
        }
    }

    /**
     * A9: Migrate domain field values from string_value to json_value format.
     *
     * Domains field (company) was changed from STRING data type (string_value) to
     * array format (json_value) to support multiple domains.
     *
     * IDEMPOTENT: Only migrates values with non-empty string_value and empty json_value.
     */
    private function phaseA9_migrateDomainFieldValues(): void
    {
        $fieldTable = 'custom_fields';
        $valueTable = 'custom_field_values';

        // Find all domains custom fields (code 'domains' or legacy 'domain_name')
        $domainFieldIds = DB::table($fieldTable)
            ->where('entity_type', 'company')
            ->where(function (\Illuminate\Contracts\Database\Query\Builder $query): void {
                $query->where('code', 'domains')
                    ->orWhere('code', 'domain_name');
            })
            ->pluck('id');

        if ($domainFieldIds->isEmpty()) {
            return; // No domain fields
        }

        // Find values needing migration: has string_value, empty/null json_value
        $valuesToMigrate = DB::table($valueTable)
            ->whereIn('custom_field_id', $domainFieldIds)
            ->whereNotNull('string_value')
            ->where('string_value', '!=', '')
            ->where(function (\Illuminate\Contracts\Database\Query\Builder $query): void {
                $query->whereNull('json_value')
                    ->orWhere('json_value', '=', '[]')
                    ->orWhere('json_value', '=', 'null');
            })
            ->select(['id', 'string_value'])
            ->get();

        if ($valuesToMigrate->isEmpty()) {
            return; // Already migrated
        }

        // Migrate in chunks to avoid memory issues
        foreach ($valuesToMigrate->chunk(100) as $chunk) {
            foreach ($chunk as $value) {
                // Handle comma-separated domains
                $domains = array_filter(
                    array_map(trim(...), explode(',', (string) $value->string_value)),
                    fn ($d): bool => $d !== ''
                );

                DB::table($valueTable)
                    ->where('id', $value->id)
                    ->update([
                        'json_value' => json_encode(array_values($domains)),
                        'string_value' => null,
                    ]);
            }
        }
    }

    // =========================================================================
    // PHASE B: Cutover - drop old columns, rename ULID columns
    // =========================================================================

    /**
     * B0: Drop ALL foreign key constraints and composite unique indexes.
     *
     * MySQL: Cannot drop PRIMARY KEY if foreign keys reference it,
     *        cannot drop column if it's part of a unique index.
     * PostgreSQL: Same logical requirement, different SQL syntax.
     */
    private function phaseB_dropAllForeignKeyConstraints(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $foreignKeys = DB::select("
                SELECT tc.table_name, tc.constraint_name
                FROM information_schema.table_constraints tc
                WHERE tc.constraint_type = 'FOREIGN KEY'
                AND tc.table_schema = 'public'
            ");

            foreach ($foreignKeys as $fk) {
                try {
                    DB::statement("ALTER TABLE \"{$fk->table_name}\" DROP CONSTRAINT IF EXISTS \"{$fk->constraint_name}\"");
                } catch (\Throwable) {
                }
            }

            $indexesToDrop = [
                'team_invitations_team_id_email_unique',
                'team_user_team_id_user_id_unique',
                'ai_summaries_summarizable_type_summarizable_id_team_id_unique',
                'custom_field_options_custom_field_id_name_tenant_id_unique',
                'custom_field_sections_entity_type_code_tenant_id_unique',
                'custom_field_values_entity_type_unique',
                'custom_fields_code_entity_type_tenant_id_unique',
            ];

            foreach ($indexesToDrop as $indexName) {
                try {
                    DB::statement("DROP INDEX IF EXISTS \"{$indexName}\"");
                } catch (\Throwable) {
                }
            }
        } else {
            $foreignKeys = DB::select('
                SELECT TABLE_NAME, CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE REFERENCED_TABLE_NAME IS NOT NULL
                AND TABLE_SCHEMA = DATABASE()
            ');

            foreach ($foreignKeys as $fk) {
                try {
                    DB::statement("ALTER TABLE `{$fk->TABLE_NAME}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                } catch (\Throwable) {
                }
            }

            $indexesToDrop = [
                'team_invitations' => 'team_invitations_team_id_email_unique',
                'team_user' => 'team_user_team_id_user_id_unique',
                'ai_summaries' => 'ai_summaries_summarizable_type_summarizable_id_team_id_unique',
                'custom_field_options' => 'custom_field_options_custom_field_id_name_tenant_id_unique',
                'custom_field_sections' => 'custom_field_sections_entity_type_code_tenant_id_unique',
                'custom_field_values' => 'custom_field_values_entity_type_unique',
                'custom_fields' => 'custom_fields_code_entity_type_tenant_id_unique',
            ];

            foreach ($indexesToDrop as $table => $indexName) {
                try {
                    DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * B1: Cutover core table primary keys.
     */
    private function phaseB_cutoverCorePrimaryKeys(): void
    {
        foreach ($this->coreEntityTables as $table) {
            $this->cutoverPrimaryKey($table);
        }
    }

    /**
     * B2: Cutover core table foreign keys.
     */
    private function phaseB_cutoverCoreForeignKeys(): void
    {
        // Companies
        $this->cutoverForeignKeyColumn('companies', 'team_id');
        $this->cutoverForeignKeyColumn('companies', 'creator_id');
        $this->cutoverForeignKeyColumn('companies', 'account_owner_id');

        // People
        $this->cutoverForeignKeyColumn('people', 'team_id');
        $this->cutoverForeignKeyColumn('people', 'creator_id');
        $this->cutoverForeignKeyColumn('people', 'company_id');

        // Opportunities
        $this->cutoverForeignKeyColumn('opportunities', 'team_id');
        $this->cutoverForeignKeyColumn('opportunities', 'creator_id');
        $this->cutoverForeignKeyColumn('opportunities', 'company_id');
        $this->cutoverForeignKeyColumn('opportunities', 'contact_id');

        // Tasks
        $this->cutoverForeignKeyColumn('tasks', 'team_id');
        $this->cutoverForeignKeyColumn('tasks', 'creator_id');

        // Notes
        $this->cutoverForeignKeyColumn('notes', 'team_id');
        $this->cutoverForeignKeyColumn('notes', 'creator_id');

        // Users
        $this->cutoverForeignKeyColumn('users', 'current_team_id');

        // Teams
        $this->cutoverForeignKeyColumn('teams', 'user_id');
    }

    /**
     * B3: Cutover other entity tables.
     */
    private function phaseB_cutoverOtherEntityTables(): void
    {
        foreach ($this->otherEntityTables as $table) {
            $this->cutoverPrimaryKey($table);
        }

        // Cutover foreign keys
        $this->cutoverForeignKeyColumn('imports', 'team_id');
        $this->cutoverForeignKeyColumn('imports', 'user_id');

        $this->cutoverForeignKeyColumn('exports', 'team_id');
        $this->cutoverForeignKeyColumn('exports', 'user_id');

        $this->cutoverForeignKeyColumn('team_invitations', 'team_id');

        $this->cutoverForeignKeyColumn('user_social_accounts', 'user_id');

        $this->cutoverForeignKeyColumn('failed_import_rows', 'team_id');
        $this->cutoverForeignKeyColumn('failed_import_rows', 'import_id');

        $this->cutoverForeignKeyColumn('ai_summaries', 'team_id');
    }

    /**
     * B4: Cutover pivot tables (foreign keys only, keep integer id).
     */
    private function phaseB_cutoverPivotTables(): void
    {
        // team_user
        $this->cutoverForeignKeyColumn('team_user', 'team_id');
        $this->cutoverForeignKeyColumn('team_user', 'user_id');

        // task_user
        $this->cutoverForeignKeyColumn('task_user', 'task_id');
        $this->cutoverForeignKeyColumn('task_user', 'user_id');

        // taskables
        $this->cutoverForeignKeyColumn('taskables', 'task_id');
        $this->cutoverMorphColumn('taskables', 'taskable');

        // noteables
        $this->cutoverForeignKeyColumn('noteables', 'note_id');
        $this->cutoverMorphColumn('noteables', 'noteable');
    }

    /**
     * B5: Cutover polymorphic tables.
     */
    private function phaseB_cutoverPolymorphicTables(): void
    {
        $this->cutoverMorphColumn('notifications', 'notifiable');
        $this->cutoverMorphColumn('media', 'model');
        $this->cutoverMorphColumn('ai_summaries', 'summarizable');
        $this->cutoverMorphColumn('personal_access_tokens', 'tokenable');
        $this->cutoverMorphColumn('custom_field_values', 'entity');
    }

    /**
     * B6: Cutover tenant-scoped tables.
     */
    private function phaseB_cutoverTenantScopedTables(): void
    {
        $this->cutoverForeignKeyColumn('sessions', 'user_id');
        $this->cutoverForeignKeyColumn('custom_fields', 'tenant_id');
        $this->cutoverForeignKeyColumn('custom_field_options', 'tenant_id');
        $this->cutoverForeignKeyColumn('custom_field_sections', 'tenant_id');
        $this->cutoverForeignKeyColumn('custom_field_values', 'tenant_id');
    }

    /**
     * B7: Cutover custom field tables - primary keys and foreign keys.
     */
    private function phaseB_cutoverCustomFieldTables(): void
    {
        foreach ($this->customFieldTables as $table) {
            $this->cutoverPrimaryKey($table);
        }

        // Cutover foreign keys
        $this->cutoverForeignKeyColumn('custom_fields', 'custom_field_section_id');
        $this->cutoverForeignKeyColumn('custom_field_options', 'custom_field_id');
        $this->cutoverForeignKeyColumn('custom_field_values', 'custom_field_id');
    }

    /**
     * B8: Recreate composite unique indexes with new ULID columns.
     */
    private function phaseB_recreateUniqueIndexes(): void
    {
        // team_invitations: (team_id, email) unique
        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->unique(['team_id', 'email'], 'team_invitations_team_id_email_unique');
        });

        // team_user: (team_id, user_id) unique
        Schema::table('team_user', function (Blueprint $table): void {
            $table->unique(['team_id', 'user_id'], 'team_user_team_id_user_id_unique');
        });

        // ai_summaries: (summarizable_type, summarizable_id, team_id) unique
        Schema::table('ai_summaries', function (Blueprint $table): void {
            $table->unique(['summarizable_type', 'summarizable_id', 'team_id'], 'ai_summaries_summarizable_type_summarizable_id_team_id_unique');
        });

        // custom_field_options: (custom_field_id, name, tenant_id) unique
        Schema::table('custom_field_options', function (Blueprint $table): void {
            $table->unique(['custom_field_id', 'name', 'tenant_id'], 'custom_field_options_custom_field_id_name_tenant_id_unique');
        });

        // custom_field_sections: (entity_type, code, tenant_id) unique
        Schema::table('custom_field_sections', function (Blueprint $table): void {
            $table->unique(['entity_type', 'code', 'tenant_id'], 'custom_field_sections_entity_type_code_tenant_id_unique');
        });

        // custom_field_values: (entity_type, entity_id, custom_field_id, tenant_id) unique
        Schema::table('custom_field_values', function (Blueprint $table): void {
            $table->unique(['entity_type', 'entity_id', 'custom_field_id', 'tenant_id'], 'custom_field_values_entity_type_unique');
        });

        // custom_fields: (code, entity_type, tenant_id) unique
        Schema::table('custom_fields', function (Blueprint $table): void {
            $table->unique(['code', 'entity_type', 'tenant_id'], 'custom_fields_code_entity_type_tenant_id_unique');
        });
    }

    /**
     * B9: Recreate foreign key constraints with ULID references.
     *
     * CRITICAL: Foreign keys were dropped in B0, must be recreated
     * to restore referential integrity.
     */
    private function phaseB9_recreateForeignKeyConstraints(): void
    {
        // Core entity foreign keys
        Schema::table('companies', function (Blueprint $table): void {
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('creator_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('account_owner_id')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('people', function (Blueprint $table): void {
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('creator_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('creator_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('people')->onDelete('set null');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('creator_id')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('notes', function (Blueprint $table): void {
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('creator_id')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('current_team_id')->references('id')->on('teams')->onDelete('set null');
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Related entity foreign keys
        Schema::table('imports', function (Blueprint $table): void {
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('exports', function (Blueprint $table): void {
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
        });

        Schema::table('user_social_accounts', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('failed_import_rows', function (Blueprint $table): void {
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('import_id')->references('id')->on('imports')->onDelete('cascade');
        });

        Schema::table('ai_summaries', function (Blueprint $table): void {
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
        });

        // Pivot table foreign keys
        Schema::table('team_user', function (Blueprint $table): void {
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('task_user', function (Blueprint $table): void {
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('taskables', function (Blueprint $table): void {
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
            // taskable_id is polymorphic, no FK constraint
        });

        Schema::table('noteables', function (Blueprint $table): void {
            $table->foreign('note_id')->references('id')->on('notes')->onDelete('cascade');
            // noteable_id is polymorphic, no FK constraint
        });

        // Tenant-scoped table foreign keys
        Schema::table('sessions', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('custom_fields', function (Blueprint $table): void {
            $table->foreign('tenant_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('custom_field_section_id')->references('id')->on('custom_field_sections')->onDelete('cascade');
        });

        Schema::table('custom_field_options', function (Blueprint $table): void {
            $table->foreign('tenant_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('custom_field_id')->references('id')->on('custom_fields')->onDelete('cascade');
        });

        Schema::table('custom_field_sections', function (Blueprint $table): void {
            $table->foreign('tenant_id')->references('id')->on('teams')->onDelete('cascade');
        });

        Schema::table('custom_field_values', function (Blueprint $table): void {
            $table->foreign('tenant_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('custom_field_id')->references('id')->on('custom_fields')->onDelete('cascade');
            // entity_id is polymorphic, no FK constraint
        });
    }

    // =========================================================================
    // Helper Methods - Phase A (Add and Populate)
    // =========================================================================

    /**
     * Add a ULID column for primary key and populate with values.
     */
    private function addUlidPrimaryKeyColumn(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table): void {
            $table->ulid('ulid')->after('id')->nullable();
        });

        DB::table($tableName)
            ->whereNull('ulid')
            ->lazyById(100)
            ->each(function ($record) use ($tableName): void {
                DB::table($tableName)
                    ->where('id', $record->id)
                    ->update(['ulid' => (string) Str::ulid()]);
            });
    }

    /**
     * Add a ULID foreign key column and populate by looking up the referenced table.
     */
    private function addAndPopulateForeignUlid(string $tableName, string $fkColumn, string $refTable): void
    {
        $ulidColumn = $fkColumn.'_ulid';

        // Add ULID column
        Schema::table($tableName, function (Blueprint $table) use ($fkColumn, $ulidColumn): void {
            $table->char($ulidColumn, 26)->nullable()->after($fkColumn);
        });

        // Populate by looking up the referenced table's ulid column
        // At this point, referenced tables have both 'id' (integer) and 'ulid' columns
        DB::statement("
            UPDATE {$tableName}
            SET {$ulidColumn} = (
                SELECT ulid FROM {$refTable} WHERE {$refTable}.id = {$tableName}.{$fkColumn}
            )
            WHERE {$fkColumn} IS NOT NULL
        ");
    }

    /**
     * Add a ULID morph column and populate by looking up each morph type.
     * Handles both morph aliases ('company') and full class names ('App\Models\Company').
     */
    private function addAndPopulateMorphUlid(string $tableName, string $morphName): void
    {
        $morphIdColumn = $morphName.'_id';
        $morphTypeColumn = $morphName.'_type';
        $morphUlidColumn = $morphIdColumn.'_ulid';

        // Add ULID column
        Schema::table($tableName, function (Blueprint $table) use ($morphIdColumn, $morphUlidColumn): void {
            $table->char($morphUlidColumn, 26)->nullable()->after($morphIdColumn);
        });

        // Populate for each morph type using aliases (e.g., 'company', 'people')
        // These match what's stored in the database via Relation::enforceMorphMap()
        foreach ($this->morphTypes as $morphAlias => $refTable) {
            // At this point, core tables have both 'id' (integer) and 'ulid' columns
            DB::statement("
                UPDATE {$tableName}
                SET {$morphUlidColumn} = (
                    SELECT ulid FROM {$refTable} WHERE {$refTable}.id = {$tableName}.{$morphIdColumn}
                )
                WHERE {$morphTypeColumn} = '{$morphAlias}'
                AND {$morphIdColumn} IS NOT NULL
            ");
        }

        // SPECIAL CASE: Handle full class names (e.g., Spatie Media Library)
        // Check if any rows still have NULL after alias-based population
        $nullCount = DB::table($tableName)
            ->whereNull($morphUlidColumn)
            ->whereNotNull($morphIdColumn)
            ->count();

        if ($nullCount > 0) {
            // Populate using full class name mapping
            $fullClassMap = [
                \App\Models\User::class => 'users',
                \App\Models\Team::class => 'teams',
                \App\Models\Company::class => 'companies',
                \App\Models\People::class => 'people',
                \App\Models\Opportunity::class => 'opportunities',
                \App\Models\Task::class => 'tasks',
                \App\Models\Note::class => 'notes',
                'App\\Models\\Import' => 'imports',
                \App\Models\Export::class => 'exports',
                'App\\Models\\SystemAdministrator' => 'system_administrators',
            ];

            foreach ($fullClassMap as $fullClass => $refTable) {
                DB::statement("
                    UPDATE {$tableName}
                    SET {$morphUlidColumn} = (
                        SELECT ulid FROM {$refTable} WHERE {$refTable}.id = {$tableName}.{$morphIdColumn}
                    )
                    WHERE {$morphTypeColumn} = '{$fullClass}'
                    AND {$morphIdColumn} IS NOT NULL
                ");
            }
        }
    }

    // =========================================================================
    // Helper Methods - Phase B (Cutover)
    // =========================================================================

    /**
     * Cutover a primary key from integer to ULID.
     *
     * MySQL requires removing AUTO_INCREMENT before dropping the primary key (Error 1075).
     * PostgreSQL uses sequences — this step is unnecessary and skipped.
     */
    private function cutoverPrimaryKey(string $table): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('id')->change();
            });
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropPrimary(['id']);
        });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('id');
        });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->renameColumn('ulid', 'id');
        });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->primary('id');
        });
    }

    /**
     * Cutover a foreign key column: drop old, rename ULID column.
     */
    private function cutoverForeignKeyColumn(string $tableName, string $fkColumn): void
    {
        $ulidColumn = $fkColumn.'_ulid';

        // Drop old integer column
        Schema::table($tableName, function (Blueprint $table) use ($fkColumn): void {
            $table->dropColumn($fkColumn);
        });

        // Rename ULID column to original name
        Schema::table($tableName, function (Blueprint $table) use ($fkColumn, $ulidColumn): void {
            $table->renameColumn($ulidColumn, $fkColumn);
        });
    }

    /**
     * Cutover a morph column: drop old, rename ULID column, recreate index.
     */
    private function cutoverMorphColumn(string $tableName, string $morphName): void
    {
        $morphIdColumn = $morphName.'_id';
        $morphTypeColumn = $morphName.'_type';
        $morphUlidColumn = $morphIdColumn.'_ulid';

        // Drop index if exists
        try {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $morphTypeColumn, $morphIdColumn): void {
                $table->dropIndex("{$tableName}_{$morphTypeColumn}_{$morphIdColumn}_index");
            });
        } catch (\Throwable) {
            // Index might not exist
        }

        // Drop old morph id column
        Schema::table($tableName, function (Blueprint $table) use ($morphIdColumn): void {
            $table->dropColumn($morphIdColumn);
        });

        // Rename ULID column to original name
        Schema::table($tableName, function (Blueprint $table) use ($morphIdColumn, $morphUlidColumn): void {
            $table->renameColumn($morphUlidColumn, $morphIdColumn);
        });

        // Recreate index
        Schema::table($tableName, function (Blueprint $table) use ($morphTypeColumn, $morphIdColumn): void {
            $table->index([$morphTypeColumn, $morphIdColumn]);
        });
    }

    // =========================================================================
    // SQLite Implementation (for tests)
    // =========================================================================

    /**
     * Migration for SQLite (used in tests).
     * Uses table rebuild approach due to SQLite limitations.
     */
    private function migrateForSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        // SQLite requires table rebuilds to change column types
        // This simplified approach works for fresh test databases

        // Core entity tables
        foreach ($this->coreEntityTables as $table) {
            $this->rebuildTableWithUlidPrimarySqlite($table);
        }

        // Other entity tables
        foreach ($this->otherEntityTables as $table) {
            $this->rebuildTableWithUlidPrimarySqlite($table);
        }

        // Pivot tables - rebuild with ULID foreign keys (keep integer id)
        $this->rebuildPivotTableSqlite('team_user', ['team_id', 'user_id']);
        $this->rebuildPivotTableSqlite('task_user', ['task_id', 'user_id']);
        $this->rebuildPivotWithMorphSqlite('taskables', 'task_id', 'taskable_id');
        $this->rebuildPivotWithMorphSqlite('noteables', 'note_id', 'noteable_id');

        // Polymorphic tables - rebuild with ULID morph columns
        $this->rebuildMorphTableSqlite('notifications', 'notifiable_id');
        $this->rebuildMorphTableSqlite('media', 'model_id');
        $this->rebuildMorphTableSqlite('ai_summaries', 'summarizable_id');
        $this->rebuildMorphTableSqlite('personal_access_tokens', 'tokenable_id');
        $this->rebuildMorphTableSqlite('custom_field_values', 'entity_id');

        // Tenant-scoped tables - rebuild foreign keys
        $this->rebuildForeignKeysSqlite('sessions', ['user_id']);
        $this->rebuildForeignKeysSqlite('custom_fields', ['tenant_id']);
        $this->rebuildForeignKeysSqlite('custom_field_options', ['tenant_id']);
        $this->rebuildForeignKeysSqlite('custom_field_sections', ['tenant_id']);
        // custom_field_values tenant_id already handled above

        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Rebuild a table with ULID primary key (SQLite).
     */
    private function rebuildTableWithUlidPrimarySqlite(string $tableName): void
    {
        $columns = DB::select("PRAGMA table_info({$tableName})");
        $columnDefs = [];
        $columnNames = [];

        foreach ($columns as $col) {
            $columnNames[] = $col->name;
            if ($col->name === 'id') {
                // Change id to TEXT for ULID
                $columnDefs[] = 'id TEXT PRIMARY KEY';
            } else {
                $type = $col->type;
                $nullable = $col->notnull ? '' : '';
                $default = $col->dflt_value !== null ? " DEFAULT {$col->dflt_value}" : '';
                $columnDefs[] = "{$col->name} {$type}{$nullable}{$default}";
            }
        }

        // Create new table
        $columnDefsStr = implode(', ', $columnDefs);
        DB::statement("CREATE TABLE {$tableName}_new ({$columnDefsStr})");

        // Copy data (excluding id, we'll generate new ULIDs)
        $otherColumns = array_filter($columnNames, fn ($c): bool => $c !== 'id');
        if ($otherColumns !== []) {
            $otherColumnsStr = implode(', ', $otherColumns);
            $rows = DB::select("SELECT {$otherColumnsStr} FROM {$tableName}");
            foreach ($rows as $row) {
                $values = [(string) Str::ulid()];
                $placeholders = ['?'];
                foreach ($otherColumns as $col) {
                    $values[] = $row->$col;
                    $placeholders[] = '?';
                }
                $placeholdersStr = implode(', ', $placeholders);
                DB::insert("INSERT INTO {$tableName}_new (id, {$otherColumnsStr}) VALUES ({$placeholdersStr})", $values);
            }
        }

        // Swap tables
        DB::statement("DROP TABLE {$tableName}");
        DB::statement("ALTER TABLE {$tableName}_new RENAME TO {$tableName}");
    }

    /**
     * Rebuild pivot table with ULID foreign keys (SQLite).
     *
     * @param  array<string>  $foreignKeys
     */
    private function rebuildPivotTableSqlite(string $tableName, array $foreignKeys): void
    {
        $columns = DB::select("PRAGMA table_info({$tableName})");
        $columnDefs = [];
        $columnNames = [];

        foreach ($columns as $col) {
            $columnNames[] = $col->name;
            if (in_array($col->name, $foreignKeys, true)) {
                // Change foreign keys to TEXT for ULID
                $columnDefs[] = "{$col->name} TEXT";
            } else {
                $type = $col->type;
                $pk = $col->pk ? ' PRIMARY KEY AUTOINCREMENT' : '';
                $columnDefs[] = "{$col->name} {$type}{$pk}";
            }
        }

        $columnDefsStr = implode(', ', $columnDefs);
        DB::statement("CREATE TABLE {$tableName}_new ({$columnDefsStr})");

        // No data to copy for fresh tests
        DB::statement("DROP TABLE {$tableName}");
        DB::statement("ALTER TABLE {$tableName}_new RENAME TO {$tableName}");
    }

    /**
     * Rebuild pivot table with morph (SQLite).
     */
    private function rebuildPivotWithMorphSqlite(string $tableName, string $fkColumn, string $morphIdColumn): void
    {
        $columns = DB::select("PRAGMA table_info({$tableName})");
        $columnDefs = [];

        foreach ($columns as $col) {
            if ($col->name === $fkColumn || $col->name === $morphIdColumn) {
                $columnDefs[] = "{$col->name} TEXT";
            } else {
                $type = $col->type;
                $pk = $col->pk ? ' PRIMARY KEY AUTOINCREMENT' : '';
                $columnDefs[] = "{$col->name} {$type}{$pk}";
            }
        }

        $columnDefsStr = implode(', ', $columnDefs);
        DB::statement("CREATE TABLE {$tableName}_new ({$columnDefsStr})");
        DB::statement("DROP TABLE {$tableName}");
        DB::statement("ALTER TABLE {$tableName}_new RENAME TO {$tableName}");
    }

    /**
     * Rebuild morph table (SQLite).
     */
    private function rebuildMorphTableSqlite(string $tableName, string $morphIdColumn): void
    {
        $columns = DB::select("PRAGMA table_info({$tableName})");
        $columnDefs = [];

        foreach ($columns as $col) {
            if ($col->name === $morphIdColumn) {
                $columnDefs[] = "{$col->name} TEXT";
            } else {
                $type = $col->type;
                $pk = $col->pk ? ' PRIMARY KEY AUTOINCREMENT' : '';
                $columnDefs[] = "{$col->name} {$type}{$pk}";
            }
        }

        $columnDefsStr = implode(', ', $columnDefs);
        DB::statement("CREATE TABLE {$tableName}_new ({$columnDefsStr})");
        DB::statement("DROP TABLE {$tableName}");
        DB::statement("ALTER TABLE {$tableName}_new RENAME TO {$tableName}");
    }

    /**
     * Rebuild table with ULID foreign keys (SQLite).
     *
     * @param  array<string>  $foreignKeys
     */
    private function rebuildForeignKeysSqlite(string $tableName, array $foreignKeys): void
    {
        $columns = DB::select("PRAGMA table_info({$tableName})");
        $columnDefs = [];

        foreach ($columns as $col) {
            if (in_array($col->name, $foreignKeys, true)) {
                $columnDefs[] = "{$col->name} TEXT";
            } else {
                $type = $col->type;
                $pk = $col->pk ? ' PRIMARY KEY' : '';
                $columnDefs[] = "{$col->name} {$type}{$pk}";
            }
        }

        $columnDefsStr = implode(', ', $columnDefs);
        DB::statement("CREATE TABLE {$tableName}_new ({$columnDefsStr})");
        DB::statement("DROP TABLE {$tableName}");
        DB::statement("ALTER TABLE {$tableName}_new RENAME TO {$tableName}");
    }

    // =========================================================================
    // Utility Methods
    // =========================================================================

    /**
     * Check if a column is already using ULID (char/string type).
     */
    private function isAlreadyUlid(string $table, string $column): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return false;
        }

        $columnType = Schema::getColumnType($table, $column);

        return in_array($columnType, ['string', 'char', 'varchar', 'bpchar', 'text'], true);
    }
};

<?php

declare(strict_types=1);

use App\Notifications\SavedViewChangedNotification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string OLD_NOTIFICATION = 'App\Notifications\TableLayoutChangedNotification';

    private const string NEW_NOTIFICATION = SavedViewChangedNotification::class;

    public function up(): void
    {
        Schema::rename('table_layouts', 'saved_views');

        Schema::table('saved_views', function (Blueprint $table): void {
            $table->string('type')->default('table');
        });

        Schema::table('table_preferences', function (Blueprint $table): void {
            $table->renameColumn('active_layout_id', 'active_view_id');
            $table->renameColumn('default_layout_id', 'default_view_id');
            $table->renameColumn('applied_layout_hash', 'applied_view_hash');
        });

        $this->renameAttachedObjects();
        $this->rewriteNotifications();
    }

    private function renameAttachedObjects(): void
    {
        DB::statement('alter index table_layouts_pkey rename to saved_views_pkey');
        DB::statement('alter index table_layouts_owner_name_unique rename to saved_views_owner_name_unique');
        DB::statement('alter index table_layouts_workspace_id_entity_index rename to saved_views_workspace_id_entity_index');
        DB::statement('alter index if exists table_preferences_active_layout_id_index rename to table_preferences_active_view_id_index');
        DB::statement('alter index if exists table_preferences_default_layout_id_index rename to table_preferences_default_view_id_index');
        DB::statement('alter table saved_views rename constraint table_layouts_user_id_foreign to saved_views_user_id_foreign');
        DB::statement('alter table saved_views rename constraint table_layouts_workspace_id_foreign to saved_views_workspace_id_foreign');
        DB::statement('alter table table_preferences rename constraint table_preferences_active_layout_id_foreign to table_preferences_active_view_id_foreign');
        DB::statement('alter table table_preferences rename constraint table_preferences_default_layout_id_foreign to table_preferences_default_view_id_foreign');
    }

    private function rewriteNotifications(): void
    {
        DB::table('notifications')
            ->select(['id', 'data'])
            ->where('type', self::OLD_NOTIFICATION)
            ->chunkById(500, function (Collection $rows): void {
                foreach ($rows as $row) {
                    $data = json_decode((string) $row->data, true);
                    $data = is_array($data) ? $data : [];

                    if (array_key_exists('layout_name', $data)) {
                        $data['view_name'] = $data['layout_name'];
                        unset($data['layout_name']);
                    }

                    DB::table('notifications')
                        ->where('id', $row->id)
                        ->update(['type' => self::NEW_NOTIFICATION, 'data' => json_encode($data)]);
                }
            });
    }
};

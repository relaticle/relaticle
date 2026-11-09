<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Actions\View\ActionsIconAlias;
use Filament\Forms\View\FormsIconAlias;
use Filament\Infolists\View\InfolistsIconAlias;
use Filament\Notifications\View\NotificationsIconAlias;
use Filament\QueryBuilder\View\QueryBuilderIconAlias;
use Filament\Schemas\View\SchemasIconAlias;
use Filament\Support\View\SupportIconAlias;
use Filament\Tables\View\TablesIconAlias;
use Filament\View\PanelsIconAlias;
use Filament\Widgets\View\WidgetsIconAlias;

final readonly class PanelIcons
{
    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            ...self::actions(),
            ...self::forms(),
            ...self::richEditor(),
            ...self::richEditorTables(),
            ...self::panels(),
            ...self::tables(),
            ...self::support(),
            ...self::components(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function actions(): array
    {
        return [
            ActionsIconAlias::ACTION_GROUP => 'lucide-ellipsis-vertical',
            ActionsIconAlias::CREATE_ACTION_GROUPED => 'lucide-plus',
            ActionsIconAlias::DELETE_ACTION => 'lucide-trash',
            ActionsIconAlias::DELETE_ACTION_GROUPED => 'lucide-trash',
            ActionsIconAlias::DELETE_ACTION_MODAL => 'lucide-trash',
            ActionsIconAlias::DETACH_ACTION => 'lucide-x',
            ActionsIconAlias::DETACH_ACTION_MODAL => 'lucide-x',
            ActionsIconAlias::DISSOCIATE_ACTION => 'lucide-x',
            ActionsIconAlias::DISSOCIATE_ACTION_MODAL => 'lucide-x',
            ActionsIconAlias::EDIT_ACTION => 'lucide-square-pen',
            ActionsIconAlias::EDIT_ACTION_GROUPED => 'lucide-square-pen',
            ActionsIconAlias::EXPORT_ACTION_GROUPED => 'lucide-download',
            ActionsIconAlias::FORCE_DELETE_ACTION => 'lucide-trash',
            ActionsIconAlias::FORCE_DELETE_ACTION_GROUPED => 'lucide-trash',
            ActionsIconAlias::FORCE_DELETE_ACTION_MODAL => 'lucide-trash',
            ActionsIconAlias::IMPORT_ACTION_GROUPED => 'lucide-upload',
            ActionsIconAlias::MODAL_CONFIRMATION => 'lucide-triangle-exclamation-point',
            ActionsIconAlias::REPLICATE_ACTION => 'lucide-copy',
            ActionsIconAlias::REPLICATE_ACTION_GROUPED => 'lucide-copy',
            ActionsIconAlias::RESTORE_ACTION => 'lucide-undo-2',
            ActionsIconAlias::RESTORE_ACTION_GROUPED => 'lucide-undo-2',
            ActionsIconAlias::RESTORE_ACTION_MODAL => 'lucide-undo-2',
            ActionsIconAlias::VIEW_ACTION => 'lucide-eye',
            ActionsIconAlias::VIEW_ACTION_GROUPED => 'lucide-eye',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function forms(): array
    {
        return [
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_CLONE => 'lucide-copy',
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_COLLAPSE => 'lucide-chevron-up',
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_DELETE => 'lucide-trash',
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_EDIT => 'lucide-settings',
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_EXPAND => 'lucide-chevron-down',
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_MOVE_DOWN => 'lucide-arrow-down',
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_MOVE_UP => 'lucide-arrow-up',
            FormsIconAlias::COMPONENTS_BUILDER_ACTIONS_REORDER => 'lucide-arrow-up-down',
            FormsIconAlias::COMPONENTS_CHECKBOX_LIST_SEARCH_FIELD => 'lucide-search',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_DRAG_CROP => 'lucide-crop',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_DRAG_MOVE => 'lucide-move',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_FLIP_HORIZONTAL => 'lucide-square-centerline-dashed-vertical',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_FLIP_VERTICAL => 'lucide-square-centerline-dashed-horizontal',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_MOVE_DOWN => 'lucide-circle-arrow-down',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_MOVE_LEFT => 'lucide-circle-arrow-left',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_MOVE_RIGHT => 'lucide-circle-arrow-right',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_MOVE_UP => 'lucide-circle-arrow-up',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_ROTATE_LEFT => 'lucide-undo-2',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_ROTATE_RIGHT => 'lucide-redo-2',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_ZOOM_100 => 'lucide-maximize-2',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_ZOOM_IN => 'lucide-zoom-in',
            FormsIconAlias::COMPONENTS_FILE_UPLOAD_EDITOR_ACTIONS_ZOOM_OUT => 'lucide-zoom-out',
            FormsIconAlias::COMPONENTS_KEY_VALUE_ACTIONS_DELETE => 'lucide-trash',
            FormsIconAlias::COMPONENTS_KEY_VALUE_ACTIONS_REORDER => 'lucide-arrow-up-down',
            FormsIconAlias::COMPONENTS_MODAL_TABLE_SELECT_ACTIONS_SELECT => 'lucide-square-pen',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_CLONE => 'lucide-copy',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_COLLAPSE => 'lucide-chevron-up',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_DELETE => 'lucide-trash',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_EXPAND => 'lucide-chevron-down',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_MOVE_DOWN => 'lucide-arrow-down',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_MOVE_UP => 'lucide-arrow-up',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_REORDER => 'lucide-arrow-up-down',
            FormsIconAlias::COMPONENTS_SELECT_ACTIONS_CREATE_OPTION => 'lucide-plus',
            FormsIconAlias::COMPONENTS_SELECT_ACTIONS_EDIT_OPTION => 'lucide-square-pen',
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_COPY => 'lucide-clipboard-list',
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_HIDE_PASSWORD => 'lucide-eye-off',
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_SHOW_PASSWORD => 'lucide-eye',
            FormsIconAlias::COMPONENTS_TOGGLE_BUTTONS_BOOLEAN_FALSE => 'lucide-x',
            FormsIconAlias::COMPONENTS_TOGGLE_BUTTONS_BOOLEAN_TRUE => 'lucide-check',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function richEditor(): array
    {
        return [
            FormsIconAlias::COMPONENTS_RICH_EDITOR_PANELS_CUSTOM_BLOCKS_CLOSE_BUTTON => 'lucide-x',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_PANELS_CUSTOM_BLOCK_DELETE_BUTTON => 'lucide-trash',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_PANELS_CUSTOM_BLOCK_EDIT_BUTTON => 'lucide-square-pen',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_PANELS_MERGE_TAGS_CLOSE_BUTTON => 'lucide-x',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_ALIGN_CENTER => 'lucide-text-align-center',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_ALIGN_END => 'lucide-text-align-end',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_ALIGN_JUSTIFY => 'lucide-text-align-justify',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_ALIGN_START => 'lucide-text-align-start',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_ATTACH_FILES => 'lucide-paperclip',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_BLOCKQUOTE => 'lucide-message-square-text',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_BOLD => 'lucide-letter-b',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_BULLET_LIST => 'lucide-list',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_CLEAR_FORMATTING => 'lucide-remove-formatting',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_CODE => 'lucide-code',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_CODE_BLOCK => 'lucide-square-code',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_CUSTOM_BLOCKS => 'lucide-blocks',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_DETAILS => 'lucide-list-collapse',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_H1 => 'lucide-heading-1',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_H2 => 'lucide-heading-2',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_H3 => 'lucide-heading-3',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_H4 => 'lucide-heading-4',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_H5 => 'lucide-heading-5',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_H6 => 'lucide-heading-6',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_HIGHLIGHT => 'lucide-highlighter',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_HORIZONTAL_RULE => 'lucide-minus',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_ITALIC => 'lucide-italic',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_LEAD => 'lucide-text-quote',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_LINK => 'lucide-link',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_MERGE_TAGS => 'lucide-braces',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_ORDERED_LIST => 'lucide-list-ordered',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_PARAGRAPH => 'lucide-pilcrow',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_REDO => 'lucide-redo-2',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_SMALL => 'lucide-case-sensitive',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_STRIKE => 'lucide-strikethrough',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_SUBSCRIPT => 'lucide-subscript',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_SUPERSCRIPT => 'lucide-superscript',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TEXT_COLOR => 'lucide-swatch-book',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_UNDERLINE => 'lucide-underline',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_UNDO => 'lucide-undo-2',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function richEditorTables(): array
    {
        return [
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_GRID => 'lucide-columns-2',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_GRID_ADD_COLUMN_AFTER => 'lucide-between-vertical-end',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_GRID_ADD_COLUMN_BEFORE => 'lucide-between-vertical-start',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_GRID_DELETE => 'lucide-grid-2x2-x',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_GRID_DELETE_COLUMN => 'lucide-table-columns-split',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TABLE => 'lucide-table',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TABLE_ADD_COLUMN_AFTER => 'lucide-between-vertical-end',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TABLE_ADD_COLUMN_BEFORE => 'lucide-between-vertical-start',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TABLE_ADD_ROW_AFTER => 'lucide-between-horizontal-end',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TABLE_ADD_ROW_BEFORE => 'lucide-between-horizontal-start',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TABLE_DELETE => 'lucide-grid-2x2-x',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TABLE_DELETE_COLUMN => 'lucide-table-columns-split',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TABLE_DELETE_ROW => 'lucide-table-rows-split',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TABLE_MERGE_CELLS => 'lucide-table-cells-merge',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TABLE_SPLIT_CELL => 'lucide-table-cells-split',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TABLE_TOGGLE_HEADER_CELL => 'lucide-panel-left',
            FormsIconAlias::COMPONENTS_RICH_EDITOR_TOOLBAR_TABLE_TOGGLE_HEADER_ROW => 'lucide-panel-top',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function panels(): array
    {
        return [
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_DISABLE => 'lucide-lock-open',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_DISABLE_MODAL => 'lucide-lock-open',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_DISABLE_NOTIFICATION => 'lucide-lock-open',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_REGENERATE_RECOVERY_CODES => 'lucide-refresh-cw',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_REGENERATE_RECOVERY_CODES_MODAL => 'lucide-refresh-cw',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_REGENERATE_RECOVERY_CODES_NOTIFICATION => 'lucide-refresh-cw',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_SET_UP => 'lucide-lock',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_SET_UP_MODAL => 'lucide-lock',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_SET_UP_NOTIFICATION => 'lucide-lock',
            PanelsIconAlias::AUTH_MULTI_FACTOR_EMAIL_ACTIONS_DISABLE => 'lucide-lock-open',
            PanelsIconAlias::AUTH_MULTI_FACTOR_EMAIL_ACTIONS_DISABLE_MODAL => 'lucide-lock-open',
            PanelsIconAlias::AUTH_MULTI_FACTOR_EMAIL_ACTIONS_DISABLE_NOTIFICATION => 'lucide-lock-open',
            PanelsIconAlias::AUTH_MULTI_FACTOR_EMAIL_ACTIONS_SET_UP => 'lucide-lock',
            PanelsIconAlias::AUTH_MULTI_FACTOR_EMAIL_ACTIONS_SET_UP_MODAL => 'lucide-lock',
            PanelsIconAlias::AUTH_MULTI_FACTOR_EMAIL_ACTIONS_SET_UP_NOTIFICATION => 'lucide-lock',
            PanelsIconAlias::AUTH_PASSWORD_RESET_REQUEST_ACTIONS_LOGIN => 'lucide-arrow-left',
            PanelsIconAlias::AUTH_PASSWORD_RESET_REQUEST_ACTIONS_LOGIN_RTL => 'lucide-arrow-right',
            PanelsIconAlias::GLOBAL_SEARCH_FIELD => 'lucide-search',
            PanelsIconAlias::PAGES_DASHBOARD_ACTIONS_FILTER => 'lucide-funnel',
            PanelsIconAlias::PAGES_DASHBOARD_NAVIGATION_ITEM => 'lucide-house',
            PanelsIconAlias::RESOURCES_PAGES_EDIT_RECORD_NAVIGATION_ITEM => 'lucide-square-pen',
            PanelsIconAlias::RESOURCES_PAGES_MANAGE_RELATED_RECORDS_NAVIGATION_ITEM => 'lucide-layers',
            PanelsIconAlias::RESOURCES_PAGES_VIEW_RECORD_NAVIGATION_ITEM => 'lucide-eye',
            PanelsIconAlias::SIDEBAR_COLLAPSE_BUTTON => 'lucide-chevron-left',
            PanelsIconAlias::SIDEBAR_COLLAPSE_BUTTON_RTL => 'lucide-chevron-right',
            PanelsIconAlias::SIDEBAR_EXPAND_BUTTON => 'lucide-menu',
            PanelsIconAlias::SIDEBAR_EXPAND_BUTTON_RTL => 'lucide-chevron-left',
            PanelsIconAlias::SIDEBAR_GROUP_COLLAPSE_BUTTON => 'lucide-chevron-up',
            PanelsIconAlias::SIDEBAR_OPEN_DATABASE_NOTIFICATIONS_BUTTON => 'lucide-bell',
            PanelsIconAlias::SUB_NAVIGATION_MOBILE_MENU_BUTTON => 'lucide-chevron-down',
            PanelsIconAlias::TENANT_MENU_BILLING_BUTTON => 'lucide-credit-card',
            PanelsIconAlias::TENANT_MENU_PROFILE_BUTTON => 'lucide-settings',
            PanelsIconAlias::TENANT_MENU_REGISTRATION_BUTTON => 'lucide-plus',
            PanelsIconAlias::TENANT_MENU_TOGGLE_BUTTON => 'lucide-chevron-down',
            PanelsIconAlias::THEME_SWITCHER_DARK_BUTTON => 'lucide-moon',
            PanelsIconAlias::THEME_SWITCHER_LIGHT_BUTTON => 'lucide-sun',
            PanelsIconAlias::THEME_SWITCHER_SYSTEM_BUTTON => 'lucide-monitor',
            PanelsIconAlias::TOPBAR_CLOSE_SIDEBAR_BUTTON => 'lucide-x',
            PanelsIconAlias::TOPBAR_GROUP_TOGGLE_BUTTON => 'lucide-chevron-down',
            PanelsIconAlias::TOPBAR_OPEN_DATABASE_NOTIFICATIONS_BUTTON => 'lucide-bell',
            PanelsIconAlias::TOPBAR_OPEN_SIDEBAR_BUTTON => 'lucide-menu',
            PanelsIconAlias::USER_MENU_LOGOUT_BUTTON => 'lucide-log-out',
            PanelsIconAlias::USER_MENU_PROFILE_ITEM => 'lucide-circle-user',
            PanelsIconAlias::USER_MENU_TOGGLE_BUTTON => 'lucide-chevron-up',
            PanelsIconAlias::WIDGETS_ACCOUNT_LOGOUT_BUTTON => 'lucide-log-out',
            PanelsIconAlias::WIDGETS_FILAMENT_INFO_OPEN_DOCUMENTATION_BUTTON => 'lucide-book-open',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function tables(): array
    {
        return [
            TablesIconAlias::ACTIONS_COLUMN_MANAGER => 'lucide-columns-3',
            TablesIconAlias::ACTIONS_DISABLE_REORDERING => 'lucide-check',
            TablesIconAlias::ACTIONS_ENABLE_REORDERING => 'lucide-arrow-up-down',
            TablesIconAlias::ACTIONS_FILTER => 'lucide-funnel',
            TablesIconAlias::ACTIONS_GROUP => 'lucide-layers',
            TablesIconAlias::ACTIONS_OPEN_BULK_ACTIONS => 'lucide-ellipsis-vertical',
            TablesIconAlias::COLUMNS_COLLAPSE_BUTTON => 'lucide-chevron-down',
            TablesIconAlias::COLUMNS_ICON_COLUMN_FALSE => 'lucide-circle-x',
            TablesIconAlias::COLUMNS_ICON_COLUMN_TRUE => 'lucide-circle-check',
            TablesIconAlias::EMPTY_STATE => 'lucide-x',
            TablesIconAlias::FILTERS_QUERY_BUILDER_CONSTRAINTS_BOOLEAN => 'lucide-circle-check',
            TablesIconAlias::FILTERS_QUERY_BUILDER_CONSTRAINTS_DATE => 'lucide-calendar',
            TablesIconAlias::FILTERS_QUERY_BUILDER_CONSTRAINTS_NUMBER => 'lucide-variable',
            TablesIconAlias::FILTERS_QUERY_BUILDER_CONSTRAINTS_RELATIONSHIP => 'lucide-maximize-2',
            TablesIconAlias::FILTERS_QUERY_BUILDER_CONSTRAINTS_SELECT => 'lucide-chevrons-up-down',
            TablesIconAlias::FILTERS_QUERY_BUILDER_CONSTRAINTS_TEXT => 'lucide-languages',
            TablesIconAlias::FILTERS_REMOVE_ALL_BUTTON => 'lucide-x',
            TablesIconAlias::GROUPING_COLLAPSE_BUTTON => 'lucide-chevron-up',
            TablesIconAlias::HEADER_CELL_SORT_ASC_BUTTON => 'lucide-chevron-up',
            TablesIconAlias::HEADER_CELL_SORT_BUTTON => 'lucide-chevron-down',
            TablesIconAlias::HEADER_CELL_SORT_DESC_BUTTON => 'lucide-chevron-down',
            TablesIconAlias::REORDER_HANDLE => 'lucide-grip-horizontal',
            TablesIconAlias::SEARCH_FIELD => 'lucide-search',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function support(): array
    {
        return [
            SupportIconAlias::BADGE_DELETE_BUTTON => 'lucide-x',
            SupportIconAlias::BREADCRUMBS_SEPARATOR => 'lucide-chevron-right',
            SupportIconAlias::BREADCRUMBS_SEPARATOR_RTL => 'lucide-chevron-left',
            SupportIconAlias::MODAL_CLOSE_BUTTON => 'lucide-x',
            SupportIconAlias::PAGINATION_FIRST_BUTTON => 'lucide-chevrons-left',
            SupportIconAlias::PAGINATION_FIRST_BUTTON_RTL => 'lucide-chevrons-right',
            SupportIconAlias::PAGINATION_LAST_BUTTON => 'lucide-chevrons-right',
            SupportIconAlias::PAGINATION_LAST_BUTTON_RTL => 'lucide-chevrons-left',
            SupportIconAlias::PAGINATION_NEXT_BUTTON => 'lucide-chevron-right',
            SupportIconAlias::PAGINATION_NEXT_BUTTON_RTL => 'lucide-chevron-left',
            SupportIconAlias::PAGINATION_PREVIOUS_BUTTON => 'lucide-chevron-left',
            SupportIconAlias::PAGINATION_PREVIOUS_BUTTON_RTL => 'lucide-chevron-right',
            SupportIconAlias::SECTION_COLLAPSE_BUTTON => 'lucide-chevron-up',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function components(): array
    {
        return [
            InfolistsIconAlias::COMPONENTS_ICON_ENTRY_FALSE => 'lucide-circle-x',
            InfolistsIconAlias::COMPONENTS_ICON_ENTRY_TRUE => 'lucide-circle-check',
            NotificationsIconAlias::DATABASE_MODAL_EMPTY_STATE => 'lucide-bell-off',
            NotificationsIconAlias::NOTIFICATION_CLOSE_BUTTON => 'lucide-x',
            NotificationsIconAlias::NOTIFICATION_DANGER => 'lucide-circle-x',
            NotificationsIconAlias::NOTIFICATION_INFO => 'lucide-info',
            NotificationsIconAlias::NOTIFICATION_SUCCESS => 'lucide-circle-check',
            NotificationsIconAlias::NOTIFICATION_WARNING => 'lucide-circle-exclamation-point',
            QueryBuilderIconAlias::ADD_RULE_ACTION => 'lucide-plus',
            QueryBuilderIconAlias::CONSTRAINTS_BOOLEAN => 'lucide-circle-check',
            QueryBuilderIconAlias::CONSTRAINTS_DATE => 'lucide-calendar',
            QueryBuilderIconAlias::CONSTRAINTS_NUMBER => 'lucide-variable',
            QueryBuilderIconAlias::CONSTRAINTS_RELATIONSHIP => 'lucide-maximize-2',
            QueryBuilderIconAlias::CONSTRAINTS_SELECT => 'lucide-chevrons-up-down',
            QueryBuilderIconAlias::CONSTRAINTS_TEXT => 'lucide-languages',
            QueryBuilderIconAlias::OR_GROUP_ADD_GROUP_ACTION => 'lucide-plus',
            QueryBuilderIconAlias::OR_GROUP_BLOCK => 'lucide-slash',
            SchemasIconAlias::COMPONENTS_CALLOUT_DANGER => 'lucide-circle-x',
            SchemasIconAlias::COMPONENTS_CALLOUT_INFO => 'lucide-info',
            SchemasIconAlias::COMPONENTS_CALLOUT_SUCCESS => 'lucide-circle-check',
            SchemasIconAlias::COMPONENTS_CALLOUT_WARNING => 'lucide-circle-exclamation-point',
            SchemasIconAlias::COMPONENTS_TABS_DROPDOWN_TRIGGER_BUTTON => 'lucide-chevron-down',
            SchemasIconAlias::COMPONENTS_TABS_MORE_TABS_BUTTON => 'lucide-ellipsis',
            SchemasIconAlias::COMPONENTS_WIZARD_COMPLETED_STEP => 'lucide-check',
            WidgetsIconAlias::CHART_WIDGET_EMPTY_STATE => 'lucide-x',
            WidgetsIconAlias::CHART_WIDGET_FILTER => 'lucide-funnel',
        ];
    }
}

<?php

declare(strict_types=1);

return [
    'view_switcher' => [
        'board' => 'Board',
        'cards' => 'Cards',
        'opportunity_board' => 'Pipeline',
    ],

    'stage_order_failed' => 'The stage order could not be saved.',

    'view_settings' => [
        'panel' => 'Board view settings',
        'stage_actions' => 'Actions for :stage',
        'grouped_by' => 'Grouped by',
        'lanes' => 'Lanes',
        'no_lanes' => 'No lanes',
        'no_lanes_short' => 'None',
        'visible_columns' => 'Visible columns',
        'show_column' => 'Show :column',
        'last_column' => 'One column stays visible.',
        'no_value_column' => 'No :attribute',
        'show_labels' => 'Show attribute labels',
        'hide_empty' => 'Hide empty attributes',
        'visible_attributes' => 'Visible attributes',
        'attribute_actions' => 'Actions for :attribute',
        'remove_attribute' => 'Remove from card',
        'attribute_created_off_card' => ':name created. This type of attribute does not show on a card.',
        'columns_grouped_by_created' => ':name created. The columns are now grouped by it.',
        'add_attribute' => 'Add attribute to view',
        'search_attributes' => 'Search :entity attributes…',
        'all_attributes_shown' => 'Every attribute is on the card',
        'no_attribute_matches' => 'No attribute matches',
    ],

    'card_attributes' => [
        'description' => 'Description',
        'checklist' => 'Checklist',
        'empty' => 'Empty',
    ],

    'stage_menu' => [
        'nouns' => [
            'stage' => 'stage',
            'status' => 'status',
            'option' => 'option',
        ],
        'add' => 'Add :noun',
        'add_placeholder' => 'New :noun name',
        'name_taken' => 'A :noun named ":name" already exists.',
        'collapse' => 'Collapse :noun',
        'hide' => 'Hide :noun',
        'name' => 'Name of this :noun',
        'color' => 'Change the color of this :noun',
        'set_color' => 'Set color to :color',
        'confetti' => 'Confetti',
        'confetti_label' => 'Fire confetti when a card moves into this :noun',
        'last_visible' => 'One :noun stays visible. Show another before hiding this one.',
        'delete' => 'Delete :noun',
        'delete_heading' => 'Delete ":name"?',
        'delete_body' => '{1} :count :records will have no :attribute. It shows in the :column column when it is on in View settings.|[2,*] :count :records will have no :attribute. They show in the :column column when it is on in View settings.',
        'changed' => 'The :noun list changed while you were editing. Reload the board and try again.',
        'delete_confirm' => 'Delete',
        'delete_only' => 'A board needs at least one :noun. Add another before deleting this one.',
        'delete_last_visible' => 'One :noun stays visible. Show another before deleting this one.',
        'records' => [
            'opportunity' => 'opportunity|opportunities',
            'task' => 'task|tasks',
        ],
    ],

    'layout' => [
        'columns' => 'Columns',
        'card_size' => 'Card size',
        'columns_collapsed' => 'Collapsed columns',
        'columns_expanded' => 'Expanded columns',
        'compact' => 'Compact cards',
        'full' => 'Full cards',
    ],

    'move_denied' => 'You do not have permission to move this card.',

    'sorted_no_reorder' => 'Cards are sorted by :field. Clear the sort to arrange them by hand.',

    'move_to' => [
        'label' => 'Move to',
        'moved' => 'Moved to :column',
    ],

    'opportunities' => [
        'title' => 'Opportunities',
        'actions' => [
            'add' => 'Add Opportunity',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
        'form' => [
            'name_placeholder' => 'Enter opportunity title',
        ],
        'badges' => [
            'today' => 'Closes Today',
            'tomorrow' => 'Closes Tomorrow',
            'overdue' => ':date (Overdue)',
        ],
        'groupings' => [
            'company' => 'Company',
            'no_company' => 'No company',
        ],
    ],

    'tasks' => [
        'title' => 'Tasks',
        'actions' => [
            'add' => 'Add Task',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
        'badges' => [
            'today' => 'Due Today',
            'tomorrow' => 'Due Tomorrow',
            'overdue' => ':date (Overdue)',
        ],
        'groupings' => [
            'assignee' => 'Assignee',
            'unassigned' => 'Unassigned',
        ],
        'checklist_progress' => 'Checklist progress',
        'checklist_progress_tooltip' => '{1} :checked of :total item done|[2,*] :checked of :total items done',
    ],
];

<?php

return [
    'heading' => [
        'title' => 'Custom attributes',
        'description' => 'Create and manage custom attributes to capture additional data for your records.',
    ],

    'nav' => [
        'label' => 'Custom attributes',
        'group' => 'Custom attributes',
    ],

    'section' => [
        'form' => [
            'contains_system_fields_cannot_delete' => 'Sections containing system-defined attributes cannot be deleted.',
        ],
        'notifications' => [
            'duplicate_field_code' => 'This section already has an attribute with that code. Rename one of them before moving it here.',
        ],
    ],

    'field' => [
        'form' => [
            'name_helper_text' => "The attribute's label shown in tables and forms.",
            'code_helper_text' => 'Unique code to identify this attribute throughout the resource.',
            'description_position_options' => [
                'below' => 'Below attribute',
                'above' => 'Above attribute',
            ],
            'encrypted_help' => 'When enabled, this attribute\'s values will be stored securely using encryption.',
            'visible_in_list_help' => 'Show this attribute in table list views.',
            'list_toggleable_hidden_hint' => 'When enabled, this attribute will be hidden by default in the list view but can be toggled visible by the user.',
            'visible_in_view_help' => 'Show this attribute in detail view pages.',
            'add_field' => 'Add attribute',
            'search_placeholder' => 'Search attributes...',
            'type_search_prompt' => 'Search attribute types...',
            'type_no_results' => 'No attribute types found',
            'system_defined_cannot_delete' => 'System-defined attributes cannot be deleted.',
            'allow_multiple_help' => 'When enabled, users can enter multiple values for this attribute.',
        ],
        'actions' => [
            'edit_modal_heading' => 'Edit attribute',
            'delete_modal_heading' => 'Delete attribute',
            'delete_modal_description' => 'Are you sure you want to delete this attribute? This cannot be undone.',
        ],
    ],

    'empty_states' => [
        'sections' => [
            'description' => 'Create your first section to organize and group your custom attributes together.',
        ],
        'fields' => [
            'description' => 'Drag and drop attributes here or click the button below to add your first attribute.',
        ],
        'fields_no_sections' => [
            'heading' => 'No custom attributes yet',
            'description' => 'Click the button below to add your first custom attribute.',
        ],
        'search_no_results' => [
            'heading' => 'No matching attributes',
            'description' => 'No attributes match your search criteria.',
        ],
    ],

    'common' => [
        'active_fields' => 'Active attributes',
        'inactive_fields' => 'Inactive attributes',
    ],

    'enums' => [
        'condition_source' => [
            'custom_field' => 'Custom attribute',
        ],
        'date_anchor' => [
            'custom_field' => 'Another attribute',
        ],
        'description_position' => [
            'below' => 'Below attribute',
            'above' => 'Above attribute',
        ],
        'entity_feature' => [
            'labels' => [
                'custom_fields' => 'Custom attributes',
            ],
            'descriptions' => [
                'custom_fields' => 'Entity can have custom attributes attached',
                'lookup_source' => 'Entity can be used as a lookup source for choice attributes',
                'scoped_management' => 'Entity custom attributes are managed per-parent record, not on the global management page',
            ],
        ],
    ],

    'date_constraint' => [
        'reference_field' => 'Reference attribute',
    ],
];

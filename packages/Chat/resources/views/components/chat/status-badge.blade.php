@props([
    'type' => 'company', 
])

@php
    $badges = [
        'company' => ['label' => 'Company', 'class' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'],
        'person' => ['label' => 'Person', 'class' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'],
        'opportunity' => ['label' => 'Opportunity', 'class' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'],
        'task' => ['label' => 'Task', 'class' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400'],
        'note' => ['label' => 'Note', 'class' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-400'],
        'created' => ['label' => 'Created', 'class' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'],
        'updated' => ['label' => 'Updated', 'class' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'],
        'deleted' => ['label' => 'Deleted', 'class' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'],
        'rejected' => ['label' => 'Rejected', 'class' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-400'],
    ];
    $badge = $badges[$type] ?? $badges['note'];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium {$badge['class']}"]) }}>
    {{ $badge['label'] }}
</span>

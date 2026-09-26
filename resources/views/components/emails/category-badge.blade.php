@props(['label'])

@php
    $colorClass = match ($label) {
        'Scheduling'    => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
        'Marketing'     => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        'Invoice'       => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'Support'       => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
        'Sales'         => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
        'Notifications' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
        'News'          => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
        default         => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    };
@endphp

<span {{ $attributes->class(['inline-flex shrink-0 items-center rounded-md px-2 py-0.5 text-[11px] font-medium leading-none', $colorClass]) }}>
    {{ $label }}
</span>

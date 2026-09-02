@php
    /** @var string $label */
    /** @var string $old */
    /** @var string $new */
    /** @var string $title */
@endphp

<span title="{{ $title }}"
    ><span class="text-gray-500 dark:text-gray-400">{{ $label }}:</span>
    <span class="text-gray-400 line-through decoration-gray-300 dark:text-gray-500 dark:decoration-gray-600">{{ $old }}</span>
    <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">→</span>
    <span class="font-medium text-gray-950 dark:text-white">{{ $new }}</span
></span>

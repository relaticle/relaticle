{{-- The `label value` pairs under a plan line, as partials/_dock-line-values.blade.php draws them.
     A `change` row carries the old value fourth and draws a select change: old, arrow, new. --}}
@props([
    /** @var list<array{0: string, 1: string, 2?: string, 3?: string}> */
    'fields',
])

<div {{ $attributes->class('flex flex-wrap items-baseline gap-x-2 gap-y-1') }}>
    @foreach($fields as $field)
        @php([$label, $value] = $field)
        @php($kind = $field[2] ?? 'text')
        @unless($loop->first)
            <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">&middot;</span>
        @endunless
        <span class="inline-flex min-w-0 max-w-full items-baseline gap-1.5">
            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $label }}</span>
            @if($kind === 'new')
                <span class="inline-flex min-w-0 items-center gap-1 rounded-md bg-primary-50 px-1.5 text-xs font-medium leading-5 text-primary-700 dark:bg-primary-400/10 dark:text-primary-300"><span class="truncate">{{ $value }}</span> <span class="font-normal opacity-80">{{ __('new') }}</span></span>
            @elseif($kind === 'change')
                <span class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5">
                    <span class="text-gray-400 line-through decoration-gray-300 dark:text-gray-500 dark:decoration-gray-600">{{ $field[3] }}</span>
                    <x-lucide-arrow-right class="h-3 w-3 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-micro font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $value }}</span>
                </span>
            @elseif($kind === 'badge')
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-micro font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $value }}</span>
            @else
                <span class="min-w-0 break-words text-sm text-gray-900 dark:text-white">{{ $value }}</span>
            @endif
        </span>
    @endforeach
</div>

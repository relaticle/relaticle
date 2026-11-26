{{-- Static twin of a field row on a single-record card (Chat partials/_dock-field.blade.php). `old` draws a select change, `badge` a select value.
     `toggle` names the Alpine boolean that keeps the field, and a string `editable` is the Alpine call that opens its editor. --}}
@props(['label', 'value' => null, 'old' => null, 'badge' => false, 'editable' => false, 'excludable' => false, 'toggle' => null])

@php
    $struck = $toggle === null ? null : "{ 'line-through opacity-60': ! {$toggle} }";
    $control = 'inline-flex min-h-8 min-w-8 shrink-0 items-center justify-center rounded-md transition focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500';
    $idle = 'text-gray-400 hover:bg-gray-100 hover:text-gray-700 sm:opacity-0 sm:group-hover/field:opacity-100 dark:hover:bg-white/10 dark:hover:text-gray-200';
    $keep = 'px-1.5 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400';
@endphp

<div {{ $attributes->class('group/field flex items-start gap-3') }}>
    <span class="w-24 shrink-0 truncate py-1.5 text-xs leading-5 text-gray-500 sm:w-32 dark:text-gray-400" @if ($struck) x-bind:class="{{ $struck }}" @endif>{{ $label }}</span>
    <span class="flex min-w-0 flex-1 items-start gap-1.5" @if ($struck) x-bind:class="{{ $struck }}" @endif>
        <span class="min-w-0 flex-1 py-1.5 leading-5">
            @if ($old !== null)
                <span class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5">
                    <span class="text-gray-400 line-through decoration-gray-300 dark:text-gray-500 dark:decoration-gray-600">{{ $old }}</span>
                    <x-lucide-arrow-right class="h-3 w-3 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-micro font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $value }}</span>
                </span>
            @elseif ($badge)
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-micro font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $value }}</span>
            @else
                <span class="min-w-0 break-words text-sm text-gray-900 dark:text-white">{{ $slot->isEmpty() ? $value : $slot }}</span>
            @endif
        </span>
        @if (is_string($editable))
            <button type="button" class="{{ $control }} {{ $idle }} cursor-pointer" x-on:click="{{ $editable }}" aria-label="{{ __('Edit :field', ['field' => $label]) }}">
                <x-lucide-square-pen class="h-3.5 w-3.5" aria-hidden="true"/>
            </button>
        @elseif ($editable)
            <span class="{{ $control }} {{ $idle }}" aria-hidden="true">
                <x-lucide-square-pen class="h-3.5 w-3.5"/>
            </span>
        @endif
    </span>
    @if ($toggle !== null)
        <button
            type="button"
            class="{{ $control }} {{ $idle }} cursor-pointer"
            x-bind:class="{ @js($idle): {{ $toggle }}, @js($keep): ! {{ $toggle }} }"
            x-on:click="{{ $toggle }} = ! {{ $toggle }}"
            x-bind:aria-label="{{ $toggle }} ? @js(__('Leave out :field', ['field' => $label])) : @js(__('Keep :field', ['field' => $label]))"
            aria-label="{{ __('Leave out :field', ['field' => $label]) }}"
        >
            <x-lucide-x class="h-3.5 w-3.5" x-show="{{ $toggle }}" aria-hidden="true"/>
            <span x-show="! {{ $toggle }}" style="display: none">{{ __('Keep') }}</span>
        </button>
    @elseif ($excludable)
        <span class="{{ $control }} {{ $idle }}" aria-hidden="true">
            <x-lucide-x class="h-3.5 w-3.5"/>
        </span>
    @endif
</div>

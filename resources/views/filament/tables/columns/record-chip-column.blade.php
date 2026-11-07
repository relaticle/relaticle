@php
    $chips = $getChips();
    $url = $getUrl();
    $previewAction = $getPreviewActionName();
    $recordKey = $getRecordKey();
@endphp

<div
    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-gray-950 dark:text-white"
    @if ($previewAction)
        x-effect="$el.closest('tr')?.classList.toggle('fi-record-previewing', $wire.mountedActions.at(-1)?.name === @js($previewAction) && $wire.mountedActions.at(-1)?.context?.recordKey === @js($recordKey))"
    @endif
>
    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
        @forelse ($chips as $chip)
            @if (filled($url))
                {{-- Filament wraps a cell with a URL in its own link, and a nested anchor gets pulled out of the cell. --}}
                <span class="text-primary-600 hover:underline dark:text-primary-400">{{ $chip }}</span>
            @else
                {{ $chip }}
            @endif
        @empty
            <span class="text-gray-400 dark:text-gray-500">{{ $getPlaceholder() }}</span>
        @endforelse
    </div>

    @if ($previewAction)
        <x-filament::icon-button
            icon="lucide-panel-right"
            color="gray"
            size="xs"
            :label="__('filament/record-page.actions.open_preview')"
            :tooltip="__('filament/record-page.actions.open_preview')"
            x-on:click.prevent.stop="$wire[$wire.mountedActions.at(-1)?.name === {{ Js::from($previewAction) }} ? 'replaceMountedAction' : 'mountAction']({{ Js::from($previewAction) }}, {{ Js::from(['position' => null]) }}, {{ Js::from(['table' => true, 'recordKey' => $recordKey]) }})"
            class="fi-record-preview-trigger"
        />
    @endif
</div>

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($task)
        <button type="button" class="fi-record-next-task" x-on:click="{{ $open }}">
            <span class="fi-record-next-task-title">{{ $task['title'] }}</span>
            @if ($due)
                <span @class(['fi-record-due', "fi-record-due-{$due['tone']}"])>{{ $due['label'] }}</span>
            @endif
        </button>
    @else
        <span class="fi-record-highlight-empty">{{ __('filament/record-page.overview.no_next_task') }}</span>
    @endif
</x-dynamic-component>

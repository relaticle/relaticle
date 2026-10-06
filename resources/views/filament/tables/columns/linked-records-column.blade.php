@php
    $records = $getLinkedRecords();
    $first = $records[0] ?? null;
    $hiddenCount = max(count($records) - 1, 0);
@endphp

<div
    @if ($hiddenCount > 0)
        x-data="{
            open: false,
            toggle() {
                this.open ? this.close() : this.show()
            },
            show() {
                this.open = true
                this.$refs.panel.open(this.$refs.trigger)
            },
            close(refocus = false) {
                if (! this.open) {
                    return
                }

                this.open = false
                this.$refs.panel.close()

                if (refocus) {
                    this.$refs.trigger.focus({ preventScroll: true })
                }
            },
        }"
        x-on:keydown.escape="if (open) { $event.stopPropagation(); close(true) }"
        x-on:click.outside="if (! $refs.panel.contains($event.target)) close()"
    @endif
    class="fi-linked-records-cell"
>
    @if ($first === null)
        <span class="fi-linked-records-cell-empty">{{ $getPlaceholder() }}</span>
    @else
        <a
            @if (filled($first['url'])) href="{{ $first['url'] }}" @endif
            title="{{ $first['chip']->name }}"
            class="fi-linked-records-cell-link"
        >
            {{ $first['chip'] }}
        </a>
    @endif

    @if ($hiddenCount > 0)
        <button
            x-ref="trigger"
            x-on:click="toggle()"
            x-bind:aria-expanded="open"
            type="button"
            aria-haspopup="true"
            aria-label="{{ __('filament/components/linked-records.show_all', ['count' => count($records)]) }}"
            class="fi-linked-records-cell-more"
        >
            +{{ $hiddenCount }}
        </button>

        <div
            x-ref="panel"
            x-cloak
            x-float.placement.bottom-start.flip.shift.offset.teleport="{ offset: 6 }"
            x-on:keydown.escape="close(true)"
            role="group"
            aria-label="{{ __('filament/components/linked-records.label') }}"
            class="fi-linked-records-cell-panel"
        >
            <ul>
                @foreach ($records as $record)
                    <li>
                        <a
                            @if (filled($record['url'])) href="{{ $record['url'] }}" @endif
                            class="fi-linked-records-cell-link"
                        >
                            {{ $record['chip'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

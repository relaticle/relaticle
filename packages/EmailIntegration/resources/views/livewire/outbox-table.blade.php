<div>
    @if ($this->segments() !== [])
        <div class="mb-4 px-4 pt-3 lg:px-6">
            <x-filament::tabs :label="__('filament/pages/email-outbox.tabs.aria')" class="ei-tabs-segmented" data-reveal-active-tab>
                @foreach ($this->segments() as $segment)
                    @php($count = $this->segmentCounts[$segment->value] ?? 0)
                    <x-filament::tabs.item
                        :active="$activeSegment === $segment"
                        :badge="$count > 0 ? ($count > 99 ? '99+' : (string) $count) : null"
                        badge-color="primary"
                        wire:click="setStatusTab('{{ $segment->value }}')"
                        x-on:click="$el.scrollIntoView({ block: 'nearest', inline: 'nearest' })"
                    >
                        {{ $segment->getLabel() }}
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
        </div>
    @endif

    <div wire:loading.class="pointer-events-none opacity-50" wire:target="setStatusTab">
        {{ $this->table }}
    </div>
</div>

<div>
    @if ($this->segments() !== [])
        <div class="-mt-2 mb-4">
            <x-filament::tabs :label="__('filament/pages/email-outbox.tabs.aria')" class="ei-tabs-segmented">
                @foreach ($this->segments() as $segment)
                    @php($count = $this->segmentCounts[$segment->value] ?? 0)
                    <x-filament::tabs.item
                        :active="$statusTab === $segment"
                        :badge="$count > 0 ? ($count > 99 ? '99+' : (string) $count) : null"
                        badge-color="primary"
                        wire:click="setStatusTab('{{ $segment->value }}')"
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

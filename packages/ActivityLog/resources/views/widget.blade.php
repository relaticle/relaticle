<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ $this->getHeading() }}</x-slot>

        @php($entries = $this->getEntries())

        @if (count($entries) === 0)
            <p class="text-sm text-gray-500">No recent activity.</p>
        @else
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($entries as $entry)
                    {!! app(\Relaticle\ActivityLog\Renderers\RendererRegistry::class)->resolve($entry)->render($entry) !!}
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

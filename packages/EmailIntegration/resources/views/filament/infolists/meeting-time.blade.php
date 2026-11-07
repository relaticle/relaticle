@php
    /** @var array{location: string|null, location_url: string|null, calendar_url: string|null, calendar_label: string} $state */
    $state = $getState();
    $iconClass = 'size-4 shrink-0 text-gray-400 dark:text-gray-500';
@endphp

@if ($state['location'] !== null || $state['calendar_url'] !== null)
    <div
        class="flex min-w-0 items-center gap-x-4 gap-y-1 border-y border-[var(--surface-block-border)] py-2.5 text-sm text-gray-600 dark:text-gray-400"
        data-testid="meeting-meta"
    >
        @if ($state['location'] !== null)
            <div class="flex min-w-0 flex-1 items-center gap-2" data-testid="meeting-location-row">
                <x-filament::icon
                    icon="lucide-map-pin"
                    class="{{ $iconClass }}"
                    aria-hidden="true"
                />

                @if ($state['location_url'] !== null)
                    <a
                        href="{{ $state['location_url'] }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="min-w-0 truncate leading-5 text-primary-600 hover:underline dark:text-primary-400"
                    >{{ $state['location'] }}</a>
                @else
                    <span class="min-w-0 truncate leading-5" title="{{ $state['location'] }}">{{ $state['location'] }}</span>
                @endif
            </div>
        @endif

        @if ($state['calendar_url'] !== null)
            <a
                href="{{ $state['calendar_url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                data-testid="meeting-calendar-link-row"
                @class([
                    'inline-flex shrink-0 items-center gap-1 leading-5 text-primary-600 hover:underline dark:text-primary-400',
                    'ml-auto' => $state['location'] !== null,
                ])
            >
                <span>{{ $state['calendar_label'] }}</span>
                <x-filament::icon
                    icon="lucide-external-link"
                    class="size-3.5 shrink-0"
                    aria-hidden="true"
                />
            </a>
        @endif
    </div>
@endif

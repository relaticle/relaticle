<div class="flex items-start gap-3 py-2" data-type="{{ $entry->type }}" data-event="{{ $entry->event }}">
    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-primary-600">
        <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
    </div>
    <div class="flex-1">
        <div class="flex items-center justify-between text-sm">
            <span class="font-medium">
                {{ \Illuminate\Support\Str::headline($entry->event) }}
            </span>
            <time class="text-xs text-gray-500" datetime="{{ $entry->occurredAt->toIso8601String() }}">
                {{ $entry->occurredAt->diffForHumans() }}
            </time>
        </div>
        @if ($entry->causer)
            <p class="text-xs text-gray-500">by {{ $entry->causer->name ?? 'System' }}</p>
        @endif
        @if (! empty($entry->properties['attributes'] ?? null))
            <dl class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                @foreach ($entry->properties['attributes'] as $key => $value)
                    <div class="flex gap-2">
                        <dt class="font-medium">{{ $key }}:</dt>
                        <dd>{{ is_scalar($value) ? (string) $value : json_encode($value) }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>
</div>

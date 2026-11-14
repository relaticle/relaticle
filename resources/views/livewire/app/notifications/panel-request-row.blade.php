@php
    $requestId = $request['id'];
    $kind = $request['kind'];
    $outcome = $request['outcome'];
@endphp

<li
    wire:key="inbox-request-{{ $requestId }}"
    data-inbox-key="request-{{ $requestId }}"
    x-data="{ kind: @js($kind), requestId: @js($requestId) }"
    class="fi-inbox-row"
>
    @if ($request['avatar'])
        <img src="{{ $request['avatar'] }}" alt="" class="size-8 shrink-0 rounded-full object-cover" />
    @else
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400">
            <x-filament::icon icon="lucide-inbox" class="size-4" />
        </span>
    @endif

    <div class="min-w-0 flex-1">
        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $request['title'] }}</p>

        @if (filled($request['body']))
            <p class="mt-0.5 line-clamp-2 text-sm text-gray-500 dark:text-gray-400">{{ $request['body'] }}</p>
        @endif

        <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400">
            <time datetime="{{ $request['createdAt']->toIso8601String() }}">{{ $request['createdAt']->diffForHumans() }}</time>

            @if ($request['expiresAt'] && ! $outcome)
                <span aria-hidden="true">&middot;</span>
                <span>{{ __('notifications.panel.requests.expires', ['when' => $request['expiresAt']->diffForHumans()]) }}</span>
            @endif
        </p>

        <div class="mt-2 flex flex-wrap items-center gap-2">
            @if ($outcome)
                <span
                    @class([
                        'inline-flex items-center gap-1 text-xs font-medium',
                        'text-success-600 dark:text-success-400' => $outcome->color() === 'success',
                        'text-gray-500 dark:text-gray-400' => $outcome->color() === 'gray',
                    ])
                >
                    <x-filament::icon :icon="$outcome->icon()" class="size-4" />
                    {{ $outcome->label() }}
                </span>
            @else
                <x-filament::button
                    size="xs"
                    data-inbox-approve
                    x-on:click="act(() => $wire.approveRequest(kind, requestId), $el, '[data-inbox-approve]')"
                    wire:loading.attr="disabled"
                    wire:target="approveRequest, denyRequest"
                >
                    {{ __('notifications.panel.requests.approve') }}
                </x-filament::button>

                <x-filament::button
                    size="xs"
                    color="gray"
                    x-on:click="act(() => $wire.denyRequest(kind, requestId), $el, '[data-inbox-approve]')"
                    wire:loading.attr="disabled"
                    wire:target="approveRequest, denyRequest"
                >
                    {{ __('notifications.panel.requests.deny') }}
                </x-filament::button>

                @foreach ($request['approvalOptions'] as $option => $label)
                    <span x-data="{ option: @js($option), confirmation: @js(__('notifications.panel.requests.confirm_approval', ['action' => $label])) }">
                        <x-filament::button
                            size="xs"
                            color="gray"
                            x-on:click="if (confirm(confirmation)) act(() => $wire.approveRequest(kind, requestId, option), $el, '[data-inbox-approve]')"
                            wire:loading.attr="disabled"
                            wire:target="approveRequest, denyRequest"
                        >
                            {{ $label }}
                        </x-filament::button>
                    </span>
                @endforeach

                @if ($request['url'])
                    <a
                        {{ \Filament\Support\generate_href_html($request['url']) }}
                        x-on:click="hide(false)"
                        class="ms-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                    >
                        {{ __('notifications.panel.requests.view') }}
                    </a>
                @endif
            @endif
        </div>
    </div>
</li>

@php
    use Filament\Actions\Action;
    use Filament\Support\Enums\IconSize;
    use Filament\Notifications\View\Components\NotificationComponent\IconComponent;
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;

    $presented = $present($row);
    $actions = collect($presented->getActions());
    $primary = $actions->first(fn (mixed $action): bool => $action instanceof Action && filled($action->getUrl()));
    $showsHint = $primary !== null && $actions->count() === 1;
    $barActions = $showsHint ? collect() : $actions;
    $opensInNewTab = $primary?->shouldOpenUrlInNewTab() ?? false;
    $unread = $row->unread();
    $actor = $row->actor ?? null;
    $icon = $presented->getIcon();
    $rowId = (string) $row->getKey();
    $control = $primary ? 'a' : ($unread ? 'button' : 'div');
@endphp

<li
    wire:key="inbox-notification-{{ $rowId }}"
    data-inbox-key="notification-{{ $rowId }}"
    x-data="{
        markAsRead() { return this.$wire.markNotificationAsRead(@js($rowId)) },
        markAsUnread() { return this.$wire.markNotificationAsUnread(@js($rowId)) },
        close() {},
    }"
    class="group relative"
>
    <{{ $control }}
        @if ($primary)
            data-inbox-main
            {{ \Filament\Support\generate_href_html($primary->getUrl(), $opensInNewTab) }}
            x-on:click="{{ $unread ? 'markAsRead(); ' : '' }}{{ $opensInNewTab ? '' : 'hide(false)' }}"
        @elseif ($unread)
            type="button"
            data-inbox-main
            x-on:click="act(() => markAsRead(), $el, '[data-inbox-main]')"
        @endif
        @class([
            'fi-inbox-row',
            'fi-inbox-row-interactive' => $control !== 'div',
        ])
    >
        @if ($actor)
            <img
                src="{{ filament()->getUserAvatarUrl($actor) }}"
                alt=""
                class="size-8 shrink-0 rounded-full object-cover"
            />
        @else
            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-white/10">
                {{
                    \Filament\Support\generate_icon_html(
                        $icon ?? 'lucide-bell',
                        attributes: (new FilamentComponentAttributeBag)->color(IconComponent::class, $presented->getIconColor() ?? 'gray')->class(['size-4']),
                        size: IconSize::Medium,
                    )
                }}
            </span>
        @endif

        <span class="min-w-0 flex-1">
            <span @class([
                'block text-sm text-gray-950 dark:text-white',
                'font-semibold' => $unread,
                'font-medium' => ! $unread,
            ])>
                {!! str($presented->getTitle())->sanitizeHtml() !!}
            </span>

            @if (filled($presented->getBody()))
                <span class="mt-0.5 line-clamp-2 block text-sm text-gray-500 dark:text-gray-400">
                    {!! str($presented->getBody())->sanitizeHtml() !!}
                </span>
            @endif

            <span class="mt-1 flex items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400">
                <time datetime="{{ $row->created_at?->toIso8601String() }}">{{ $presented->getDate() }}</time>

                @if ($showsHint)
                    <span data-inbox-hint class="font-medium text-primary-600 dark:text-primary-400">{{ $primary->getLabel() }}</span>
                @endif
            </span>
        </span>

        @if ($unread)
            <span class="mt-1.5 size-2 shrink-0 rounded-full bg-primary-600 dark:bg-primary-400">
                <span class="sr-only">{{ __('notifications.panel.row.unread') }}</span>
            </span>
        @endif
    </{{ $control }}>

    @if ($barActions->isNotEmpty())
        <div class="fi-ac flex flex-wrap gap-3 pb-3 ps-15 pe-4">
            @foreach ($barActions as $action)
                {{ $action }}
            @endforeach
        </div>
    @endif

    <div class="fi-inbox-row-controls">
        <button
            type="button"
            class="fi-inbox-icon-btn"
            wire:click="{{ $unread ? 'markNotificationAsRead' : 'markNotificationAsUnread' }}(@js($rowId))"
            aria-label="{{ $unread ? __('notifications.panel.row.mark_read') : __('notifications.panel.row.mark_unread') }}"
            title="{{ $unread ? __('notifications.panel.row.mark_read') : __('notifications.panel.row.mark_unread') }}"
        >
            <x-filament::icon :icon="$unread ? 'lucide-mail-open' : 'lucide-mail'" class="size-4" />
        </button>

        <button
            type="button"
            class="fi-inbox-icon-btn"
            x-on:click="act(() => $wire.archiveNotification(@js($rowId)), $el, '[data-inbox-main]')"
            aria-label="{{ __('notifications.panel.row.archive') }}"
            title="{{ __('notifications.panel.row.archive') }}"
        >
            <x-filament::icon icon="lucide-archive" class="size-4" />
        </button>
    </div>
</li>

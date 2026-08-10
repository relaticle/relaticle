@php
    $label = $unreadNotificationsCount
        ? trans_choice('filament-panels::layout.actions.open_database_notifications.label_with_unread_count', $unreadNotificationsCount, ['count' => \Illuminate\Support\Number::format($unreadNotificationsCount, locale: app()->getLocale())])
        : __('filament-panels::layout.actions.open_database_notifications.label');
@endphp

<button
    type="button"
    aria-label="{{ $label }}"
    title="{{ $label }}"
    class="fi-sidebar-notifications-btn"
>
    {{ \Filament\Support\generate_icon_html('ri-inbox-line', size: \Filament\Support\Enums\IconSize::Medium) }}

    @if ($unreadNotificationsCount)
        <span class="fi-sidebar-notifications-btn-badge">
            {{ \Illuminate\Support\Number::format($unreadNotificationsCount, locale: app()->getLocale()) }}
        </span>
    @endif
</button>

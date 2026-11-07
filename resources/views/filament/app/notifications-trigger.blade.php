@php
    $attentionCount = $unreadNotificationsCount + ($pendingRequestsCount ?? 0);
    $label = $attentionCount
        ? trans_choice('notifications.panel.open_with_count', $attentionCount, ['count' => \Illuminate\Support\Number::format($attentionCount, locale: app()->getLocale())])
        : __('notifications.panel.open');
@endphp

{{-- A bare `open` resolves to window.open once navigation has torn down the scope, so read it from $data. --}}
<button
    type="button"
    aria-label="{{ $label }}"
    title="{{ $label }}"
    @unless ($isPlaceholder ?? false)
        x-ref="trigger"
        x-on:click="toggle()"
        aria-haspopup="dialog"
        x-bind:aria-expanded="$data.open"
    @endunless
    class="fi-sidebar-notifications-btn"
>
    {{ \Filament\Support\generate_icon_html('lucide-inbox', size: \Filament\Support\Enums\IconSize::Medium) }}

    @if ($attentionCount)
        <span class="fi-sidebar-notifications-btn-badge">
            {{ \Illuminate\Support\Number::format($attentionCount, locale: app()->getLocale()) }}
        </span>
    @endif
</button>

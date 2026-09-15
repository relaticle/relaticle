@props(['account'])

@if (filled($account->last_error) && ! $account->isActive())
    <p {{ $attributes->class(['text-xs text-danger-600 dark:text-danger-400']) }}>
        {{ $account->last_error }}
    </p>
@endif

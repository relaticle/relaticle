@php
    /** @var \Relaticle\EmailIntegration\Enums\AttendeeResponseStatus $status */
    use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
@endphp

<span @class([
    'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium',
    match ($status) {
        AttendeeResponseStatus::NEEDS_ACTION => 'border border-gray-200 bg-white text-gray-600 dark:border-white/10 dark:bg-transparent dark:text-gray-400',
        AttendeeResponseStatus::TENTATIVE => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400',
        AttendeeResponseStatus::ACCEPTED => 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
        AttendeeResponseStatus::DECLINED => 'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400',
    },
])>
    {{ $status->getLabel() }}
</span>

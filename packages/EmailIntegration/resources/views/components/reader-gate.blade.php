@props(['email'])

@php
    use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
    use Relaticle\EmailIntegration\Services\PrivacyService;

    $authUser = auth()->user();
    $level = resolve(PrivacyService::class)->effectiveTier($email, $authUser) === EmailPrivacyTier::SUBJECT ? 'subject' : 'metadata_only';
    $ownerName = $email->user?->name ?? __('filament/pages/email-inbox.pending_access.unknown_user');
    $requested = $email->hasPendingAccessRequestFrom($authUser);
@endphp

<div {{ $attributes->class(['px-4 py-6 sm:px-6']) }} data-reader-gate="{{ $email->getKey() }}">
    <div class="flex flex-col items-center gap-4 rounded-xl border border-dashed border-gray-200 bg-gray-50 px-6 py-8 text-center dark:border-gray-700 dark:bg-gray-900/40">
        <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
            <x-heroicon-o-lock-closed class="size-5 text-gray-400 dark:text-gray-500" aria-hidden="true" />
        </div>

        <div class="space-y-1">
            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                {{ __("filament/pages/email-inbox.drawer.gate.{$level}.heading") }}
            </p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __("filament/pages/email-inbox.drawer.gate.{$level}.description", ['name' => $ownerName]) }}
            </p>
        </div>

        @if ($requested)
            <span class="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                <x-heroicon-m-clock class="size-3.5 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                {{ __('filament/pages/email-inbox.drawer.gate.requested') }}
            </span>
        @elseif ($authUser->can('requestAccess', $email))
            <x-filament::button
                size="sm"
                color="gray"
                icon="heroicon-m-key"
                data-reader-action="request-access"
                wire:click="mountAction('requestAccess', { emailId: '{{ $email->getKey() }}' })"
            >
                {{ __('filament/pages/email-inbox.drawer.gate.request') }}
            </x-filament::button>
        @endif
    </div>
</div>

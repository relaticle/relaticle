@props([
    'isMassSend' => false,
    'massRecipients' => [],
    'showCc' => false,
    'showBcc' => false,
    'fromAvatarUrl' => null,
    'accountOptions' => [],
    'fromAccount' => null,
    'recipientSuggestions' => [],
    'recipientOptions' => [],
    'allowedRecipientAddresses' => [],
])

@php
    $rowClass = 'flex min-h-10 items-center gap-3';
    $labelClass = 'w-14 shrink-0 self-center text-xs font-medium uppercase tracking-wide text-gray-400';
    $errorClass = 'pb-1 text-xs text-danger-600 dark:text-danger-400';
@endphp

<div {{ $attributes->class(['shrink-0 divide-y divide-gray-100 text-sm dark:divide-white/5']) }}>
    <label @class([$rowClass, 'cursor-default'])>
        <span class="{{ $labelClass }}">{{ __('filament/emails/composer.fields.from') }}</span>
        <span class="flex min-w-0 flex-1 items-center gap-2">
            <x-filament::avatar
                :src="$fromAvatarUrl"
                :alt="$fromAccount?->label ?? ''"
                size="h-6 w-6"
                class="shrink-0"
            />
            @if (count($accountOptions) > 1)
                <select wire:model.live="accountId" class="w-full border-0 bg-transparent p-0 text-sm text-gray-900 focus:ring-0 dark:text-gray-100">
                    @foreach ($accountOptions as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            @else
                <span class="truncate text-sm text-gray-900 dark:text-gray-100">{{ $fromAccount?->label }}</span>
            @endif
        </span>
    </label>
    @error('accountId')
        <p class="{{ $errorClass }}">{{ $message }}</p>
    @enderror

    <div class="{{ $rowClass }}">
        <span class="{{ $labelClass }}">{{ __('filament/emails/composer.fields.to') }}</span>
        @if ($isMassSend)
            <x-emails.composer-mass-send-to-summary :count="count($massRecipients)" />
        @else
            <div class="flex min-w-0 flex-1 items-center self-stretch">
                <x-emails.recipient-chips
                    wire:model="to"
                    :autofocus="true"
                    :suggestions="$recipientSuggestions"
                    :options="$recipientOptions"
                    :allowed-addresses="$allowedRecipientAddresses"
                    class="w-full"
                />
            </div>
            <span class="shrink-0 space-x-2 text-xs font-medium text-gray-400">
                <button type="button" wire:click="toggleCc" @class(['transition hover:text-gray-700 dark:hover:text-gray-200', 'text-primary-600 dark:text-primary-400' => $showCc])>{{ __('filament/emails/composer.fields.cc') }}</button>
                <button type="button" wire:click="toggleBcc" @class(['transition hover:text-gray-700 dark:hover:text-gray-200', 'text-primary-600 dark:text-primary-400' => $showBcc])>{{ __('filament/emails/composer.fields.bcc') }}</button>
            </span>
        @endif
    </div>
    @unless ($isMassSend)
        @error('to')
            <p class="{{ $errorClass }}">{{ $message }}</p>
        @enderror
        {{-- The `to.*` => email rule keys its errors per array index (to.0, to.1, ...). --}}
        @error('to.*')
            <p class="{{ $errorClass }}">{{ $message }}</p>
        @enderror
    @else
        @error('massRecipients')
            <p class="{{ $errorClass }}">{{ $message }}</p>
        @enderror
    @endunless

    @if ($showCc && ! $isMassSend)
        <div class="{{ $rowClass }}">
            <span class="{{ $labelClass }}">{{ __('filament/emails/composer.fields.cc') }}</span>
            <div class="flex min-w-0 flex-1 items-center self-stretch">
                <x-emails.recipient-chips
                    wire:model="cc"
                    :suggestions="$recipientSuggestions"
                    :options="$recipientOptions"
                    :allowed-addresses="$allowedRecipientAddresses"
                    class="w-full"
                />
            </div>
        </div>
        @error('cc.*')
            <p class="{{ $errorClass }}">{{ $message }}</p>
        @enderror
    @endif

    @if ($showBcc && ! $isMassSend)
        <div class="{{ $rowClass }}">
            <span class="{{ $labelClass }}">{{ __('filament/emails/composer.fields.bcc') }}</span>
            <div class="flex min-w-0 flex-1 items-center self-stretch">
                <x-emails.recipient-chips
                    wire:model="bcc"
                    :suggestions="$recipientSuggestions"
                    :options="$recipientOptions"
                    :allowed-addresses="$allowedRecipientAddresses"
                    class="w-full"
                />
            </div>
        </div>
        @error('bcc.*')
            <p class="{{ $errorClass }}">{{ $message }}</p>
        @enderror
    @endif

    <div class="border-b border-gray-100 dark:border-white/5">
        <label for="email-composer-subject" class="{{ $rowClass }}">
            <span class="{{ $labelClass }}">{{ __('filament/emails/composer.fields.subject') }}</span>
            <input
                id="email-composer-subject"
                type="text"
                wire:model="subject"
                placeholder="{{ __('filament/emails/composer.fields.subject_placeholder') }}"
                class="h-10 w-full min-w-0 flex-1 border-0 bg-transparent p-0 text-sm leading-10 text-gray-900 shadow-none placeholder:text-gray-400 focus:border-0 focus:outline-none focus:ring-0 focus-visible:outline-none focus-visible:ring-0 dark:text-gray-100"
            />
        </label>
        @error('subject')
            <p class="{{ $errorClass }}">{{ $message }}</p>
        @enderror
    </div>
</div>

@php
    $mailbox = $this->mailboxRecord();
    $tier = $this->chosenTier();
    $rows = $this->previewRows();
    $decode = fn (?string $text): string => html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
@endphp

<x-filament-panels::page>
    <form
        wire:submit="save"
        @if ($mailbox->isImportingHistory()) wire:poll.5s @endif
        class="mx-auto flex w-full max-w-xl flex-col"
    >
        <p class="text-sm text-gray-500 dark:text-gray-400">
            @if ($mailbox->isImportingHistory())
                {{ __('filament/pages/email-accounts.sharing_step.importing') }}
            @endif
            {{ __('filament/pages/email-accounts.sharing_step.description', ['mailbox' => $mailbox->email_address]) }}
        </p>

        <x-email-integration::sharing-tier-choice
            class="mt-5"
            :selected="$sharingTier"
            :label="__('filament/pages/email-accounts.sharing_step.title')"
        />

        @error('sharingTier')
            <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
        @enderror

        <section class="mt-6" data-sharing-preview="{{ $tier->value }}">
            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                {{ __('filament/pages/email-accounts.sharing_step.preview.heading') }}
            </h2>

            @if ($rows === [])
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('filament/pages/email-accounts.sharing_step.preview.empty') }}
                </p>
            @else
                <ul class="mt-2 divide-y divide-gray-950/5 overflow-hidden rounded-xl bg-white ring-1 ring-gray-950/10 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">
                    @foreach ($rows as $row)
                        <li class="flex min-w-0 flex-col gap-0.5 px-4 py-3" data-preview-conversation="{{ $row->conversationKey }}">
                            <span class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                {{ implode(', ', $row->senderNames !== [] ? $row->senderNames : $row->participants) }}
                            </span>
                            <span class="flex min-w-0 items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                                @if ($tier->showsSubject())
                                    <span class="truncate">{{ filled($row->subject) ? $decode($row->subject) : __('filament/pages/email-inbox.subject.none') }}</span>
                                @else
                                    <x-filament::icon icon="lucide-lock" class="size-3.5 shrink-0" />
                                    <span class="truncate">{{ __('filament/pages/email-accounts.sharing_step.preview.subject_hidden') }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <x-filament::link :href="$this->moreOptionsUrl()" color="gray" size="sm">
                {{ __('filament/pages/email-accounts.sharing_step.more_options') }}
            </x-filament::link>

            <x-filament::button type="submit" wire:target="save">
                {{ __('filament/pages/email-accounts.sharing_step.save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>

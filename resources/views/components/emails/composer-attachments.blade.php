{{-- Files already saved on the draft, then the uploads still pending. Only the
     saved ones have a row to stream back, so only they offer a download. --}}
@props(['saved' => [], 'pending' => []])

@if ($saved !== [] || $pending !== [])
    <div {{ $attributes->class(['flex shrink-0 flex-wrap gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5']) }}>
        @foreach ($saved as $attachment)
            <x-emails.attachment-card
                wire:key="saved-attachment-{{ $attachment['id'] }}"
                :filename="$attachment['filename']"
                :size="$attachment['size']"
            >
                <a
                    href="{{ route('email-attachments.download', $attachment['id']) }}"
                    download
                    aria-label="{{ __('filament/emails/composer.actions.download_attachment') }}"
                    class="shrink-0 rounded-md p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200"
                >
                    <x-heroicon-m-arrow-down-tray class="h-4 w-4" />
                </a>

                <x-emails.composer-attachment-remove wire:click="removeSavedAttachment('{{ $attachment['id'] }}')" />
            </x-emails.attachment-card>
        @endforeach

        @foreach ($pending as $index => $attachment)
            <x-emails.attachment-card
                wire:key="attachment-{{ $index }}"
                :filename="$attachment->getClientOriginalName()"
                :size="$attachment->getSize()"
            >
                <x-emails.composer-attachment-remove wire:click="removeAttachment({{ $index }})" />
            </x-emails.attachment-card>
        @endforeach
    </div>
@endif

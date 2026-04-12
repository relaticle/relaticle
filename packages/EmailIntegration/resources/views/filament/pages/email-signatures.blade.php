<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Email Signatures">

            @forelse($this->signatures as $signature)
                <div class="flex items-start justify-between gap-4 rounded-lg border p-4 dark:border-gray-700">
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $signature->name }}</p>
                            @if ($signature->is_default)
                                <x-filament::badge color="success" size="sm">Default</x-filament::badge>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $signature->connectedAccount->email_address }}
                        </p>
                        <div class="prose prose-xs dark:prose-invert mt-2 max-w-none rounded border border-gray-100 bg-gray-50 px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
                            {!! $signature->content_html !!}
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        {{ ($this->editSignatureAction)(['signature_id' => $signature->id]) }}
                        {{ ($this->deleteSignatureAction)(['signature_id' => $signature->id]) }}
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No signatures created yet. Click <strong>New Signature</strong> to add one.
                </p>
            @endforelse
        </x-filament::section>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>

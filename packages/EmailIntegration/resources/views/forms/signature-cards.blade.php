<div class="space-y-3">
    @forelse ($this->signatures as $signature)
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="flex items-start justify-between gap-3">
                <div class="flex min-w-0 items-center gap-2">
                    <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                        {{ $signature->name }}
                    </p>

                    @if ($signature->is_default)
                        <x-filament::badge color="success" class="shrink-0">
                            {{ __('filament/pages/email-accounts.default_badge') }}
                        </x-filament::badge>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    {{ ($this->editSignatureAction)(['signature_id' => $signature->getKey()]) }}
                    {{ ($this->deleteSignatureAction)(['signature_id' => $signature->getKey()]) }}
                </div>
            </div>

            <div class="prose prose-sm dark:prose-invert mt-3 max-w-none rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
                {!! app(\Relaticle\EmailIntegration\Services\HtmlSanitizerService::class)->sanitizeRichText($signature->content_html) !!}
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-dashed border-gray-300 px-6 py-10 text-center dark:border-white/20">
            <p class="text-sm font-medium text-gray-950 dark:text-white">
                {{ __('filament/pages/email-account-settings.signatures.empty_heading') }}
            </p>
            <p class="mx-auto mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">
                {{ __('filament/pages/email-account-settings.signatures.empty_description') }}
            </p>

            <div class="mt-4 flex justify-center">
                {{ $this->createSignatureAction }}
            </div>
        </div>
    @endforelse
</div>

<div class="flex flex-col gap-4">
    <div class="mx-auto w-fit rounded-xl bg-white p-3 ring-1 ring-gray-950/10 [&>svg]:size-40">
        {!! $this->pendingQrSvg !!}
    </div>

    <div class="flex flex-col gap-2">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('profile.sections.mfa.manual_hint') }}</p>

        <div class="flex items-center gap-3 rounded-lg bg-gray-100 px-3 py-2 dark:bg-gray-800">
            <code class="min-w-0 grow font-mono text-xs break-all text-gray-900 dark:text-gray-100">{{ $this->pendingSecret }}</code>

            <x-auth.copy-button :value="$this->pendingSecret" :label="__('profile.sections.mfa.copy_key')" />
        </div>
    </div>
</div>

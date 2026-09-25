<div class="space-y-2 rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
    <ul class="grid gap-2 font-mono text-sm text-gray-900 sm:grid-cols-2 dark:text-gray-100">
        @foreach ($this->revealedRecoveryCodes as $recoveryCode)
            <li wire:key="recovery-code-{{ $loop->index }}">{{ $recoveryCode }}</li>
        @endforeach
    </ul>

    <div class="flex justify-end border-t border-gray-200 pt-2 dark:border-gray-700">
        <x-auth.copy-button
            :value="implode(PHP_EOL, $this->revealedRecoveryCodes)"
            :label="__('profile.sections.mfa.copy_codes')"
        />
    </div>
</div>

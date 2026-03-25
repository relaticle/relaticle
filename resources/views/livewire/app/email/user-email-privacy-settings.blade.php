<div class="space-y-6">
    <x-filament::section>
        <x-slot name="heading">My Email Sharing Preference</x-slot>
        <x-slot name="description">
            Overrides the workspace default for emails you sync. Set to blank to use the workspace default.
        </x-slot>

        <div>
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
                Default sharing tier
            </label>
            <select
                wire:model="default_email_sharing_tier"
                class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm text-sm dark:bg-gray-800 dark:border-gray-700 dark:text-white"
            >
                @foreach($this->getTierOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Blocked Addresses & Domains</x-slot>
        <x-slot name="description">
            Emails involving these addresses or domains will be hidden from your view.
        </x-slot>

        <div class="space-y-3">
            @foreach($blocklist as $index => $entry)
                <div class="flex items-center gap-3">
                    <select
                        wire:model="blocklist.{{ $index }}.type"
                        class="rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-gray-700 dark:text-white"
                    >
                        @foreach($this->getTypeOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <input
                        wire:model="blocklist.{{ $index }}.value"
                        type="text"
                        placeholder="e.g. spam@example.com or spammy.com"
                        class="flex-1 rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-gray-700 dark:text-white"
                    />
                    <button
                        type="button"
                        wire:click="removeBlocklistEntry({{ $index }})"
                        class="text-danger-500 hover:text-danger-700 text-sm font-medium"
                    >
                        Remove
                    </button>
                </div>
            @endforeach

            <button
                type="button"
                wire:click="addBlocklistEntry"
                class="text-sm text-primary-600 hover:text-primary-800 font-medium"
            >
                + Add entry
            </button>
        </div>
    </x-filament::section>

    <x-filament::button wire:click="save">
        Save
    </x-filament::button>
</div>

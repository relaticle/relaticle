@php
    use Illuminate\Support\Str;

    /** @var \App\Filament\Components\Forms\WorkspaceLogoUpload $field */
    $id = $field->getId();
    $key = $field->getKey();
    $statePath = $field->getStatePath();
    $isDisabled = $field->isDisabled();
    $acceptedFileTypes = $field->getAcceptedFileTypes() ?? [];
    $maxKilobytes = $field->getMaxSize();
    $allowedLabels = collect($acceptedFileTypes)->map(fn (string $type): string => Str::upper(Str::after($type, '/')))->join(', ');
@endphp

<div
    id="{{ $id }}"
    role="group"
    aria-labelledby="{{ $id }}-label"
    data-logo-upload
    wire:ignore
    wire:key="{{ $field->getLivewireKey() }}.{{ (int) $isDisabled }}"
    x-data="{
        previewUrl: null,
        isUploading: false,
        isDragging: false,
        error: null,
        stateKeys() {
            return Object.keys($wire.get(@js($statePath)) ?? {})
        },
        async init() {
            if (this.stateKeys().length === 0) {
                return
            }

            const files = await Livewire.fireAction(
                $wire.__instance,
                'callSchemaComponentMethod',
                [@js($key), 'getUploadedFiles'],
                { async: true },
            )

            this.previewUrl ??= Object.values(files ?? {}).find((file) => file?.url)?.url ?? null
        },
        pick() {
            if (! this.isUploading) {
                this.$refs.input.click()
            }
        },
        async squared(file) {
            const bitmap = await createImageBitmap(file)
            const side = Math.min(bitmap.width, bitmap.height)
            const size = Math.min(side, 500)
            const canvas = Object.assign(document.createElement('canvas'), { width: size, height: size })

            canvas.getContext('2d').drawImage(bitmap, (bitmap.width - side) / 2, (bitmap.height - side) / 2, side, side, 0, 0, size, size)

            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))

            return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.png', { type: 'image/png' })
        },
        async handle(file) {
            if (! file || this.isUploading) {
                return
            }

            if (! @js($acceptedFileTypes).includes(file.type)) {
                this.error = @js(__('uploads.errors.mime_not_allowed', ['mime' => ':mime', 'allowed' => $allowedLabels])).replace(':mime', file.type || file.name)

                return
            }

            if (@js($maxKilobytes) && file.size > @js($maxKilobytes) * 1024) {
                this.error = @js(__('uploads.errors.too_large', ['max' => (int) round(($maxKilobytes ?? 0) / 1024)]))

                return
            }

            const previousKeys = this.stateKeys()
            const previousUrl = this.previewUrl
            let logo

            try {
                logo = await this.squared(file)
            } catch {
                this.error = @js(__('uploads.logo.failed'))

                return
            }

            this.error = null
            this.previewUrl = URL.createObjectURL(logo)
            this.isUploading = true

            $wire.upload(
                `{{ $statePath }}.${crypto.randomUUID()}`,
                logo,
                async () => {
                    for (const fileKey of previousKeys) {
                        await $wire.callSchemaComponentMethod(@js($key), 'removeUploadedFile', { fileKey })
                    }

                    this.isUploading = false
                },
                () => {
                    this.isUploading = false
                    this.previewUrl = previousUrl
                    this.error = @js(__('uploads.logo.failed'))
                },
            )
        },
        async remove() {
            for (const fileKey of this.stateKeys()) {
                await $wire.callSchemaComponentMethod(@js($key), 'removeUploadedFile', { fileKey })
            }

            this.previewUrl = null
            this.error = null
        },
    }"
    class="flex items-center gap-4"
>
    <button
        type="button"
        x-on:click="pick()"
        x-on:dragover.prevent="isDragging = ! isUploading"
        x-on:dragleave.prevent="isDragging = false"
        x-on:drop.prevent="isDragging = false; handle($event.dataTransfer.files[0])"
        @disabled($isDisabled)
        aria-describedby="{{ $id }}-description"
        x-bind:aria-label="previewUrl ? @js(__('uploads.logo.replace')) : @js(__('uploads.logo.upload'))"
        x-bind:class="isDragging
            ? 'border-primary-500 bg-primary-50 text-primary-600 ring-4 ring-primary-500/15 dark:border-primary-400 dark:bg-primary-500/10 dark:text-primary-400'
            : (previewUrl ? 'border-transparent' : 'border-gray-300 text-gray-400 hover:border-primary-400 hover:text-primary-500 dark:border-white/15 dark:text-gray-500 dark:hover:border-primary-400 dark:hover:text-primary-400')"
        class="group relative flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-dashed bg-gray-50 transition duration-150 outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-not-allowed dark:bg-white/5"
    >
        <x-filament::icon icon="ri-image-add-line" class="size-6" x-show="! previewUrl" />

        <img
            x-show="previewUrl"
            x-bind:src="previewUrl"
            alt=""
            class="absolute inset-0 size-full object-cover"
            x-cloak
        />

        <span
            x-show="previewUrl && ! isUploading"
            x-cloak
            class="absolute inset-0 flex items-center justify-center bg-gray-950/50 text-white opacity-0 transition duration-150 group-hover:opacity-100 group-focus-visible:opacity-100"
        >
            <x-filament::icon icon="ri-upload-2-line" class="size-5" />
        </span>

        <span
            x-show="isUploading"
            x-cloak
            class="absolute inset-0 flex items-center justify-center bg-white/70 dark:bg-gray-900/70"
        >
            <x-filament::loading-indicator class="size-5 text-primary-600 dark:text-primary-400" />
        </span>
    </button>

    <div class="min-w-0">
        <p id="{{ $id }}-label" class="text-sm font-medium text-gray-950 dark:text-white">{{ $field->getLabel() }}</p>
        <p id="{{ $id }}-description" class="text-sm text-gray-500 dark:text-gray-400">{{ $field->getDescription() }}</p>

        @unless ($isDisabled)
            <button
                type="button"
                x-show="previewUrl && ! isUploading"
                x-cloak
                x-on:click="remove()"
                class="mt-1 text-xs font-medium text-gray-500 transition hover:text-danger-600 dark:text-gray-400 dark:hover:text-danger-400"
            >
                {{ __('uploads.logo.remove') }}
            </button>
        @endunless

        <p x-show="error" x-text="error" x-cloak role="alert" class="mt-2 text-sm text-danger-600 dark:text-danger-400"></p>
    </div>

    <input
        x-ref="input"
        type="file"
        class="sr-only"
        tabindex="-1"
        accept="{{ implode(',', $acceptedFileTypes) }}"
        x-on:change="handle($event.target.files[0]); $event.target.value = ''"
        @disabled($isDisabled)
    />
</div>

<x-onboarding.shell>
    <div class="flex h-full">
        <div class="flex flex-1 flex-col px-10 py-10 sm:px-12 sm:py-12">
            @include('filament.pages.setup-workspace.'.$this->stepView())
        </div>

        <div class="hidden w-[48%] shrink-0 border-s border-gray-950/5 bg-gray-50 lg:block dark:border-white/10 dark:bg-gray-950">
            <x-onboarding.crm-preview :preview="$this->getPreview()" :panel="$this->previewPanel()" />
        </div>
    </div>
</x-onboarding.shell>

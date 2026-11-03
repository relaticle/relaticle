<x-cloud::marketing.frame id="email">
    <x-cloud::marketing.frame-header :label="__('Email and calendar')" :lead="__('Your inbox fills in your CRM.')">
        {{ __('No typing, no copy and paste.') }}
    </x-cloud::marketing.frame-header>

    <x-cloud::marketing.showcase
        data-feature-row
        :label="__('Gmail and Outlook')"
        :lead="__('Every email and meeting on the record.')"
        :facts="[__('Connect a Google or Microsoft account'), __('Relaticle never changes or deletes a message'), __('Share who you talked to, the subject, or the full email')]"
        :link-label="__('How email works in Relaticle')"
        :link-href="route('emailAndCalendar')"
    >
        {{ __('People and companies fill in from your inbox.') }}

        <x-slot:visual>
            <div class="bg-warm flex min-h-[inherit] items-center justify-center p-4 sm:p-8 md:p-12">
                <div class="w-full max-w-xl space-y-3" data-mail-demo>
                    <p class="flex w-fit max-w-full items-center gap-2 rounded-2xl bg-white/80 py-1 pl-1 pr-3 text-xs text-gray-700 ring-1 ring-gray-950/5 backdrop-blur-sm dark:bg-white/[0.06] dark:text-gray-200 dark:ring-white/10" data-mail-caption>
                        <x-cloud::marketing.actor as="mailbox" class="shrink-0"/>
                        <span class="py-0.5 sm:truncate">{{ __('Added Lena Ortiz and Northwind after your first email') }}</span>
                    </p>

                    <x-cloud::marketing.record-emails-mock/>
                </div>
            </div>
        </x-slot:visual>
    </x-cloud::marketing.showcase>
</x-cloud::marketing.frame>

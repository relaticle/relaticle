<p {{ $attributes->class(['text-xs text-gray-500 dark:text-gray-400']) }}>
    {{ __('filament/emails/composer.mass_send.outbox_hint') }}
    <a
        href="{{ \Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage::getUrl(['tab' => 'outbox'], tenant: filament()->getTenant()) }}"
        class="font-medium text-primary-600 hover:underline dark:text-primary-400"
    >
        {{ __('filament/emails/composer.mass_send.view_outbox') }}
    </a>
</p>

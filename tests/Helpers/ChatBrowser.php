<?php

declare(strict_types=1);

namespace Tests\Helpers;

final class ChatBrowser
{
    /**
     * JS that resolves the chat interface's Alpine component on the current page.
     *
     * Since the chat drawer rework the interface is rendered collapsed on the
     * dashboard, so `offsetParent` is null even though the component is mounted
     * and its data is live. Prefer a visible host when there is one — a page may
     * mount both the drawer and an inline interface — and otherwise fall back to
     * the mounted one rather than handing `undefined` to `Alpine.$data()`.
     */
    public static function resolveInterface(string $variable = 'data'): string
    {
        return <<<JS
            const hosts = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const host = hosts.find((el) => el.offsetParent !== null) ?? hosts[0];

            if (! host) {
                throw new Error('No chatInterface component is mounted on this page.');
            }

            const {$variable} = Alpine.\$data(host);
        JS;
    }
}

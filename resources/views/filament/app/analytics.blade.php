@if(app()->isProduction() && !empty(config('services.fathom.site_id')))
    <script src="https://cdn.usefathom.com/script.js"
        data-site="{{ config('services.fathom.site_id') }}"
        data-auto="false"
        defer></script>
<script>
(() => {
    function normalizeUrl(pathname) {
        // Panel-level pages carry no tenant slug, so track them as-is and the
        // auth funnel (/login, /register, …) doesn't collapse into /dashboard.
        var tenantless = ['/login', '/register', '/forgot-password', '/password-reset', '/email-verification', '/two-factor-authentication', '/new', '/logout'];
        for (var i = 0; i < tenantless.length; i++) {
            if (pathname === tenantless[i] || pathname.indexOf(tenantless[i] + '/') === 0) {
                return pathname;
            }
        }

        // Remove tenant slug: /my-workspace/people → /people
        pathname = pathname.replace(/^\/[^\/]+/, '');

        // Normalize record IDs (numeric or ULID) out of paths:
        // /people/01abc123 → /people/view
        // /people/01abc123/edit → /people/edit
        pathname = pathname
            .replace(/^(\/[\w-]+)\/[^\/]+\/(\w+)$/, '$1/$2')          // /resource/id/action → /resource/action
            .replace(/^(\/[\w-]+)\/(?=[^\/]*\d)[^\/]+$/, '$1/view');  // /resource/id → /resource/view (id must contain a digit)

        return pathname || '/dashboard';
    }

    function track() {
        if (typeof fathom !== 'undefined') {
            const normalized = window.location.origin + normalizeUrl(window.location.pathname);
            fathom.trackPageview({ url: normalized });
        }
    }

    document.addEventListener('livewire:navigated', track);
})();
</script>
@foreach (['signup', 'workspace_created'] as $event)
    @if(session()->pull('fathom.track_'.$event))
        <script>
            document.addEventListener('livewire:navigated', () => {
                if (typeof fathom !== 'undefined') {
                    fathom.trackEvent(@js($event));
                }
            }, { once: true });
        </script>
    @endif
@endforeach
@endif

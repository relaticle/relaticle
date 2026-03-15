@if(app()->isProduction() && !empty(config('services.fathom.site_id')))
    <script src="https://cdn.usefathom.com/script.js"
        data-site="{{ config('services.fathom.site_id') }}"
        data-spa="auto"
        data-auto="false"
        defer></script>
<script>
window.addEventListener('load', function() {
    function normalizeUrl(pathname) {
        // Remove tenant slug: /my-team/people → /people
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

    // Initial track
    setTimeout(track, 100);

    // SPA navigation
    document.addEventListener('livewire:navigated', track);
});
</script>
@endif

@if(app()->isProduction() && !empty(config('services.fathom.site_id')))
    @php
        $analyticsEvents = [];
        $analyticsEventNames = [
            'signup', 'workspace_created', 'signup_started', 'email_verified', 'login_completed', 'setup_completed', 'contact_submitted', 'checkout_started',
            'google_auth_started', 'microsoft_auth_started',
            'signup_refused_email', 'signup_refused_password', 'signup_refused_challenge', 'signup_refused_challenge_blocked', 'signup_refused_tor', 'signup_refused_throttled', 'signup_refused_existing_account',
        ];
        foreach ($analyticsEventNames as $analyticsEvent) {
            $operation = session()->pull('fathom.track_'.$analyticsEvent);
            if ($operation === true || (is_string($operation) && \Illuminate\Support\Str::isUuid($operation))) {
                $analyticsEvents[] = ['id' => $operation === true ? (string) \Illuminate\Support\Str::uuid() : $operation, 'name' => $analyticsEvent];
            }
        }
        $analyticsPayload = [
            'site' => config('services.fathom.site_id'),
            'panel' => $panel ?? true,
            'prefix' => config('app.app_panel_domain') ? '' : '/'.trim(config('app.app_panel_path', 'app'), '/'),
            'events' => $analyticsEvents,
        ];
    @endphp
    <script type="application/json" data-relaticle-analytics>{!! json_encode($analyticsPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
    <script>
    (() => {
        const storageKey = 'relaticle.analytics.pending.v1';
        if (navigator.doNotTrack === '1' || navigator.globalPrivacyControl === true) {
            window.relaticleAnalytics?.clear();
            try {
                sessionStorage.removeItem(storageKey);
            } catch {}
            document.querySelectorAll('[data-relaticle-analytics]').forEach(element => element.remove());
            return;
        }
        if (window.relaticleAnalytics) {
            window.relaticleAnalytics.refresh();
            return;
        }

        const lifetime = 86400000;
        const conversions = @js($analyticsEventNames);
        const placements = ['header', 'mobile', 'hero', 'pricing', 'footer', 'docs', 'capabilities', 'page'];
        const names = new Set([...conversions, ...placements.flatMap(placement => [`signup_cta_${placement}`, `login_cta_${placement}`])]);
        let settings;
        let tracker;
        let pageview;
        let previousLocation;
        let engagement;
        let attempted = false;
        let state = { pending: [], sent: [] };

        function clear() {
            state = { pending: [], sent: [] };
            engagement = null;
            pageview = false;
            try {
                sessionStorage.removeItem(storageKey);
            } catch {}
        }

        function optedOut() {
            if (navigator.doNotTrack !== '1' && navigator.globalPrivacyControl !== true) {
                return false;
            }
            clear();
            return true;
        }

        function validOperation(operation) {
            return operation && typeof operation.id === 'string'
                && /^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i.test(operation.id)
                && names.has(operation.name) && Number.isFinite(operation.time)
                && operation.time > Date.now() - lifetime && operation.time <= Date.now();
        }

        try {
            const saved = JSON.parse(sessionStorage.getItem(storageKey));
            if (saved && Array.isArray(saved.pending) && Array.isArray(saved.sent)) {
                state = { pending: saved.pending.filter(validOperation).slice(-50), sent: saved.sent.filter(id => typeof id === 'string').slice(-100) };
            }
        } catch {}

        function persist() {
            state.pending = state.pending.filter(validOperation).slice(-50);
            state.sent = state.sent.slice(-100);
            try {
                sessionStorage.setItem(storageKey, JSON.stringify(state));
            } catch {}
        }

        function normalizedPath(pathname) {
            if (!settings.panel) {
                const publicPaths = ['/', '/pricing', '/contact', '/ai', '/ai-native-crm', '/self-hosted', '/security', '/press', '/terms-of-service', '/privacy-policy', '/crm-for-claude', '/crm-for-chatgpt'];
                if (publicPaths.includes(pathname)) {
                    return pathname;
                }
                const section = pathname.split('/')[1];
                return ['help', 'developers', 'docs', 'documentation', 'blog', 'compare', 'alternatives'].includes(section) ? `/${section}` : '/other';
            }

            const path = settings.prefix && (pathname === settings.prefix || pathname.startsWith(`${settings.prefix}/`))
                ? pathname.slice(settings.prefix.length) : pathname;
            const parts = path.split('/').filter(Boolean);
            const authPages = ['login', 'register', 'forgot-password', 'password-reset', 'email-verification', 'two-factor-authentication', 'new', 'logout'];
            if (authPages.includes(parts[0])) {
                return `/${parts[0]}`;
            }
            parts.shift();
            const resources = ['companies', 'people', 'opportunities', 'tasks', 'notes'];
            if (resources.includes(parts[0])) {
                const action = parts.length < 2 ? '' : parts[1] === 'create' ? '/create' : parts[2] === 'edit' ? '/edit' : '/view';
                return `/${parts[0]}${action}`;
            }
            const pages = ['dashboard', 'settings', 'billing', 'profile', 'imports', 'exports'];
            return pages.includes(parts[0]) ? `/${parts[0]}` : '/dashboard';
        }

        function safeReferrer() {
            try {
                const referrer = new URL(document.referrer);
                return referrer.protocol === 'https:' && ['relaticle.com', 'www.relaticle.com', 'app.relaticle.com', 'google.com', 'www.google.com', 'bing.com', 'www.bing.com', 'github.com'].includes(referrer.hostname)
                    ? referrer.origin : '';
            } catch {
                return '';
            }
        }

        function transportPayload(params) {
            const payload = { sid: settings.site, h: window.location.origin, p: normalizedPath(window.location.pathname), r: safeReferrer(), qs: '{}' };
            if (params.name) {
                if (!names.has(params.name)) {
                    return null;
                }
                return { ...payload, name: params.name, payload: '{}' };
            }
            if (params.dp === 1) {
                return { ...payload, p: engagement?.path ?? payload.p, dp: 1, d: Math.max(0, Math.min(1800, Number(params.d) || 0)) };
            }
            return payload;
        }

        function installTransport() {
            if (tracker && tracker === window.fathom) {
                return true;
            }
            const candidate = window.fathom;
            if (!candidate || !['send', 'beacon', 'isTrackingEnabled'].every(method => typeof candidate[method] === 'function')) {
                return false;
            }
            try {
                for (const method of ['send', 'beacon']) {
                    const original = candidate[method].bind(candidate);
                    const wrapped = params => {
                        const payload = transportPayload(params);
                        if (optedOut() || !payload || !candidate.isTrackingEnabled()) {
                            return;
                        }
                        original(payload);
                        attempted = true;
                    };
                    candidate[method] = wrapped;
                    if (candidate[method] !== wrapped) {
                        return false;
                    }
                }
                tracker = candidate;
                return true;
            } catch {
                return false;
            }
        }

        function depart() {
            if (!engagement || !tracker) {
                return;
            }
            const duration = Math.min(1800, Math.round((Date.now() - engagement.start) / 1000));
            if (duration > 0) {
                try {
                    tracker.beacon({ dp: 1, d: duration });
                } catch {}
            }
            engagement = null;
        }

        function flush() {
            try {
                if (optedOut() || !installTransport() || !tracker.isTrackingEnabled() || !document.body) {
                    return;
                }
                persist();
                if (pageview) {
                    depart();
                    attempted = false;
                    // The SDK's trackPageview stores raw queries for a private departure transport.
                    tracker.send({});
                    if (attempted) {
                        engagement = { path: normalizedPath(window.location.pathname), start: Date.now() };
                        pageview = false;
                    }
                }
                for (const operation of [...state.pending]) {
                    attempted = false;
                    tracker.beacon({ name: operation.name });
                    if (!attempted) {
                        break;
                    }
                    state.pending = state.pending.filter(pending => pending.id !== operation.id);
                    state.sent.push(operation.id);
                    persist();
                }
            } catch {}
        }

        function enqueue(operation) {
            if (optedOut() || !validOperation(operation) || state.sent.includes(operation.id) || state.pending.some(pending => pending.id === operation.id)) {
                return;
            }
            state.pending.push(operation);
            persist();
        }

        function refresh() {
            document.querySelectorAll('[data-relaticle-analytics]').forEach(element => {
                settings = JSON.parse(element.textContent);
                for (const operation of settings.events) {
                    enqueue({ ...operation, time: Date.now() });
                }
                element.remove();
            });
            if (previousLocation !== window.location.href) {
                depart();
                previousLocation = window.location.href;
                pageview = true;
            }
            flush();
        }

        window.relaticleAnalytics = { refresh, clear };
        document.addEventListener('livewire:navigated', refresh);
        document.addEventListener('DOMContentLoaded', refresh, { once: true });
        window.addEventListener('online', flush);
        window.addEventListener('pagehide', depart);
        document.addEventListener('analytics-event', event => {
            if (!conversions.includes(event.detail?.name)) {
                return;
            }
            enqueue({ id: event.detail.id, name: event.detail.name, time: Date.now() });
            flush();
        });
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                depart();
            } else {
                flush();
            }
        });
        document.addEventListener('click', event => {
            const link = event.target.closest?.('[data-analytics-intent][data-analytics-placement]');
            if (!link || !['signup', 'login'].includes(link.dataset.analyticsIntent) || !placements.includes(link.dataset.analyticsPlacement)) {
                return;
            }
            try {
                enqueue({ id: crypto.randomUUID(), name: `${link.dataset.analyticsIntent}_cta_${link.dataset.analyticsPlacement}`, time: Date.now() });
                flush();
            } catch {}
        });
        refresh();

        const script = document.createElement('script');
        script.src = 'https://cdn.usefathom.com/script.js';
        script.dataset.site = settings.site;
        script.dataset.auto = 'false';
        script.dataset.canonical = 'false';
        script.dataset.honorDnt = 'true';
        script.referrerPolicy = 'no-referrer';
        script.defer = true;
        script.addEventListener('load', flush, { once: true });
        document.head.appendChild(script);
    })();
    </script>
@endif

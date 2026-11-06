<?php

declare(strict_types=1);

use Illuminate\Support\Str;

function analyticsBrowserScript(bool $panel = true): string
{
    app()['env'] = 'production';
    config(['services.fathom.site_id' => 'TESTSITE']);
    $html = json_encode(view('filament.app.analytics', ['panel' => $panel])->render(), JSON_THROW_ON_ERROR);
    app()['env'] = 'testing';

    return <<<JS
        (() => {
            const append = document.head.appendChild.bind(document.head);
            document.head.appendChild = element => element.src === 'https://cdn.usefathom.com/script.js' ? element : append(element);
            const markup = new DOMParser().parseFromString({$html}, 'text/html');
            document.head.appendChild(markup.querySelector('[data-relaticle-analytics]'));
            const script = document.createElement('script');
            script.textContent = markup.querySelector('script:not([type])').textContent;
            document.head.appendChild(script);
            document.head.appendChild = append;
        })();
        JS;
}

function analyticsBrowserTracker(): string
{
    return <<<'JS'
        window.analyticsCalls = [];
        window.fathom = {
            send(payload) { window.analyticsCalls.push(payload); },
            beacon(payload) { window.analyticsCalls.push(payload); },
            isTrackingEnabled() { return !window.analyticsBlocked; },
        };
        JS;
}

it('keeps a blocked conversion across reload and attempts each operation once when ready', function (): void {
    $operation = (string) Str::uuid();
    session()->put('fathom.track_signup', $operation);
    $script = analyticsBrowserScript();
    $page = $this->visit('/up');
    $page->script($script);

    $page->assertScript('JSON.parse(sessionStorage.getItem("relaticle.analytics.pending.v1")).pending.length', 1)
        ->refresh();
    $page->script(analyticsBrowserTracker().'window.analyticsBlocked = true;');
    $page->script($script);
    $page->assertScript('window.analyticsCalls.length', 0);
    $page->script('window.analyticsBlocked = false; window.dispatchEvent(new Event("online"));');
    $page->assertScript('window.analyticsCalls.map(call => call.name || "pageview")', ['pageview', 'signup'])
        ->assertScript('JSON.parse(sessionStorage.getItem("relaticle.analytics.pending.v1")).pending.length', 0);
    $page->script($script);
    $page->script('document.dispatchEvent(new Event("livewire:navigated"));');
    $page->assertScript('window.analyticsCalls.length', 2)
        ->assertNoJavaScriptErrors();
});

it('tracks live discovery immediately and distinguishes explicit signup and login CTAs to the same URL', function (): void {
    $script = analyticsBrowserScript(false);
    $page = $this->visit('/up');
    $page->script(analyticsBrowserTracker());
    $page->script($script);
    $page->script($script);
    $page->script(<<<'JS'
        const discovery = { name: 'signup_started', id: crypto.randomUUID() };
        document.dispatchEvent(new CustomEvent('analytics-event', { detail: discovery }));
        document.dispatchEvent(new CustomEvent('analytics-event', { detail: discovery }));
        document.dispatchEvent(new CustomEvent('analytics-event', { detail: { name: 'arbitrary', id: crypto.randomUUID() } }));
        document.dispatchEvent(new CustomEvent('analytics-event', { detail: { name: 'signup', id: 'invalid' } }));
        for (const intent of ['signup', 'login']) {
            const link = document.createElement('a');
            link.href = '/app/login';
            link.dataset.analyticsIntent = intent;
            link.dataset.analyticsPlacement = 'header';
            link.textContent = 'Same label';
            link.addEventListener('click', event => event.preventDefault());
            document.body.appendChild(link);
            link.click();
        }
        JS);

    $page->assertScript('window.analyticsCalls.map(call => call.name || "pageview")', ['pageview', 'signup_started', 'signup_cta_header', 'login_cta_header'])
        ->assertNoJavaScriptErrors();
});

it('tracks a social sign-in click on the login page', function (string $provider): void {
    config(["services.{$provider}.client_id" => "test-{$provider}-client"]);

    $page = $this->visit('/app/login');
    $page->script(analyticsBrowserTracker());
    $page->script(analyticsBrowserScript());
    $page->script(<<<JS
        const button = document.querySelector('a[href$="/auth/redirect/{$provider}"]');
        button.addEventListener('click', event => event.preventDefault());
        button.click();
        JS);

    $page->assertScript('window.analyticsCalls.map(call => call.name).filter(Boolean)', ["{$provider}_auth_started"])
        ->assertNoJavaScriptErrors();
})->with(['google', 'microsoft']);

it('tracks a refused signup on the login page', function (): void {
    $page = $this->visit('/app/login');
    $page->script(analyticsBrowserTracker());
    $page->script(analyticsBrowserScript());

    $page->type('[id="form.email"]', 'jane-refused-'.uniqid().'@gmail.com')
        ->click('button[type="submit"]')
        ->assertVisible('[id="form.password"]')
        ->assertScript(asyncAlpineComponentsLoaded())
        ->type('[id="form.password"]', 'short')
        ->click('button[type="submit"]')
        ->assertSee(__('validation.min.string', ['attribute' => 'password', 'min' => 8]));

    $page->assertScript('window.analyticsCalls.map(call => call.name).filter(Boolean)', ['signup_started', 'signup_refused_password'])
        ->assertNoJavaScriptErrors();
});

it('names the homepage capabilities signup click by its own placement', function (): void {
    $script = analyticsBrowserScript(false);
    $page = $this->visit('/');
    $page->script(analyticsBrowserTracker());
    $page->script($script);
    $page->script(<<<'JS'
        const link = document.querySelector('[data-analytics-placement="capabilities"]');
        link.addEventListener('click', event => event.preventDefault());
        link.click();
        JS);

    $page->assertScript('window.analyticsCalls.map(call => call.name).filter(Boolean)', ['signup_cta_capabilities'])
        ->assertNoJavaScriptErrors();
});

it('bounds app path metadata and strips queries and referrer details from every transport', function (): void {
    $script = analyticsBrowserScript();
    $page = $this->visit('/up');
    $page->script(analyticsBrowserTracker());
    $page->script('Object.defineProperty(document, "referrer", { value: "https://www.google.com/search?q=private-person&name=private", configurable: true });');
    $page->script($script);
    $page->script(<<<'JS'
        const paths = ['/app/login', '/app/password-reset/secret-token', '/app/email-verification/42/secret-signature', '/app/private-workspace/people/private-person/edit', '/app/private-workspace/notes/private-record', '/app/private-workspace/arbitrary/private-data'];
        for (const path of paths) {
            history.replaceState({}, '', path + '?name=private&q=private&keyword=private');
            document.dispatchEvent(new Event('livewire:navigated'));
            window.fathom.beacon({ name: 'login_completed', p: path, qs: 'private', r: 'private', payload: 'private' });
        }
        Object.defineProperty(document, 'referrer', { value: 'https://unknown.example/private?name=private' });
        window.fathom.send({ p: '/private', qs: 'private', r: 'private', unknown: 'private' });
        JS);

    $page->assertScript('window.analyticsCalls.filter(call => !call.name && !call.dp).map(call => call.p)', ['/dashboard', '/login', '/password-reset', '/email-verification', '/people/edit', '/notes/view', '/dashboard', '/dashboard'])
        ->assertScript('JSON.stringify(window.analyticsCalls).includes("private")', false)
        ->assertScript('window.analyticsCalls[0].r', 'https://www.google.com')
        ->assertScript('window.analyticsCalls.at(-1).r', '')
        ->assertScript('window.analyticsCalls.every(call => call.qs === "{}")')
        ->assertNoJavaScriptErrors();
});

it('sends bounded engagement for the page being left without raw metadata or duplicate departures', function (): void {
    $script = analyticsBrowserScript();
    $page = $this->visit('/up');
    $page->script(analyticsBrowserTracker());
    $page->script($script);
    $page->script(<<<'JS'
        const originalClock = Date.now;
        Date.now = () => originalClock() + 90000000;
        history.replaceState({}, '', '/app/private-workspace/people/private-person?name=private');
        document.dispatchEvent(new Event('livewire:navigated'));
        window.dispatchEvent(new Event('pagehide'));
        Date.now = originalClock;
        JS);

    $page->assertScript('window.analyticsCalls.filter(call => call.dp === 1).map(call => ({p: call.p, d: call.d, qs: call.qs}))', [['p' => '/dashboard', 'd' => 1800, 'qs' => '{}']])
        ->assertScript('JSON.stringify(window.analyticsCalls).includes("private")', false)
        ->assertNoJavaScriptErrors();
});

it('normalizes public pageviews without forwarding dynamic document slugs', function (): void {
    $script = analyticsBrowserScript(false);
    $page = $this->visit('/up');
    $page->script(analyticsBrowserTracker());
    $page->script($script);
    $page->script(<<<'JS'
        for (const path of ['/pricing', '/crm-for-claude', '/help/private-name/private-document', '/developers/private-document', '/compare/private-company', '/unknown-private-path']) {
            history.replaceState({}, '', path + '?q=private-query');
            document.dispatchEvent(new Event('livewire:navigated'));
        }
        JS);

    $page->assertScript('window.analyticsCalls.filter(call => !call.dp).map(call => call.p)', ['/other', '/pricing', '/crm-for-claude', '/help', '/developers', '/compare', '/other'])
        ->assertScript('JSON.stringify(window.analyticsCalls).includes("private")', false)
        ->assertNoJavaScriptErrors();
});

it('fails closed for immutable SDK transports and caps undelivered tab events', function (): void {
    $script = analyticsBrowserScript();
    $page = $this->visit('/up');
    $page->script(analyticsBrowserTracker().'Object.freeze(window.fathom);');
    $page->script($script);
    $page->script(<<<'JS'
        for (let index = 0; index < 60; index++) {
            document.dispatchEvent(new CustomEvent('analytics-event', { detail: { name: 'signup_started', id: crypto.randomUUID() } }));
        }
        JS);

    $page->assertScript('window.analyticsCalls.length', 0)
        ->assertScript('JSON.parse(sessionStorage.getItem("relaticle.analytics.pending.v1")).pending.length', 50)
        ->assertNoJavaScriptErrors();
});

it('continues best effort without browser storage and discards expired queued events', function (): void {
    $script = analyticsBrowserScript();
    $page = $this->visit('/up');
    $page->script(analyticsBrowserTracker());
    $page->script(<<<'JS'
        sessionStorage.setItem('relaticle.analytics.pending.v1', JSON.stringify({pending: [{id: crypto.randomUUID(), name: 'signup', time: Date.now() - 86400001}], sent: []}));
        const setItem = Storage.prototype.setItem;
        Storage.prototype.setItem = function (key, value) {
            if (key === 'relaticle.analytics.pending.v1') {
                throw new Error('Storage denied');
            }
            return setItem.call(this, key, value);
        };
        true;
        JS);
    $page->script($script);
    $page->script(<<<'JS'
        document.dispatchEvent(new CustomEvent('analytics-event', { detail: { name: 'signup_started', id: crypto.randomUUID() } }));
        window.dispatchEvent(new Event('online'));
        JS);

    $page->assertScript('window.analyticsCalls.map(call => call.name || "pageview")', ['pageview', 'signup_started'])
        ->assertNoJavaScriptErrors();
});

it('clears queued events and never loads the SDK when the browser opts out', function (string $signal, mixed $value): void {
    $script = analyticsBrowserScript();
    $page = $this->visit('/up');
    $page->script('sessionStorage.setItem("relaticle.analytics.pending.v1", "pending"); Object.defineProperty(navigator, '.json_encode($signal).', {value: '.json_encode($value).'});');
    $page->script($script);
    $page->script('document.dispatchEvent(new CustomEvent("analytics-event", {detail: {name: "signup_started", id: crypto.randomUUID()}}));');

    $page->assertScript('sessionStorage.getItem("relaticle.analytics.pending.v1")', null)
        ->assertScript('typeof window.relaticleAnalytics', 'undefined')
        ->assertScript('document.querySelectorAll("script[data-site]").length', 0)
        ->assertNoJavaScriptErrors();
})->with([['doNotTrack', '1'], ['globalPrivacyControl', true]]);

it('stops wrapped transports and clears the queue if a privacy signal changes after initialization', function (): void {
    $script = analyticsBrowserScript();
    $page = $this->visit('/up');
    $page->script(analyticsBrowserTracker());
    $page->script($script);
    $page->script(<<<'JS'
        Object.defineProperty(navigator, 'globalPrivacyControl', {value: true});
        window.fathom.beacon({name: 'signup'});
        document.dispatchEvent(new CustomEvent('analytics-event', {detail: {name: 'signup_started', id: crypto.randomUUID()}}));
        JS);

    $page->assertScript('window.analyticsCalls.length', 1)
        ->assertScript('sessionStorage.getItem("relaticle.analytics.pending.v1")', null)
        ->assertNoJavaScriptErrors();
});

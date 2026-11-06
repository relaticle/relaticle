<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->withVite();
});

function selfHostedInstall(mixed $page, string $expression): mixed
{
    return $page->script("(() => { const root = document.querySelector('[data-self-hosted-install]'); const install = Alpine.\$data(root); return {$expression}; })()");
}

it('generates a fresh application key and database password in the browser', function (): void {
    $page = $this->visit('/self-hosted');
    $page->wait(1);

    expect(selfHostedInstall($page, 'install.appKey'))->toMatch('/^base64:[A-Za-z0-9+\/]{43}=$/')
        ->and(selfHostedInstall($page, 'install.dbPassword'))->toMatch('/^[0-9a-f]{48}$/')
        ->and(selfHostedInstall($page, "root.innerText.includes('openssl')"))->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

it('writes the typed address into the env file and the product window', function (): void {
    $page = $this->visit('/self-hosted');
    $page->wait(1);

    $page->fill('[data-self-hosted-host]', 'https://crm.acme.io/anything');
    $page->wait(0.3);

    expect(selfHostedInstall($page, "[...root.querySelectorAll('[x-text=\"appUrlLine\"], [x-text=\"appAddress\"]')].map((line) => line.innerText)"))
        ->toBe(['APP_URL=https://crm.acme.io', 'https://crm.acme.io/app']);

    $page->fill('[data-self-hosted-host]', '');
    $page->wait(0.3);

    expect(selfHostedInstall($page, "root.querySelector('[x-text=\"appUrlLine\"]').innerText"))->toBe('APP_URL=https://crm.example.com');

    $page->assertNoJavaScriptErrors();
});

it('copies commands only, with the generated secrets and no prompt glyphs', function (): void {
    $page = $this->visit('/self-hosted');
    $page->wait(1);

    $page->fill('[data-self-hosted-host]', 'crm.acme.io');
    $page->wait(0.3);

    $script = selfHostedInstall($page, 'install.script()');
    $appKey = selfHostedInstall($page, 'install.appKey');

    expect($script)->toStartWith('curl -o compose.yml')
        ->and($script)->toContain("cat > .env << 'EOF'\nAPP_KEY={$appKey}\nDB_PASSWORD=")
        ->and($script)->toContain("APP_URL=https://crm.acme.io\nEOF\ndocker compose up -d\ndocker compose exec app php artisan make:filament-user")
        ->and($script)->not->toContain('#')
        ->and($script)->not->toMatch('/^[$>] /m');
});

it('falls back to the default address when the typed one could run a command', function (string $typed): void {
    $page = $this->visit('/self-hosted');
    $page->wait(1);

    $page->fill('[data-self-hosted-host]', $typed);
    $page->wait(0.3);

    expect(selfHostedInstall($page, "root.querySelector('[x-text=\"appUrlLine\"]').innerText"))->toBe('APP_URL=https://crm.example.com')
        ->and(selfHostedInstall($page, 'install.script()'))->not->toContain('whoami');

    $page->assertNoJavaScriptErrors();
})->with([
    'command substitution' => '$(whoami)',
    'backticks' => '`whoami`',
    'a space and a command' => 'crm.acme.io whoami',
    'a quote' => "crm.acme.io'whoami",
]);

it('keeps a hostname with a port', function (): void {
    $page = $this->visit('/self-hosted');
    $page->wait(1);

    $page->fill('[data-self-hosted-host]', 'crm.acme.io:8080');
    $page->wait(0.3);

    expect(selfHostedInstall($page, "root.querySelector('[x-text=\"appUrlLine\"]').innerText"))->toBe('APP_URL=https://crm.acme.io:8080');
});

it('copies a heredoc with a quoted delimiter so the shell expands nothing', function (): void {
    $page = $this->visit('/self-hosted');
    $page->wait(1);

    expect(selfHostedInstall($page, 'install.script()'))->toContain("cat > .env << 'EOF'\n")
        ->and(selfHostedInstall($page, "root.querySelector('[x-text=\"heredocLine\"]').innerText"))->toBe("cat > .env << 'EOF'");
});

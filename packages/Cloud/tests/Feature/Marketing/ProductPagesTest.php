<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use App\Features\Documentation;
use App\Features\EmailIntegration;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Cloud\CloudServiceProvider;

mutates(CloudServiceProvider::class);

it('renders the Rela page with verified capability claims', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(config('chat.assistant_name'))
        ->and($html)->toContain(__(':name never writes without your approval', ['name' => config('chat.assistant_name')]))
        ->and($html)->toContain(__('MCP server'))
        ->and($html)->toContain('"FAQPage"')
        ->and($html)->toContain('"BreadcrumbList"');
});

it('does not claim hosted models ship with self-hosted installs', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('bring your own API key'));
});

it('describes the Cloud Pro trial when billing is on', function (): void {
    withHostedBilling();

    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('Is Rela included with Relaticle Cloud?'))
        ->and($html)->toContain('14-day Cloud Pro trial')
        ->and($html)->toContain('2,000-credit monthly allowance')
        ->and($html)->toContain('Self-hosted installs do not meter credits')
        ->and($html)->not->toContain('300-credit monthly allowance')
        ->and($html)->not->toContain('every workspace, free or paid');
});

it('says AI credits are not metered when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('Is Rela included in the free plan?'))
        ->and($html)->toContain('Rela does not meter AI credits on this installation')
        ->and($html)->not->toContain('300-credit monthly allowance');
});

it('offers every model without a plan gate when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('Every model this installation offers is open to every workspace.'))
        ->not->toContain('on a paid plan')
        ->not->toContain('A paid plan additionally unlocks');
});

it('describes batch approval as leaving out records, matching how the service resolves batches', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('Leave out any record before you approve'))
        ->and($html)->not->toContain('all-or-nothing')
        ->and($html)->not->toContain('no partial approval');
});

it('names the assistant from config rather than a hardcoded literal', function (): void {
    config()->set('chat.assistant_name', 'Testbot');

    $this->get('/ai')->assertOk()->assertSee('Testbot');
});

it('renders the ask playground at the #demo anchor the hero points to', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain('id="demo"')
        ->and($html)->toContain(__('Update three opportunities'))
        ->and($html)->toContain(__('Add a person'))
        ->and($html)->toContain(__('Find overdue tasks'));

    expect($html)->toContain(__('Move'))
        ->and($html)->toContain(__('Create person'))
        ->and($html)->toContain(__('Approve :count changes', ['count' => 3]))
        ->and($html)->toContain(__('Approve or discard the proposal to keep chatting'))
        ->and($html)->toContain(__('Discard'))
        ->and($html)->not->toContain('bg-amber-500 text-white');
});

it('quotes a line the assistant instructions really contain', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain('Every write needs the user&#039;s approval.')
        ->and((string) resolve(CrmAssistant::class)->instructions())->toContain("Every write needs the user's approval.");
});

it('shows a plan, a docs answer and an email proposal as product fragments', function (): void {
    Feature::activate([Documentation::class, EmailIntegration::class]);

    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('Hover a line to leave it out'))
        ->and($html)->not->toContain(__('Approved together, in order'))
        ->and($html)->toContain(__('Approve :count changes', ['count' => 3]))
        ->and($html)->toContain(__('Up to :steps linked steps in one plan', ['steps' => config('chat.max_plan_steps')]))
        ->and($html)->toContain(route('help.show', ['category' => 'ai-assistant', 'slug' => 'connect-claude-or-chatgpt']))
        ->and($html)->toContain(__('Own approval'))
        ->and($html)->toContain(__('Approve all never sends an email'));
});

it('leaves the email showcase out when email integration is off', function (): void {
    Feature::deactivate(EmailIntegration::class);

    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->not->toContain('data-ai-email')
        ->and($html)->not->toContain(__('Send Email'))
        ->and($html)->not->toContain(__('It also proposes emails, and each one waits for its own Send.'));
});

it('lists no limit that is really a capability', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('Deleting a record never removes the records linked to it. Ask for each one by name.'))
        ->and($html)->not->toContain('It proposes each email on a card')
        ->and($html)->not->toContain('cannot import a file');
});

it('says Rela searches the web only after the user allows it', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('It searches the web only after you allow it.'))
        ->and($html)->toContain(__('and, when you allow it, the web'))
        ->and($html)->not->toContain('cannot open a web page')
        ->and($html)->not->toContain('Reading never needs your approval');
});

it('says Rela takes an attached file and hands a bigger CSV to the import wizard', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('Can :name import a file?', ['name' => config('chat.assistant_name')]))
        ->and($html)->toContain('CSV, TXT or MD file of up to 10 MB')
        ->and($html)->toContain('up to 25 at a time');
});

it('builds its own walkthrough instead of replaying the homepage mockup', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->not->toContain('heroChat()')
        ->and($html)->not->toContain('hero-chat-animate');
});

it('renders the self-hosted page with the real quick start', function (): void {
    $html = $this->get('/self-hosted')->assertOk()->getContent();

    expect($html)->toContain('docker compose up -d')
        ->and($html)->toContain('AGPL')
        ->and($html)->toContain(__('Ollama'))
        ->and($html)->toContain('"FAQPage"');
});

it('tells self-hosters their provider key is not metered', function (): void {
    $this->get('/self-hosted')
        ->assertOk()
        ->assertSee('Relaticle does not meter AI credits on a self-hosted install.')
        ->assertDontSee('still meters');
});

it('links the full self-hosting guide', function (): void {
    $this->get('/self-hosted')->assertOk()->assertSee('/developers/self-hosting');
});

it('offers a copy control whose payload is runnable shell without prompt glyphs', function (): void {
    $html = $this->get('/self-hosted')->assertOk()->getContent();

    expect($html)->toContain(__('Copy'))
        ->and($html)->toContain('navigator.clipboard.writeText')
        ->and($html)->toContain('select-none');
});

it('keeps the comment marker selectable so a select-and-copy stays runnable shell', function (): void {
    $html = $this->get('/self-hosted')->assertOk()->getContent();

    expect($html)->toContain('<span>#</span>')
        ->and($html)->not->toContain('<span class="select-none" aria-hidden="true">#</span>');
});

it('renders the install with shell-generated secrets before the browser generates its own', function (): void {
    $html = $this->get('/self-hosted')->assertOk()->getContent();

    expect($html)->toContain('APP_KEY=base64:$(openssl rand -base64 32)')
        ->and($html)->toContain('DB_PASSWORD=$(openssl rand -hex 24)')
        ->and($html)->toContain('APP_URL=https://crm.example.com')
        ->and($html)->toContain('docker compose exec app php artisan make:filament-user')
        ->and($html)->toContain('data-self-hosted-host')
        ->and($html)->toContain('https://crm.example.com/app');
});

it('tells self-hosters the stack serves plain HTTP and links the reverse proxy guide', function (): void {
    $html = $this->get('/self-hosted')->assertOk()->getContent();

    expect($html)->toContain('data-self-hosted-tls')
        ->and($html)->toContain('The stack serves HTTP on port 80. For HTTPS, put a TLS reverse proxy in front.')
        ->and($html)->toContain('/developers/self-hosting#reverse-proxy-and-ssl');
});

it('names the six containers the compose file starts', function (): void {
    $html = $this->get('/self-hosted')->assertOk()->getContent();

    foreach (['app', 'horizon', 'scheduler', 'reverb', 'postgres', 'redis'] as $container) {
        expect($html)->toContain('<h3 class="font-mono text-sm font-semibold text-gray-950 dark:text-white">'.$container.'</h3>');
    }

    expect($html)->toContain('postgres:17-alpine')
        ->and($html)->toContain('redis:7-alpine');
});

it('ends the self-hosted page on the closing band with a cloud signup', function (): void {
    $html = $this->get('/self-hosted')->assertOk()->getContent();

    expect($html)->toContain('Run it your way.')
        ->and($html)->toContain('Try Relaticle Cloud')
        ->and($html)->toContain('Deploy self-hosted');
});

it('gives each product page its own social card instead of the sitewide one', function (string $path, string $image): void {
    $html = $this->get($path)->assertOk()->getContent();

    expect($html)->toContain('content="'.url("/images/{$image}").'?v=1"')
        ->and($html)->not->toContain('/images/open-graph.jpg');

    expect(public_path("images/{$image}"))->toBeReadableFile();
})->with([
    ['/ai', 'open-graph-ai.jpg'],
    ['/self-hosted', 'open-graph-self-hosted.jpg'],
]);

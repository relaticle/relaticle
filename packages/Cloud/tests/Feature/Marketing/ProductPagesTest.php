<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use App\Features\Documentation;
use App\Features\EmailIntegration;
use Laravel\Pennant\Feature;
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

it('describes batch approval as per record, matching how the service resolves batches', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('Batches are reviewed record by record'))
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
        ->and($html)->toContain(__('Try an ask'))
        ->and($html)->toContain(__('Update three deals'))
        ->and($html)->toContain(__('Add a person'))
        ->and($html)->toContain(__('Find overdue tasks'));

    expect($html)->toContain(__('Update Opportunity'))
        ->and($html)->toContain(__('Create Person'))
        ->and($html)->toContain(__('Save changes'))
        ->and($html)->toContain(__('Discard'))
        ->and($html)->not->toContain('bg-amber-500 text-white');
});

it('shows a plan, a docs answer and an email proposal as product fragments', function (): void {
    Feature::activate([Documentation::class, EmailIntegration::class]);

    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('Approved together, in order'))
        ->and($html)->toContain(__('Runs after step :step', ['step' => 1]))
        ->and($html)->toContain(__('Kovra Systems (step 1)'))
        ->and($html)->toContain(__('Up to :steps linked steps in one plan', ['steps' => config('chat.max_plan_steps')]))
        ->and($html)->toContain(route('help.show', ['category' => 'ai-assistant', 'slug' => 'connect-claude-or-chatgpt']))
        ->and($html)->toContain(__('Send Email'))
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

    // The `$`/`>` prompts are select-none because they are not part of the command.
    // The `#` is the opposite: drop it from the selection and the comment text
    // pastes into a shell as a bare command.
    expect($html)->toContain('<span>#</span>')
        ->and($html)->not->toContain('<span class="select-none" aria-hidden="true">#</span>');
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

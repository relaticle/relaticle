<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Models\Workspace;
use App\Support\CompetitorFacts;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Services\ModelRegistry;
use Symfony\Component\DomCrawler\Crawler;

mutates(Plan::class, ModelRegistry::class, CompetitorFacts::class);

it('shows the legacy two-card page when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('No per-seat pricing. Ever.')
        ->assertDontSee('Cloud Pro');
});

it('shows the pro tier when billing is on', function (): void {
    withHostedBilling();

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('Pay per workspace. Never per seat.')
        ->assertSee('$19')
        ->assertSee('$24')
        ->assertSee('$228 billed yearly')
        ->assertSee('Save 21%')
        ->assertSee('2,000 AI credits')
        ->assertSee('Cloud Pro')
        ->assertSee('Prefer to self-host? It’s free.')
        ->assertSee('Start for free')
        ->assertSee('14-day trial. No card required.')
        ->assertDontSee('One workspace price as your workspace grows')
        ->assertDontSee('300 AI credits')
        ->assertDontSee('Generous free tier');
});

it('keeps self-hosting separate from the three paid plan choices', function (): void {
    withHostedBilling();

    $response = $this->get('/pricing');
    $crawler = new Crawler((string) $response->getContent());

    expect($crawler->filter('#pricing-plans h2')->each(fn (Crawler $heading): string => trim($heading->text())))
        ->toBe(['Cloud Pro', 'Cloud Concierge', 'Enterprise'])
        ->and($crawler->filter('#self-hosting a')->attr('href'))->toBe(route('selfHosted'));
});

it('closes with a trial start and a sales contact when billing is on', function (): void {
    withHostedBilling();

    $response = $this->get('/pricing')->assertOk();
    $crawler = new Crawler((string) $response->getContent());
    $cta = $crawler->filter('#pricing-cta');

    expect($cta->filter('h2')->text())->toBe(__('Start with a 14-day trial'))
        ->and($cta->filter('a[href="'.route('login').'"]')->text())->toContain(__('Start for free'))
        ->and($cta->filter('a[href="'.route('contact').'"]')->text())->toContain(__('Talk to us'))
        ->and($crawler->filter('#pricing-plans #self-hosting'))->toHaveCount(0)
        ->and($crawler->filter('#self-hosting a[href="'.route('selfHosted').'"]'))->toHaveCount(1);
});

it('closes with a free start and a self-hosting link when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $response = $this->get('/pricing')->assertOk();
    $cta = (new Crawler((string) $response->getContent()))->filter('#pricing-cta');

    expect($cta->filter('a[href="'.route('login').'"]')->text())->toContain(__('Start for free'))
        ->and($cta->filter('a[href="'.route('selfHosted').'"]')->text())->toContain(__('Explore self-hosting'))
        ->and(trim($cta->filter('a[href="'.route('contact').'"]')->text()))->toBe(__('Questions? Talk to us.'));
});

it('answers the trial question right after the hosted plan question when billing is on', function (): void {
    withHostedBilling();

    $response = $this->get('/pricing')->assertOk();
    $questions = (new Crawler((string) $response->getContent()))
        ->filter('#pricing-faq button')
        ->each(fn (Crawler $button): string => trim($button->text()));

    $hostedIndex = array_search(__("What's included in the hosted plan?"), $questions, true);

    expect($hostedIndex)->not->toBeFalse()
        ->and($questions[$hostedIndex + 1])->toBe(__('What happens after my trial ends?'));
});

it('links to the AI assistant and MCP server page', function (): void {
    $response = $this->get('/pricing')->assertOk();
    $crawler = new Crawler((string) $response->getContent());
    $toolLink = $crawler->filter('main a[href="'.route('ai').'"]')->text();

    expect($toolLink)->toContain('the MCP server');
});

it('emits product json-ld on the pricing page', function (): void {
    $this->get('/pricing')
        ->assertOk()
        ->assertSee('application/ld+json', false)
        ->assertSee('"@type":"Product"', false)
        ->assertSee('"brand":{"@type":"Brand","name":"Relaticle"}', false)
        ->assertSee('"availability":"https://schema.org/InStock"', false);
});

it('lists three existing aspect-ratio images in the product json-ld', function (): void {
    $html = (string) $this->get('/pricing')->assertOk()->getContent();

    preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $matches);

    $graph = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    $product = collect($graph['@graph'])->firstWhere('@type', 'Product');

    expect($product['image'])->toHaveCount(3);

    foreach ($product['image'] as $url) {
        $path = public_path((string) parse_url((string) $url, PHP_URL_PATH));

        expect(file_exists($path))->toBeTrue("Missing product image on disk: {$url}");
    }
});

it('answers common pricing questions on the page', function (): void {
    $this->get('/pricing')
        ->assertSee(__('Is Relaticle really free to self-host?'))
        ->assertSee(__('Do you charge per seat?'));
});

it('scopes the hosted-plan faq to the pro tier when billing is on', function (): void {
    withHostedBilling();

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__("What's included in the hosted plan?"))
        ->assertSee(__('What happens after my trial ends?'))
        ->assertSee('2,000-credit')
        ->assertDontSee('The hosted Cloud plan is $0/mo and includes unlimited users and data');
});

it('scopes the hosted-plan faq to the free tier when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__("What's included in the hosted plan?"))
        ->assertDontSee(__('What happens after my trial ends?'))
        ->assertDontSee('2,000-credit');
});

it('compares hosted product access with scoped Enterprise services when billing is on', function (): void {
    withHostedBilling();

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('Choose how you get started'))
        ->assertSee('$19/mo per workspace ($228 billed yearly, or $24/mo billed monthly)')
        ->assertSee(__('Scoped implementation project'))
        ->assertDontSee(__('$0/mo per workspace'))
        ->assertDontSee(__('Zero-downtime updates and automatic daily backups, handled for you'));
});

it('shows the comparison list with the free-tier hosted price and updates when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('Self-hosted or hosted: how to choose'))
        ->assertSee(__('$0/mo per workspace'))
        ->assertSee(__('Zero-downtime updates and automatic daily backups, handled for you'))
        ->assertDontSee('$19/mo per workspace ($228 billed yearly, or $24/mo billed monthly)');
});

it('emits accurate product json-ld offers when billing is on', function (): void {
    withHostedBilling();

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('"name":"Self-hosted","price":"0"', false)
        ->assertSee('"name":"Cloud Pro","price":"19"', false)
        ->assertDontSee('"name":"Cloud","price":"0"', false);
});

it('keeps the Enterprise starting price consistent with its annual structured offer', function (): void {
    withHostedBilling();
    config(['relaticle.enterprise.starting_price_yearly' => 25_000]);

    $response = $this->get('/pricing')->assertSee('From $25,000 / year');
    $crawler = new Crawler((string) $response->getContent());
    $graph = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, 512, JSON_THROW_ON_ERROR);
    $product = collect($graph['@graph'])->firstWhere('@type', 'Product');
    $offer = collect($product['offers'])->firstWhere('name', 'Enterprise');

    expect($offer)->not->toBeNull()
        ->and($offer['priceSpecification']['minPrice'])->toBe(25_000)
        ->and($offer['priceSpecification']['unitText'])->toBe('year')
        ->and($offer['url'])->toBe(route('contact', ['plan' => 'enterprise']));
});

it('names Cloud Concierge in the meta description when billing is on', function (): void {
    withHostedBilling();

    $response = $this->get('/pricing')->assertOk();
    $description = (new Crawler((string) $response->getContent()))
        ->filter('meta[name="description"]')
        ->attr('content');

    expect($description)->toContain('Cloud Concierge')
        ->toContain('$708/year');
});

it('sells Cloud Concierge through a sales inquiry at its yearly price', function (): void {
    withHostedBilling();
    config(['relaticle.concierge.price_yearly' => 1_200]);

    $response = $this->get('/pricing')->assertOk();
    $crawler = new Crawler((string) $response->getContent());
    $card = $crawler->filter('#concierge-plan');

    expect($card->filter('a')->attr('href'))->toBe(route('contact', ['plan' => 'concierge']))
        ->and($card->text())->toContain('$100')
        ->toContain('$1,200 billed yearly')
        ->toContain(number_format(Plan::Concierge->credits()).' AI credits / month')
        ->toContain('Setup starts once the invoice is paid.');

    $graph = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, 512, JSON_THROW_ON_ERROR);
    $offer = collect(collect($graph['@graph'])->firstWhere('@type', 'Product')['offers'])->firstWhere('name', 'Cloud Concierge');

    expect($offer['priceSpecification']['price'])->toBe(1_200)
        ->and($offer['priceSpecification']['unitText'])->toBe('year')
        ->and($offer['url'])->toBe(route('contact', ['plan' => 'concierge']));
});

it('emits accurate product json-ld offers when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('"name":"Self-hosted","price":"0"', false)
        ->assertSee('"name":"Cloud","price":"0"', false)
        ->assertDontSee('"name":"Cloud Pro"', false);
});

it('discloses the real per-model credit multiplier instead of a flat allowance claim', function (): void {
    withHostedBilling();

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('What counts as an AI credit?'))
        ->assertSee('3x for Opus 5', false)
        ->assertSee('0.5 credits for every tool call')
        ->assertDontSee('One credit is used each time the built-in AI assistant sends a chat reply or generates a record summary.');
});

it('does not contradict the credit-multiplier faq with a flat-allowance claim on the billing-on plan card', function (): void {
    withHostedBilling();

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('What counts as an AI credit?'))
        ->assertDontSee('1 credit ≈ one AI chat message or record summary')
        ->assertDontSee('All AI models, including premium');
});

it('tells self-hosters their installs do not meter AI credits and keeps the Cloud trial facts', function (): void {
    withHostedBilling();

    $html = $this->get('/pricing')->assertOk()->getContent();
    $question = e(__('Are self-hosted installs exempt from AI credit limits?'));
    $windows = collect(explode($question, $html))->skip(1)->map(fn (string $after): string => mb_substr($after, 0, 3000));

    expect($windows)->not->toBeEmpty()
        ->and($windows->contains(fn (string $answer): bool => str_contains($answer, 'Self-hosted installs do not meter AI credits')
            && str_contains($answer, number_format(Plan::Pro->credits()))
            && str_contains($answer, Workspace::PRO_TRIAL_DAYS.'-day')))->toBeTrue('the FAQ answer must say self-hosted is unmetered and keep the Pro credits and trial length')
        ->and($html)->not->toContain('does not disable credit metering')
        ->and($html)->not->toContain('brand-new Cloud signup gets exactly the same one')
        ->and($html)->not->toContain("\u{2014}");
});

it('keeps the Cloud Pro trial out of the self-hosted credit answer when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('Are self-hosted installs exempt from AI credit limits?'))
        ->assertSee('Self-hosted installs do not meter AI credits')
        ->assertDontSee('Cloud Pro trial');
});

it('says AI credits are not metered in the billing-off plan-limit answer', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('does not meter credits on a self-hosted install')
        ->assertDontSee("Free plan's 300 credits a month")
        ->assertDontSee('That includes self-hosted installs');
});

it('offers a prepaid credit top-up instead of a hard stop in the billing-on plan-limit answer', function (): void {
    withHostedBilling();

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('buy a prepaid credit top-up instead of waiting');
});

it('never names a model the app cannot actually serve', function (): void {
    // Gemini 3 Flash and Gemini 3.1 Pro carry supports_tools => false in chat.php, so
    // ModelDescriptor::isAvailable() always returns false for them and they can never
    // be picked, so the page must never claim a plan "unlocks" or is priced for them.
    withHostedBilling();

    $this->get('/pricing')
        ->assertOk()
        ->assertDontSee('Gemini')
        ->assertSee(__('Which AI models does my plan unlock?'))
        ->assertSee('Sonnet 5 and self-hosted models')
        ->assertSee('GPT 5.5, Opus 5 and GPT 5.4');
});

it('pins the exact membership of every model list derived from the chat catalog', function (): void {
    // $freeCloudModels and $multiplierOneHalfModels (pricing.blade.php) are not covered by
    // any other assertion in this file. A config edit that emptied either one (e.g. Sonnet's
    // min_plan changing, or the GPT rows' credit_multiplier changing) would still pass every
    // other test here, since those only assert substrings that come from the OTHER derived
    // lists ($paidCloudModels / $multiplierOneModels). Each line below anchors one full
    // sentence fragment, so an empty/degraded list breaks the exact assertion for that list.
    withHostedBilling();

    $this->get('/pricing')
        ->assertOk()
        // $freeCloudModels, via $modelsUnlockAnswer
        ->assertSee('Every plan can use Sonnet 5 and any self-hosted model you connect yourself.')
        // $paidCloudModels, via $modelsUnlockAnswer
        ->assertSee('Cloud Pro additionally unlocks the higher-multiplier models: GPT 5.5, Opus 5 and GPT 5.4.')
        // $multiplierOneModels, $multiplierOneHalfModels, $multiplierThreeModels, via $creditFaqAnswer
        ->assertSee('1x for Sonnet 5 and self-hosted models; 1.5x for GPT 5.5 and GPT 5.4; 3x for Opus 5)', false);
});

/**
 * What a plan includes is not a function of whether the web host currently holds an
 * Anthropic key. Reading these lists through ModelRegistry::available() made a key
 * rotation render "Every plan can use  and any self-hosted model you connect
 * yourself", a public sentence with a hole in it, invisible to the rest of this
 * suite because phpunit.xml sets a fake key for every provider.
 */
it('names the models a plan includes even when this install holds no provider key', function (): void {
    withHostedBilling();
    config(['ai.providers.anthropic.key' => null, 'ai.providers.openai.key' => null]);
    app()->forgetInstance(ModelRegistry::class);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('Every plan can use Sonnet 5 and any self-hosted model you connect yourself.')
        ->assertSee('Cloud Pro additionally unlocks the higher-multiplier models: GPT 5.5, Opus 5 and GPT 5.4', false)
        ->assertSee('3x for Opus 5', false)
        ->assertDontSee('Gemini');
});

it('offers Enterprise through a scoped sales inquiry with an annual starting price', function (): void {
    withHostedBilling();

    $response = $this->get('/pricing')
        ->assertSee('From $20,000 / year')
        ->assertSee('Scope and price agreed before you sign.')
        ->assertSee('What does Enterprise include?');

    $card = (new Crawler((string) $response->getContent()))->filter('#enterprise-plan');

    expect($card->filter('a')->attr('href'))->toBe(route('contact', ['plan' => 'enterprise']))
        ->and($card->text())->toContain('Implementation scoped to your workflow');
});

it('does not claim an unconfirmed Enterprise tier is a purchasable offering when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertDontSee('Enterprise');
});

it('describes the chat rate limit as per workspace, not per person', function (): void {
    withHostedBilling();

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('Is there a message rate limit?'))
        ->assertSee('shared across the whole workspace, not per person');
});

it('does not tell self-hosters a plan or a sysadmin action controls their AI credits', function (): void {
    $this->get('/pricing')
        ->assertOk()
        ->assertSee('Only a per-minute send limit applies')
        ->assertDontSee('no plan removes metering entirely')
        ->assertDontSee('reset its credit period from the sysadmin panel')
        ->assertDontSee('raising or removing that cap is a matter of updating your own workspace\'s plan; there is no separate self-hosted billing UI for it.');
});

/**
 * The catalog is editable at runtime now, so the marketing copy has to survive a
 * catalog these sentences were not written against. Asking for the 1.0 / 1.5 / 3.0
 * buckets by name printed "3x for )" the moment an operator retired the only 3x
 * model, and left a model priced at any other multiplier out of the sentence
 * entirely.
 *
 * @param  list<array{model:string,label:string,min_plan:string,credit_multiplier:float}>  $models
 */
function catalogOf(array $models): void
{
    config(['chat.models' => array_map(fn (array $model): array => [
        'label' => $model['label'],
        'provider' => 'anthropic',
        'model' => $model['model'],
        'min_plan' => $model['min_plan'],
        'credit_multiplier' => $model['credit_multiplier'],
        'input_per_mtok' => 1.0,
        'output_per_mtok' => 2.0,
        'auto' => true,
        'enabled' => true,
        'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'],
        'verified_at' => null,
    ], $models)]);

    app()->forgetInstance(ModelRegistry::class);
}

/** The shapes an empty interpolation leaves behind. */
function assertNoCopyHoles(string $html): void
{
    expect($html)
        ->not->toMatch('/\dx for\s*[;)]/')
        ->not->toMatch('/can use\s+and\b/')
        ->not->toMatch('/higher-multiplier models:\s*\./')
        ->not->toMatch('/from\s+up to\b/')
        ->not->toMatch('/simple\s+replies\b/')
        ->not->toMatch('/^\s*is free on every plan/m');
}

it('names every offered model in exactly one multiplier clause', function (): void {
    withHostedBilling();

    catalogOf([
        ['model' => 'a-1', 'label' => 'Alpha One', 'min_plan' => 'free', 'credit_multiplier' => 1.0],
        ['model' => 'b-2', 'label' => 'Beta Two', 'min_plan' => 'pro', 'credit_multiplier' => 2.0],
        ['model' => 'c-7', 'label' => 'Gamma Seven', 'min_plan' => 'pro', 'credit_multiplier' => 7.5],
    ]);

    $html = (string) $this->get('/pricing')->assertOk()->getContent();

    assertNoCopyHoles($html);

    // 2x and 7.5x are multipliers the old three-bucket copy could not express at all.
    expect($html)->toContain('1x for Alpha One and self-hosted models')
        ->toContain('2x for Beta Two')
        ->toContain('7.5x for Gamma Seven');
});

it('leaves no hole when no model carries a given multiplier', function (): void {
    withHostedBilling();

    catalogOf([
        ['model' => 'a-1', 'label' => 'Alpha One', 'min_plan' => 'free', 'credit_multiplier' => 1.0],
    ]);

    $html = (string) $this->get('/pricing')->assertOk()->getContent();

    assertNoCopyHoles($html);

    expect($html)->not->toContain('3x for');
});

it('leaves no hole when the catalog offers nothing on the free plan', function (): void {
    withHostedBilling();

    catalogOf([
        ['model' => 'b-2', 'label' => 'Beta Two', 'min_plan' => 'pro', 'credit_multiplier' => 2.0],
    ]);

    $html = (string) $this->get('/pricing')->assertOk()->getContent();

    assertNoCopyHoles($html);

    expect($html)->toContain('Every plan can use any self-hosted model you connect yourself.');
});

it('leaves no hole when the catalog offers nothing on a paid plan', function (): void {
    withHostedBilling();

    catalogOf([
        ['model' => 'a-1', 'label' => 'Alpha One', 'min_plan' => 'free', 'credit_multiplier' => 1.0],
    ]);

    $html = (string) $this->get('/pricing')->assertOk()->getContent();

    assertNoCopyHoles($html);

    expect($html)->not->toContain('additionally unlocks');
});

it('keeps the shipped seed catalog free of copy holes on both public pages', function (): void {
    withHostedBilling();

    config(['chat.models' => (require base_path('packages/Chat/config/chat.php'))['models']]);
    app()->forgetInstance(ModelRegistry::class);

    assertNoCopyHoles((string) $this->get('/pricing')->assertOk()->getContent());
    assertNoCopyHoles((string) $this->get('/ai')->assertOk()->getContent());
});

it('keeps the plans help article in step with the pricing page', function (): void {
    withHostedBilling();

    $pricing = $this->get('/pricing')->assertOk()->getContent();
    $article = $this->get(route('help.show', ['category' => 'workspace', 'slug' => 'billing-and-plans']))->assertOk()->getContent();

    $facts = [
        '$19/mo',
        '$24/mo',
        '$228',
        Workspace::PRO_TRIAL_DAYS.'-day',
        number_format(Plan::Pro->credits()),
        '$'.number_format(config('relaticle.enterprise.starting_price_yearly')),
    ];

    foreach ($facts as $fact) {
        expect($pricing)->toContain($fact)
            ->and($article)->toContain($fact);
    }
});

it('keeps the credits help article in step with each plan allowance', function (): void {
    $article = new Crawler((string) $this->get(route('help.show', ['category' => 'ai-assistant', 'slug' => 'ai-credits-and-limits']))->assertOk()->getContent());

    $rows = collect($article->filter('table tbody tr')->each(fn (Crawler $row): array => $row->filter('td')->each(fn (Crawler $cell): string => $cell->text())))
        ->keyBy(fn (array $cells): string => $cells[0]);

    expect($rows->all())->toBe([
        'Cloud Pro and its trial' => ['Cloud Pro and its trial', number_format(Plan::Pro->credits()), (string) Plan::Pro->rateLimit()],
        'Cloud Concierge' => ['Cloud Concierge', number_format(Plan::Concierge->credits()), (string) Plan::Concierge->rateLimit()],
        'Enterprise' => ['Enterprise', 'Agreed with your team', 'Agreed with your team'],
        'Self-hosted' => ['Self-hosted', 'Not metered', config('chat.rate_limit_per_minute').', set by CHAT_RATE_LIMIT_PER_MINUTE (0 for no limit)'],
    ]);
});

it('leaves the cloud metering questions off a self-hosted pricing page', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('Are self-hosted installs exempt from AI credit limits?'))
        ->assertDontSee(__('What counts as an AI credit?'))
        ->assertDontSee(__('Which AI models does my plan unlock?'))
        ->assertDontSee(__('Is there a message rate limit?'));
});

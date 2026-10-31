# Email Conversations, part A: foundations (PR 1, PR 3, PR 10)

> **For agentic workers:** REQUIRED SUB-SKILL: use the `sdd-lean` skill to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the reader and Outbox polish bugs, make teammates see another mailbox's mail only where it is linked to a record, and give every mailbox a stored health state that the owner and admins are told about.

**Architecture:** PR 1 changes views, two Livewire components and one enum. PR 3 adds one condition to the teammate default branch of `VisibleEmailScope` and its in-memory twin `PrivacyService::effectiveTier()`, and stops a raised workspace default from widening past mail. PR 10 adds four `connected_accounts` columns, the `MailboxHealth` enum, a `RecordMailboxHealth` action, the `email:check-mailbox-health` command, a notification, and a banner plus nav dot.

**Tech stack:** as the master plan. No new dependency.

**Spec:** `docs/superpowers/specs/2026-10-31-email-conversations-design.md`, sections "UI" (message body rendering, reply subject), "Visibility rules", "Sync health (D26)", "Things this spec removes", "Phases and pull requests".
**Master plan:** `docs/superpowers/plans/2026-10-31-email-conversations.md`. Its global constraints apply and are not repeated here.

All three PRs branch from `main` and start at once. None depends on another part.

## Plan notes: where the code differs from the spec

1. **The snippet leak at `email-view.blade.php:138` cannot be reached today.** `BaseEmailsRelationManager::selectedEmail()` returns null below `viewBody` (`BaseEmailsRelationManager.php:190`), so the reader never renders for a subject-tier viewer. The leak goes live when PR 6 opens the reader at the gate. PR 1 deletes the line anyway, because PR 6 depends on it being gone.
2. **Plain-text emails already render in the app theme.** When `body_html` is empty, the reader prints `body_text` in a themed `<pre>` (`email-view.blade.php:425-431`). The white block in dark mode is the iframe path. Its dark rules sit inside `@media (prefers-color-scheme: dark)` (`EmailHtmlSanitizer.php:123-133`), which follows the OS rather than Filament's `dark` class on `<html>`. PR 1 replaces the media query with a `data-theme` attribute that the reader copies from the page. It adds a regression test for the text-only path and does not change that path.
3. **The badge's colours lose to the enum.** `EmailCategory::getColor()` (`EmailCategory.php:34-44`) makes Invoice `success` (green) and Sales `danger` (red). The badge's private map made Invoice red and Support green (`category-badge.blade.php:4-13`). Following "one fact, one owner", the enum wins, so Sales turns red. If the founder does not want that, change the enum, not the badge.
4. **The no-provider message (D27) is deferred.** No help or docs page explains mailbox OAuth setup. `self-hosting.md:136` covers `GOOGLE_CLIENT_ID` for social login only. PR 1 moves the card above the tabs and keeps its copy.
5. **Linked-only applies to the default branch only.** The spec says `whereTeammateMaySeeMetadata()` gains the `emailables` exists. That method also holds the viewer's own synced copy, the direct share and the cross-message share branches (`VisibleEmailScope.php:90-105`). Gating those would hide mail an owner shared on purpose, which D23 names as the widening path. The condition goes on the by-default branch (`VisibleEmailScope.php:106-120`) and on the matching branch of `PrivacyService::effectiveTier()` (`PrivacyService.php:58-65`).
6. **PR 3 also carries "raising the default never widens past mail".** The spec's phases table puts it in PR 3. The brief listed only the linked-only rule. The inbox notice to mailboxes that follow the default (D23) stays in PR 13, together with the rest of the sharing copy.
7. **Health is judged on `last_synced_at`, not on the cursor value.** The spec says the cursor is the signal. D26 proposes a new `last_cursor_advanced_at` column, which the contracts table does not list. A quiet mailbox keeps the same cursor, so judging on its value would flag healthy inboxes. The real defect is that `last_synced_at` moves on a failed batch (`IncrementalEmailSyncJob.php:174-183`). Once PR 10 fixes that, `last_synced_at` means "last successful delta, even an empty one", which is D26's definition of progress. No extra column is needed.
8. **SystemAdmin's stale threshold moves from 60 minutes to 24 hours.** `ConnectedAccount::stale()` uses `STALE_AFTER_MINUTES = 60` (`ConnectedAccount.php:105, 209-218`). Per the spec, it now reads `health = stale`, which the watchdog sets after 24 hours. The `MailboxHealth` contract has no Delayed case (D26's 1-hour state), so staff stop seeing one-hour delays.
9. **The admin role is a capability.** "Workspace admins" means members holding `WorkspaceCapability::EmailManage` (`WorkspaceRole.php:43-56`), checked with `User::hasWorkspaceCapability()` (`User.php:523`). The workspace owner always holds it.
10. **The schedule test lives outside `tests/Feature/EmailIntegration`.** It is `tests/Feature/Commands/EmailIntegrationScheduleTest.php`. PR 10 extends it there.
11. **Outbox line numbers drifted.** The findings cite `OutboxTable.php:198-204` for the options. The status filter is at `OutboxTable.php:180-201` and the tab rules at `207-224`.

---

# PR 1: polish

**Branch:** `feat/email-polish`
**Goal:** Close findings 1, 5, 6, 7, 10, 11, 12, 13, 18, 19 and 21 with no data change.

## File map

| File | Change | Owns |
|---|---|---|
| `packages/EmailIntegration/src/Livewire/EmailComposer.php` | modify | reply and forward subject prefix |
| `packages/EmailIntegration/resources/views/components/email-view.blade.php` | modify | drop the snippet line, theme the frame |
| `packages/EmailIntegration/src/Support/EmailHtmlSanitizer.php` | modify | base stylesheet and `data-theme` rules |
| `packages/EmailIntegration/resources/views/components/category-badge.blade.php` | modify | reads `EmailCategory` |
| `packages/EmailIntegration/resources/views/components/list-row-wide.blade.php` | modify | passes the enum to the badge |
| `packages/EmailIntegration/src/Enums/EmailCategory.php` | modify | translated labels |
| `packages/EmailIntegration/src/Models/Email.php` | modify | `systemCategory()` |
| `packages/EmailIntegration/src/Enums/OutboxTab.php` | modify | `constrain()`, the one rule per segment |
| `packages/EmailIntegration/src/Livewire/OutboxTable.php` | modify | segments, labels, sort |
| `packages/EmailIntegration/resources/views/livewire/outbox-table.blade.php` | create | segmented tabs above the table |
| `packages/EmailIntegration/src/Filament/Pages/EmailInboxPage.php` | modify | Outbox badge counts the Queued segment |
| `packages/EmailIntegration/resources/views/filament/pages/email-inbox.blade.php` | modify | connect card above the tabs |
| `packages/EmailIntegration/resources/views/components/not-connected.blade.php` | modify | `compact` layout |
| `packages/EmailIntegration/src/Livewire/DraftsTable.php` | modify | drop its own connect empty state |
| `app/Providers/Filament/AppPanelProvider.php` | modify | BODY_END hook for the tab script |
| `resources/views/filament/app/active-tab-into-view.blade.php` | create | scrolls the active tab into view |
| `lang/en/filament/pages/email-outbox.php`, `lang/en/filament/pages/email-inbox.php` | modify | new labels |
| `tests/Feature/EmailIntegration/ReplyForwardEmailTest.php` | modify | prefix cases |
| `tests/Feature/EmailIntegration/EmailsRelationManagerReadingTest.php` | modify | snippet once, text-only body |
| `tests/Feature/EmailIntegration/RecordEmailsCategoryBadgeTest.php` | modify | enum colour |
| `tests/Feature/EmailIntegration/EmailOutboxPageTest.php` | modify | segments, labels, sort |
| `tests/Feature/EmailIntegration/EmailPageTabsTest.php` | modify | card above tabs, Outbox badge |
| `tests/Browser/EmailIntegration/EmailReaderBodyBrowserTest.php` | create | computed iframe styles. No reader body browser test exists |
| `tests/Browser/CRM/RecordPageLayoutTest.php` | modify | active tab in view at 390px |

---

### Task 1.1: Keep one reply or forward prefix

**Files:**
- Modify: `packages/EmailIntegration/src/Livewire/EmailComposer.php` (line 322 in `openReply()`, plus one private method)
- Modify: `tests/Feature/EmailIntegration/ReplyForwardEmailTest.php`

**Interfaces:**
- Consumes: `EmailComposer::openReply(string $emailId, string $mode)`.
- Produces: `EmailComposer::prefixedSubject(string $subject): string` (private). A reply keeps an existing `Re`, `Aw` or `Sv` prefix. A forward keeps an existing `Fwd`, `Fw` or `Wg` prefix. Matching ignores case and needs the colon. A reply to `Fwd: X` becomes `Re: Fwd: X`, as Gmail and Outlook do.

- [ ] **Step 1: Write the failing tests.** Add after `inline composer prefills reply-all and forward from their modes`:

```php
it('keeps a prefix the subject already carries instead of adding a second', function (string $mode, string $subject, string $expected): void {
    $this->inboundEmail->update(['subject' => $subject]);

    livewire(EmailComposer::class, ['dock' => 'inline'])
        ->call('openReply', $this->inboundEmail->id, $mode)
        ->assertSet('subject', $expected);
})->with([
    'reply to Re' => ['reply', 'Re: Pricing', 'Re: Pricing'],
    'reply to RE' => ['reply', 'RE: Pricing', 'RE: Pricing'],
    'reply all to Aw' => ['reply_all', 'Aw: Angebot', 'Aw: Angebot'],
    'reply to SV' => ['reply', 'SV: Offert', 'SV: Offert'],
    'reply to a forward' => ['reply', 'Fwd: Pricing', 'Re: Fwd: Pricing'],
    'forward a Fwd' => ['forward', 'Fwd: Pricing', 'Fwd: Pricing'],
    'forward a FW' => ['forward', 'FW: Pricing', 'FW: Pricing'],
    'forward a WG' => ['forward', 'WG: Angebot', 'WG: Angebot'],
    'forward a reply' => ['forward', 'Re: Pricing', 'Fwd: Re: Pricing'],
    'a word that starts with re' => ['reply', 'Renewal terms', 'Re: Renewal terms'],
]);

it('sends a reply to a Re subject with a single prefix', function (): void {
    $this->inboundEmail->update(['subject' => 'Re: Pricing for 40 seats']);

    livewire(EmailComposer::class, ['dock' => 'inline'])
        ->call('openReply', $this->inboundEmail->id, 'reply')
        ->set('bodyHtml', '<p>Works for us</p>')
        ->call('send')
        ->assertHasNoErrors();

    expect(Email::query()->where('direction', EmailDirection::OUTBOUND)->sole()->subject)
        ->toBe('Re: Pricing for 40 seats');
});
```

- [ ] **Step 2: Run them.** `php artisan test --compact tests/Feature/EmailIntegration/ReplyForwardEmailTest.php --filter='prefix|single prefix'`. Expected: FAIL. Every prefixed case gets a second prefix, for example `Re: Re: Pricing`.

- [ ] **Step 3: Implement.** Replace line 322 with `$this->subject = $this->prefixedSubject($originalSubject);` and add:

```php
private function prefixedSubject(string $subject): string
{
    $isForward = $this->replyMode === 'forward';
    $existingPrefix = $isForward ? '/^\s*(fwd?|wg)\s*:/iu' : '/^\s*(re|aw|sv)\s*:/iu';

    if (preg_match($existingPrefix, $subject) === 1) {
        return $subject;
    }

    return ($isForward ? 'Fwd: ' : 'Re: ').$subject;
}
```

- [ ] **Step 4: Run the file.** `php artisan test --compact tests/Feature/EmailIntegration/ReplyForwardEmailTest.php`. Expected: PASS. The `metadata only` dataset still expects `Re: `.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 6: Commit.** `fix(email): keep one re or fwd prefix on replies and forwards`

---

### Task 1.2: Drop the reader's snippet line and let the badge read the enum

**Files:**
- Modify: `packages/EmailIntegration/resources/views/components/email-view.blade.php` (delete lines 138-147 and the now-unused `$snippet` and `$isReply` at 68-69. Change the badge at 127)
- Modify: `packages/EmailIntegration/resources/views/components/list-row-wide.blade.php` (line 172)
- Modify: `packages/EmailIntegration/resources/views/components/category-badge.blade.php` (whole file)
- Modify: `packages/EmailIntegration/src/Enums/EmailCategory.php` (`getLabel()`, line 30)
- Modify: `packages/EmailIntegration/src/Models/Email.php` (next to `categoryLabel()`, line 259)
- Modify: `lang/en/filament/pages/email-inbox.php` (add `categories.*`)
- Modify: `tests/Feature/EmailIntegration/EmailsRelationManagerReadingTest.php`, `tests/Feature/EmailIntegration/RecordEmailsCategoryBadgeTest.php`

**Interfaces:**
- Consumes: `Email::categoryLabel(): ?EmailLabel`, which stays because `EmailThreadSummaryService.php:145` reads the raw label.
- Produces: `Email::systemCategory(): ?EmailCategory`. `<x-email-integration::category-badge :category="EmailCategory">` replaces the `label` prop. `EmailCategory::getLabel()` returns `__('filament/pages/email-inbox.categories.<value>')`.

- [ ] **Step 1: Write the failing tests.** In `EmailsRelationManagerReadingTest.php`, replace `shows the opening of the message in the reader to a viewer with full access` with a test that tells the list row apart from the reader:

```php
it('shows the snippet in the list row only, not again under the reader subject', function (): void {
    $email = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'subject' => 'Renewal terms',
        'snippet' => 'The discount is forty percent',
        'privacy_tier' => EmailPrivacyTier::FULL,
    ]);
    EmailBody::create(['email_id' => $email->id, 'body_html' => '<p>Full body</p>', 'body_text' => 'Full body']);

    $this->person->emails()->attach($email->getKey());

    $html = livewire(EmailsRelationManager::class, ['ownerRecord' => $this->person, 'pageClass' => ViewPeople::class])
        ->call('selectEmail', $email->getKey())
        ->assertSee('Renewal terms')
        ->html();

    expect(substr_count($html, 'The discount is forty percent'))->toBe(1);
});

it('renders a text-only body in the app theme without a frame', function (): void {
    $email = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'privacy_tier' => EmailPrivacyTier::FULL,
    ]);
    EmailBody::create(['email_id' => $email->id, 'body_html' => null, 'body_text' => 'Plain hello from Brian']);

    $this->person->emails()->attach($email->getKey());

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->person, 'pageClass' => ViewPeople::class])
        ->call('selectEmail', $email->getKey())
        ->assertSee('Plain hello from Brian')
        ->assertDontSeeHtml('<iframe');
});
```

Add `use Relaticle\EmailIntegration\Models\EmailBody;`. In `RecordEmailsCategoryBadgeTest.php` add:

```php
it('colours the category tag from the enum, not from a private map', function (EmailCategory $category): void {
    $email = Email::factory()->inbound()->full()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
    ]);
    EmailLabel::factory()->category($category->value)->create(['email_id' => $email->getKey()]);
    $this->person->emails()->attach($email->getKey());

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->person, 'pageClass' => ViewPeople::class])
        ->assertSeeHtml('fi-color-'.$category->getColor())
        ->assertSee($category->getLabel());
})->with([EmailCategory::Invoice, EmailCategory::Support, EmailCategory::Sales]);
```

- [ ] **Step 2: Run them.** `php artisan test --compact tests/Feature/EmailIntegration/EmailsRelationManagerReadingTest.php tests/Feature/EmailIntegration/RecordEmailsCategoryBadgeTest.php`. Expected: the snippet test FAILS with a count of 2. The colour test FAILS because no `fi-color-*` class renders. The text-only test PASSES and stays as the regression guard from plan note 2.

- [ ] **Step 3: Implement.** In `Email.php`:

```php
public function systemCategory(): ?EmailCategory
{
    $label = $this->categoryLabel();

    return $label instanceof EmailLabel ? EmailCategory::from($label->label) : null;
}
```

Replace `category-badge.blade.php` with:

```blade
@props(['category'])

<x-filament::badge :color="$category->getColor()" size="sm" {{ $attributes }}>
    {{ $category->getLabel() }}
</x-filament::badge>
```

In both callers, read `$category = $record->systemCategory();` (or `$email->`) in the `@php` block instead of `categoryLabel()`, and render `<x-email-integration::category-badge :category="$category" />`. `list-row-wide` keeps `class="ml-auto"`. Change `EmailCategory::getLabel()` to `return __('filament/pages/email-inbox.categories.'.strtolower($this->value));`, and add the seven keys in English. Delete the snippet `<p>` block and the two variables from `email-view.blade.php`.

- [ ] **Step 4: Run tests.** Same command as step 2. Expected: PASS.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 6: Commit.** `fix(email): stop repeating the snippet and colour categories from the enum`

---

### Task 1.3: Give the message frame a base font and the app's theme

**Files:**
- Modify: `packages/EmailIntegration/src/Support/EmailHtmlSanitizer.php` (`wrapPreviewDocument()`, lines 104-139)
- Modify: `packages/EmailIntegration/resources/views/components/email-view.blade.php` (frame Alpine data at 368-395, wrapper class at 400)
- Create: `tests/Browser/EmailIntegration/EmailReaderBodyBrowserTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: the frame document's `<html>` carries `data-theme="light|dark"`. The reader sets it on load and whenever the page's `dark` class changes. A zero-specificity `:where(body)` rule sets the font, so any font the email declares wins.

- [ ] **Step 1: Reproduce first.** Open a person's Emails tab with an HTML email in dark mode at 1440px (see the verification task for the URL). Screenshot the white block to `.amp/in/artifacts/pr1-before-dark.png`. If the frame is already dark, the cause in plan note 2 is wrong. Stop and re-read `EmailHtmlSanitizer.php:123` before changing code.

- [ ] **Step 2: Write the failing browser test.**

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\People;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;

mutates(EmailHtmlSanitizer::class);

function readerFrameStyle(): string
{
    return <<<'JS'
        (() => {
            const doc = document.querySelector('.fi-email-reader-panel iframe').contentDocument;
            const body = getComputedStyle(doc.body);
            const styled = doc.querySelector('[data-own-font]');

            return {
                theme: doc.documentElement.dataset.theme,
                font: body.fontFamily,
                size: body.fontSize,
                padding: body.paddingLeft,
                background: body.backgroundColor,
                ownFont: styled ? getComputedStyle(styled).fontFamily : null,
            };
        })();
    JS;
}

it('renders an email body in the app font and theme unless the email sets its own', function (string $theme): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id, 'workspace_id' => $workspace->id, 'sync_cursor' => 'done',
    ]));
    $person = People::factory()->create(['workspace_id' => $workspace->id, 'creator_id' => $user->id]);
    $email = Email::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $user->id, 'connected_account_id' => $account->id,
        'subject' => 'Pricing for 40 seats', 'privacy_tier' => EmailPrivacyTier::FULL,
    ]);
    EmailBody::create([
        'email_id' => $email->id,
        'body_html' => '<style>.own{font-family:Georgia,serif}</style><p>Thanks for the call.</p><p class="own" data-own-font>Signed</p>',
        'body_text' => 'Thanks for the call.',
    ]);
    $person->emails()->attach($email->id);
    $relation = array_search(EmailsRelationManager::class, PeopleResource::getRelations(), true);

    $page = loginViaBrowser($user, $theme)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->id}?relation={$relation}")
        ->click('Pricing for 40 seats')
        ->assertVisible('.fi-email-reader-panel iframe')
        ->assertScript("document.querySelector('.fi-email-reader-panel iframe').contentDocument.readyState === 'complete'");

    $style = $page->script(readerFrameStyle());

    expect($style['theme'])->toBe($theme === 'inDarkMode' ? 'dark' : 'light')
        ->and($style['font'])->not->toContain('Times')
        ->and($style['size'])->toBe('14px')
        ->and($style['padding'])->toBe('16px')
        ->and($style['ownFont'])->toContain('Georgia');

    if ($theme === 'inDarkMode') {
        expect($style['background'])->toBe('rgba(0, 0, 0, 0)');
    }

    $page->screenshot(filename: "email-reader-body-{$theme}")->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);
```

- [ ] **Step 3: Run it.** `php artisan test --compact tests/Browser/EmailIntegration/EmailReaderBodyBrowserTest.php`. Expected: FAIL. `theme` is undefined, the font is the serif default, the size is `16px` and the padding is `0px`.

- [ ] **Step 4: Implement.** In the sanitizer's `<style>`, replace the `body` block and the whole `@media (prefers-color-scheme: dark)` block with:

```css
:where(body) {
    font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 14px;
    line-height: 1.6;
}
body { box-sizing: border-box; padding: 16px; overflow-wrap: anywhere; }
:root[data-theme="dark"] { background: transparent; }
:root[data-theme="dark"] body { background: transparent !important; color: #f3f4f6 !important; }
:root[data-theme="dark"] :is(table, tbody, thead, tfoot, tr, td, th, div, p, span, section, article, main, blockquote, li) {
    background-color: transparent !important;
    border-color: #4b5563 !important;
    color: #f3f4f6 !important;
}
:root[data-theme="dark"] a { color: #93c5fd !important; }
:root[data-theme="dark"] [bgcolor] { background-color: transparent !important; }
```

In the frame's `x-data`, add a `theme()` method. Call it at the start of the `load` listener, and once from `init()` through a `MutationObserver` on `document.documentElement` with `{ attributes: true, attributeFilter: ['class'] }`:

```js
theme() {
    const doc = $refs.body?.contentDocument

    if (doc?.documentElement) doc.documentElement.dataset.theme = document.documentElement.classList.contains('dark') ? 'dark' : 'light'
},
```

Change the wrapper at line 400 from `px-6 py-6 sm:px-8 lg:px-10` to `px-2 sm:px-4`, because the frame now pads itself.

- [ ] **Step 5: Run tests.** The browser file, then `php artisan test --compact tests/Feature/EmailIntegration/EmailBodySanitizationTest.php tests/Feature/EmailIntegration/ReplyForwardEmailTest.php`. Expected: PASS. The sandbox test at `ReplyForwardEmailTest.php:97` must still see no `allow-scripts`.
- [ ] **Step 6: WebKit.** `php artisan test --compact tests/Browser/EmailIntegration/EmailReaderBodyBrowserTest.php --browser safari`. Expected: PASS.
- [ ] **Step 7:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 8: Commit.** `fix(email): render email bodies in the app font and theme`

---

### Task 1.4: Outbox segments, labels and sort

**Files:**
- Modify: `packages/EmailIntegration/src/Enums/OutboxTab.php`
- Modify: `packages/EmailIntegration/src/Livewire/OutboxTable.php` (columns 66-77, actions 79-113, filter 180-201, tab rules 207-231, render 159-162)
- Create: `packages/EmailIntegration/resources/views/livewire/outbox-table.blade.php`
- Modify: `packages/EmailIntegration/src/Filament/Pages/EmailInboxPage.php` (Outbox count, lines 118-122)
- Modify: `lang/en/filament/pages/email-outbox.php`
- Modify: `tests/Feature/EmailIntegration/EmailOutboxPageTest.php`, `tests/Feature/EmailIntegration/EmailPageTabsTest.php`

**Interfaces:**
- Consumes: `OutboxTable::$lockedStatus`, `OutboxTable::$includeFailedFilter`.
- Produces: `OutboxTab::constrain(Builder $query): Builder` holds the rule each segment applies. It moves `applyStatusTab()` and `queuedDueCutoff()` onto the enum. A public method on an enum may take a builder (`ConventionsTest`). New members: `OutboxTable::$statusTab` (`OutboxTab`, `#[Url(as: 'status')]`), `OutboxTable::setStatusTab(string $tab): void`, `OutboxTable::segments(): list<OutboxTab>` and `OutboxTable::segmentCounts(): array<string, int>` (`#[Computed]`). The `status_tab` filter is removed. The page's Outbox badge counts `OutboxTab::QUEUED`, which is what the default segment shows.

- [ ] **Step 1: Write the failing tests.** In `EmailOutboxPageTest.php`, change every `->filterTable('status_tab', 'x')` to `->call('setStatusTab', 'x')` (lines 108, 120, 212, 238). Replace the two `getFilter('status_tab')` assertions (lines 158 and 179-195) with `segments()` checks. Then add:

```php
it('shows statuses as labelled segments, not as a removable filter chip', function (): void {
    $component = livewire(OutboxTable::class);

    expect($component->instance()->getTable()->getFilter('status_tab'))->toBeNull();

    $component->assertDontSee('Status tab')
        ->assertSee(__('filament/pages/email-outbox.tabs.scheduled'))
        ->assertSee(__('filament/pages/email-outbox.columns.subject'))
        ->assertSee(__('filament/pages/email-outbox.columns.status'));
});

it('labels every row action', function (): void {
    $queued = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $failed = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED);

    livewire(OutboxTable::class)
        ->assertActionHasLabel(TestAction::make('cancel')->table($queued), __('filament/pages/email-outbox.actions.cancel'))
        ->assertActionHasLabel(TestAction::make('reschedule')->table($queued), __('filament/pages/email-outbox.actions.reschedule'))
        ->call('setStatusTab', 'failed')
        ->assertActionHasLabel(TestAction::make('retry')->table($failed), __('filament/pages/email-outbox.actions.retry'));
});

it('lists scheduled sends soonest first', function (): void {
    $this->travelTo(now()->startOfSecond());

    $later = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, ['scheduled_for' => now()->addHours(3)]);
    $soonest = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, ['scheduled_for' => now()->addHour()]);
    $middle = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, ['scheduled_for' => now()->addHours(2)]);

    livewire(OutboxTable::class)
        ->call('setStatusTab', 'scheduled')
        ->assertCanSeeTableRecords([$soonest, $middle, $later], inOrder: true);
});
```

In `EmailPageTabsTest.php` add:

```php
it('badges the outbox with what its default segment lists', function (): void {
    $this->travelTo(now()->startOfSecond());

    foreach ([null, now()->addDay(), now()->addDays(2)] as $scheduledFor) {
        Email::query()->create([
            'workspace_id' => $this->workspace->id, 'user_id' => $this->user->id,
            'connected_account_id' => $this->account->id, 'direction' => EmailDirection::OUTBOUND,
            'status' => EmailStatus::QUEUED, 'privacy_tier' => EmailPrivacyTier::FULL,
            'creation_source' => EmailCreationSource::COMPOSE, 'scheduled_for' => $scheduledFor,
        ]);
    }

    expect(Livewire::test(EmailInboxPage::class)->instance()->tabCounts()[EmailPageTab::OUTBOX->value])->toBe(1);
});
```

- [ ] **Step 2: Run them.** `php artisan test --compact tests/Feature/EmailIntegration/EmailOutboxPageTest.php tests/Feature/EmailIntegration/EmailPageTabsTest.php`. Expected: FAIL. `setStatusTab` does not exist, the labels are the raw names, the order is insertion order, and the badge reads 3.

- [ ] **Step 3: Implement.** On `OutboxTab`:

```php
/**
 * @param  Builder<Email>  $query
 * @return Builder<Email>
 */
public function constrain(Builder $query): Builder
{
    $dueCutoff = now()->addSeconds(Config::integer('email-integration.outbox.undo_send_window_seconds'));

    return match ($this) {
        self::SCHEDULED => $query->where('status', EmailStatus::QUEUED)
            ->whereNotNull('scheduled_for')->where('scheduled_for', '>', $dueCutoff)
            ->whereNot(fn (Builder $held): Builder => $held->createdOverMcp()),
        self::QUEUED => $query->where('status', EmailStatus::QUEUED)
            ->where(fn (Builder $due): Builder => $due->whereNull('scheduled_for')
                ->orWhere('scheduled_for', '<=', $dueCutoff)
                ->orWhere(fn (Builder $held): Builder => $held->createdOverMcp())),
        self::SENDING => $query->where('status', EmailStatus::SENDING),
        self::FAILED => $query->where('status', EmailStatus::FAILED),
        self::SENT => $query->where('status', EmailStatus::SENT)->where('sent_at', '>=', now()->subDay()),
    };
}
```

In `OutboxTable`, delete `statusFilter()`, `statusFilterOptions()`, `applyStatusTab()` and `queuedDueCutoff()`. Add `$statusTab`, `setStatusTab()` (`OutboxTab::from()` plus `$this->resetTable()`), `segments()` (every case, without `FAILED` when `! $includeFailedFilter`) and `segmentCounts()` (one `constrain()->count()` per segment over `buildQuery()`). `buildQuery()` applies `$this->statusTab->constrain($query)` when no status is locked. Sort with `->defaultSort(fn (Builder $query): Builder => $this->statusTab === OutboxTab::SENT ? $query->latest('sent_at') : $query->orderByRaw('scheduled_for asc nulls first')->oldest('created_at'))`. Add `->label(__(...))` to the `subject`, `status` and `last_error` columns and to the `cancel`, `reschedule` and `retry` actions. `render()` returns `email-integration::livewire.outbox-table`, which copies the `ei-tabs-segmented` markup from `access-requests-table.blade.php:3-20`: one `x-filament::tabs.item` per segment, with its count as the badge when it is above zero, and `wire:click="setStatusTab('...')"`. Skip the tabs when `$lockedStatus` is set. In `EmailInboxPage::tabCounts()`, the Outbox entry becomes `OutboxTab::QUEUED->constrain(Email::query()->forWorkspace($workspaceId)->where('user_id', $user->getKey())->where('direction', EmailDirection::OUTBOUND))->count()`. Add the lang keys `columns.subject`, `columns.status`, `columns.last_error`, `actions.cancel`, `actions.reschedule`, `actions.retry` and `tabs.aria`.

- [ ] **Step 4: Run tests.** Same command as step 2, plus `tests/Feature/Mcp/EmailToolsTest.php --filter=Outbox`. Expected: PASS.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 6: Commit.** `feat(email): show outbox statuses as labelled segments sorted by send time`

---

### Task 1.5: Put the connect card above usable tabs

**Files:**
- Modify: `packages/EmailIntegration/resources/views/filament/pages/email-inbox.blade.php` (lines 2-23)
- Modify: `packages/EmailIntegration/resources/views/components/not-connected.blade.php`
- Modify: `packages/EmailIntegration/src/Livewire/DraftsTable.php` (empty state 64-77, `connectMailboxActions()` 47-56)
- Modify: `tests/Feature/EmailIntegration/EmailPageTabsTest.php` (the four drafts connect tests at 179-235 move to the page)

**Interfaces:**
- Consumes: `EmailInboxPage::$hasConnectedMailbox`, `connectMailboxAction()`, `connectAzureAction()`.
- Produces: `<x-email-integration::not-connected compact>`, a horizontal card with `data-connect-card`. The tabs always render. `DraftsTable` no longer renders connect actions. Its empty state is always the drafts copy, with Compose hidden while there is no mailbox, as today.

- [ ] **Step 1: Write the failing tests.** Move the four drafts connect tests to `EmailInboxPage`, asserting on the page. Then add:

```php
it('keeps drafts and templates reachable under the connect card when no mailbox is connected', function (): void {
    $this->account->forceDelete();
    EmailTemplate::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->user->id, 'name' => 'Intro call']);

    Livewire::test(EmailInboxPage::class)
        ->assertSeeHtml('data-connect-card')
        ->assertSee(__('filament/pages/email-accounts.not_connected.inbox.heading'))
        ->assertSee(EmailPageTab::DRAFTS->getLabel())
        ->assertSee(EmailPageTab::TEMPLATES->getLabel())
        ->call('setTab', EmailPageTab::TEMPLATES->value)
        ->assertSeeLivewire(TemplatesTable::class);

    Livewire::test(DraftsTable::class)
        ->assertSee(__('filament/pages/email-inbox.drafts.empty.heading'))
        ->assertDontSee(__('filament/pages/email-accounts.not_connected.inbox.heading'));
});

it('shows no connect card once a mailbox is connected', function (): void {
    Livewire::test(EmailInboxPage::class)->assertDontSeeHtml('data-connect-card');
});
```

- [ ] **Step 2: Run them.** `php artisan test --compact tests/Feature/EmailIntegration/EmailPageTabsTest.php`. Expected: FAIL. With no mailbox the tabs are missing, and Drafts shows the connect copy.
- [ ] **Step 3: Implement.** In `email-inbox.blade.php`, render `@unless ($this->hasConnectedMailbox) <x-email-integration::not-connected compact ... /> @endunless` first, then the tab bar and `@livewire` unconditionally. In `not-connected.blade.php`, add `'compact' => false`. When it is true, use `flex flex-col gap-3 border-b border-gray-200/60 px-4 py-4 sm:flex-row sm:items-center lg:px-6 dark:border-white/10` and a smaller icon, and add `data-connect-card` on the root. In `DraftsTable`, drop the connect branches from the three empty-state closures, `connectMailboxActions()` and the `...$this->connectMailboxActions()` spread.
- [ ] **Step 4: Run tests.** Same command. Expected: PASS.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 6: Commit.** `fix(email): keep drafts and templates usable before a mailbox is connected`

---

### Task 1.6: Keep the active record tab in view on a phone

**Files:**
- Create: `resources/views/filament/app/active-tab-into-view.blade.php`
- Modify: `app/Providers/Filament/AppPanelProvider.php` (`registerInvitationHooks()` region, next to the existing BODY_END hook at line 364)
- Modify: `tests/Browser/CRM/RecordPageLayoutTest.php`

**Interfaces:**
- Consumes: Filament's `.fi-tabs` and `.fi-tabs-item.fi-active`.
- Produces: a script that horizontally centres the active tab in any overflowing tab bar on `DOMContentLoaded` and `livewire:navigated`. It never scrolls the page vertically.

- [ ] **Step 1: Write the failing browser test.**

```php
it('keeps the active record tab in view on a phone', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Northwind Traders']);
    $relation = array_search(EmailsRelationManager::class, CompanyResource::getRelations(), true);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}?relation={$relation}")
        ->assertSeeIn('.fi-record-pane .fi-tabs-item.fi-active', 'Emails');

    $inView = <<<'JS'
        (() => {
            const tab = document.querySelector('.fi-record-pane .fi-tabs-item.fi-active').getBoundingClientRect();
            const bar = document.querySelector('.fi-record-pane .fi-tabs').getBoundingClientRect();

            return tab.left >= bar.left && tab.right <= bar.right && window.scrollY === 0;
        })();
    JS;

    expect($page->script($inView))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
```

Add `use App\Filament\Resources\CompanyResource;` and `use App\Filament\Resources\CompanyResource\RelationManagers\EmailsRelationManager;`. Confirm the second path against `CompanyResource.php:150`.

- [ ] **Step 2: Run it.** `php artisan test --compact tests/Browser/CRM/RecordPageLayoutTest.php --filter='active record tab'`. Expected: FAIL, because the Emails tab sits past the bar's right edge.
- [ ] **Step 3: Implement.** The view:

```blade
<script>
    (() => {
        const reveal = () => document.querySelectorAll('.fi-tabs').forEach((bar) => {
            const tab = bar.querySelector('.fi-tabs-item.fi-active')

            if (! tab || bar.scrollWidth <= bar.clientWidth) return

            const barBox = bar.getBoundingClientRect()
            const tabBox = tab.getBoundingClientRect()

            bar.scrollLeft += tabBox.left - barBox.left - (barBox.width - tabBox.width) / 2
        })

        document.addEventListener('DOMContentLoaded', reveal)
        document.addEventListener('livewire:navigated', reveal)
    })()
</script>
```

Register it with `->renderHook(PanelsRenderHook::BODY_END, fn (): View|Factory => view('filament.app.active-tab-into-view'))`.
- [ ] **Step 4: Run it, then WebKit.** The same command, then again with `--browser safari`. Expected: PASS.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 6: Commit.** `fix(app): scroll the active record tab into view on small screens`

---

### Task 1.7: Verify in the browser and open the PR

- [ ] **Step 1:** Run `amp orb services ensure`. Sign in as `owner@relaticle.test` at the portal URL. In agent-browser, `set viewport 1440 900 2`, then `390 844 2`, in light and dark. Save screenshots to `.amp/in/artifacts/pr1-*.png` for: the person Emails tab reader with an HTML body and with a text-only body, the Outbox with each segment and an empty segment, the Emails page signed in as `empty@relaticle.test` (connect card above the tabs, Drafts and Templates open), and a company page at 390px with the Emails tab active. Compare against the step 1 screenshot from Task 1.3.
- [ ] **Step 2:** Run the once-per-PR checks from the master plan.
- [ ] **Step 3:** Push and open the PR.

**PR checklist**
- [ ] No migration, so no rehearsal.
- [ ] Browser tests pass in Chromium and in `--browser safari` for `EmailReaderBodyBrowserTest` and `RecordPageLayoutTest`.
- [ ] Screenshots at 1440 and 390px, light and dark, including the empty states, are in the PR body.
- [ ] The PR body names plan notes 1 to 4 so the founder sees the Sales colour change and the deferred no-provider message.
- [ ] `grep -rn "category-badge :label" packages` returns nothing.
- [ ] Findings 1, 5, 6, 7, 10, 11, 12, 13, 18, 19 and 21 are marked closed in `docs/email/findings.md` in the PR.

---

# PR 3: linked-only privacy rule

**Branch:** `feat/email-linked-only-privacy`
**Goal:** A teammate's mail reaches a viewer only where it is linked to a record, unless the viewer holds their own copy or was shared it. The policy, Rela and the MCP server follow the same rule. Record tabs already list only linked mail. Raising the workspace default no longer widens past mail.

## File map

| File | Change | Owns |
|---|---|---|
| `packages/EmailIntegration/src/Models/Scopes/VisibleEmailScope.php` | modify | linked-only in the by-default branch |
| `packages/EmailIntegration/src/Services/PrivacyService.php` | modify | the same rule for one loaded email |
| `packages/EmailIntegration/src/Models/Email.php` | modify | `isLinkedToRecord()`, `#[Scope] withRecordLinkState()` |
| `packages/EmailIntegration/src/Queries/VisibleEmailsQuery.php` | modify | preloads the link state for agent lists and reads |
| `packages/EmailIntegration/src/Enums/EmailPrivacyTier.php` | modify | `moreOpenTiers()` |
| `packages/EmailIntegration/src/Actions/ApplyDefaultSharingTierToExistingEmailsAction.php` | modify | `executeForWorkspace()` only lowers |
| `lang/en/filament/pages/email-privacy-settings.php` | modify | `workspace_default.description` (line 19) |
| `tests/Feature/EmailIntegration/VisibleEmailScopeTest.php` | modify | the rule and its exceptions |
| `tests/Feature/EmailIntegration/EmailPolicyTest.php` | modify | direct reads by id |
| `tests/Feature/Mcp/EmailToolsTest.php`, `tests/Feature/Chat/EmailToolsTest.php` | modify | agent lists and reads |
| `tests/Feature/EmailIntegration/ApplyDefaultSharingTierToExistingEmailsActionTest.php`, `EmailPrivacySettingsPageTest.php` | modify | raise and lower |
| Other teammate-visibility fixtures | modify | link the email the test expects to see |

---

### Task 3.1: Hide a teammate's unlinked mail in the scope and the policy

**Files:**
- Modify: `packages/EmailIntegration/src/Models/Scopes/VisibleEmailScope.php` (lines 106-120)
- Modify: `packages/EmailIntegration/src/Services/PrivacyService.php` (lines 58-65)
- Modify: `packages/EmailIntegration/src/Models/Email.php`
- Modify: `tests/Feature/EmailIntegration/VisibleEmailScopeTest.php`, `tests/Feature/EmailIntegration/EmailPolicyTest.php`

**Interfaces:**
- Consumes: the `emailables` table (`create_emailables.php`, index on `email_id`).
- Produces: `Email::isLinkedToRecord(): bool`. It reads a preloaded `is_linked_to_record` attribute, then a loaded `pivot` (the email came through a record relation), then queries. `#[Scope] Email::withRecordLinkState()` selects `emails.*` plus that attribute. `VisibleEmailScope` and `effectiveTier()` add "linked to a record" to the teammate default branch only. The own-copy, direct-share and cross-message-share branches stay as they are (plan note 5).

- [ ] **Step 1: Write the failing tests.** In `VisibleEmailScopeTest.php`, make `$this->makeCoworkerEmail` link every email it creates to `$this->anchor`, a company created in `beforeEach`. Add a second argument `bool $linked = true` so a test can opt out, and import `App\Models\Company`. Then add:

```php
it('hides a teammate email that no record links to', function (): void {
    $linked = ($this->makeCoworkerEmail)(['client@acme.test']);
    $unlinked = ($this->makeCoworkerEmail)(['client@acme.test'], false);

    expect(visibleTo($this->viewer)->modelKeys())->toContain($linked->id)
        ->not->toContain($unlinked->id);
});

it('still shows the owner their own unlinked email', function (): void {
    $unlinked = ($this->makeCoworkerEmail)(['client@acme.test'], false);

    expect(visibleTo($this->coworker)->modelKeys())->toContain($unlinked->id);
});

it('shows an unlinked teammate email the owner shared with the viewer', function (): void {
    $unlinked = ($this->makeCoworkerEmail)(['client@acme.test'], false);
    EmailShare::factory()->tier(EmailPrivacyTier::SUBJECT)->create([
        'email_id' => $unlinked->id, 'shared_by' => $this->coworker->id, 'shared_with' => $this->viewer->id,
    ]);

    expect(visibleTo($this->viewer)->modelKeys())->toContain($unlinked->id);
});

it('shows an unlinked teammate copy of a message the viewer also holds', function (): void {
    $unlinked = ($this->makeCoworkerEmail)(['client@acme.test'], false);
    $unlinked->update(['rfc_message_id' => '<shared@acme.test>']);
    $viewerAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $this->viewer->id, 'email_address' => 'viewer@northwind.test',
    ]));
    Email::factory()->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $this->viewer->id,
        'connected_account_id' => $viewerAccount->id, 'rfc_message_id' => '<shared@acme.test>',
    ]);

    expect(visibleTo($this->viewer)->modelKeys())->toContain($unlinked->id);
});

it('hides a teammate email again once its last link is removed', function (): void {
    $email = ($this->makeCoworkerEmail)(['client@acme.test']);
    $this->anchor->emails()->detach($email->id);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($email->id);
});
```

In `EmailPolicyTest.php` (import `App\Models\Company`), link `$this->email` to a company in the existing test `still allows a teammate to view a shared-tier email in the same workspace`, then add:

```php
it('denies a teammate every view ability on an email no record links to', function (): void {
    $teammate = User::factory()->create();
    $teammate->workspaces()->attach($this->workspace);
    $teammate->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    expect($teammate->can('view', $this->email))->toBeFalse()
        ->and($teammate->can('viewSubject', $this->email))->toBeFalse()
        ->and($teammate->can('viewBody', $this->email))->toBeFalse()
        ->and($teammate->can('requestAccess', $this->email))->toBeFalse()
        ->and($this->owner->can('viewBody', $this->email))->toBeTrue();

    Company::factory()->create(['workspace_id' => $this->workspace->id])->emails()->attach($this->email->id);

    expect($teammate->can('viewBody', $this->email->fresh()))->toBeTrue();
});
```

- [ ] **Step 2: Run them.** `php artisan test --compact tests/Feature/EmailIntegration/VisibleEmailScopeTest.php tests/Feature/EmailIntegration/EmailPolicyTest.php`. Expected: FAIL. Unlinked teammate mail is still visible and still viewable.

- [ ] **Step 3: Implement.** In the scope's `$byDefault` closure, after `->whereIn('privacy_tier', $visibleTiers)`:

```php
->whereExists(fn (BaseBuilder $link): BaseBuilder => $link
    ->from('emailables')
    ->whereColumn('emailables.email_id', 'emails.id'))
```

In `PrivacyService::effectiveTier()`, just before `$tier = $email->privacy_tier;`:

```php
if (! $email->isLinkedToRecord()) {
    return null;
}
```

On `Email`:

```php
public function isLinkedToRecord(): bool
{
    if (array_key_exists('is_linked_to_record', $this->attributes)) {
        return (bool) $this->attributes['is_linked_to_record'];
    }

    if ($this->relationLoaded('pivot')) {
        return true;
    }

    return DB::table('emailables')->where('email_id', $this->getKey())->exists();
}

/**
 * @param  Builder<Email>  $query
 * @return Builder<Email>
 */
#[Scope]
protected function withRecordLinkState(Builder $query): Builder
{
    if ($query->getQuery()->columns === null) {
        $query->select($query->qualifyColumn('*'));
    }

    return $query->selectRaw('exists (select 1 from emailables where emailables.email_id = emails.id) as is_linked_to_record');
}
```

- [ ] **Step 4: Run tests.** Same command. Expected: PASS.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 6: Commit.** `feat(email): show teammates another mailbox's mail only where it is linked`

---

### Task 3.2: Make Rela, the MCP server and the record tab agree

**Files:**
- Modify: `packages/EmailIntegration/src/Queries/VisibleEmailsQuery.php` (`paginate()` 65-90, `withDetail()` 117-123)
- Modify: `tests/Feature/Mcp/EmailToolsTest.php`, `tests/Feature/Chat/EmailToolsTest.php`
- Modify, if they fail after Task 3.1: `PrivacyServiceTest.php`, `DuplicateSyncedEmailAccessTest.php`, `EmailSharingServiceTest.php`, `EmailAccessRequestTest.php`, `EmailThreadSummaryServiceTest.php` and `ReplyForwardEmailTest.php` (the `inline composer prefills the original subject` dataset) in `tests/Feature/EmailIntegration`

**Interfaces:**
- Consumes: `Email::withRecordLinkState()`, `VisibleEmailScope`.
- Produces: `VisibleEmailsQuery::paginate()` and `find()` return models with `is_linked_to_record` loaded. `EmailForAgent` therefore runs no extra query per row.

- [ ] **Step 1: Write the failing tests.** In both `EmailToolsTest.php` files, give `$this->emailFrom` a fourth parameter `bool $linked = true`. When the owner is the coworker and `$linked` is true, attach the email to `$this->anchor`, a company made in `beforeEach`. Do not use a record that a `record_type` filter test reads. Then add to the MCP file:

```php
it('leaves a teammate email no record links to out of the list and the read', function (): void {
    $linked = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL]);
    $unlinked = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL], linked: false);
    $own = ($this->emailFrom)($this->viewer);

    expect(array_column(listedEmails($this->viewer), 'id'))->toEqualCanonicalizing([$linked->id, $own->id]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(GetEmailTool::class, ['id' => $unlinked->id])
        ->assertHasErrors(["Email with ID [{$unlinked->id}] not found."]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(GetEmailTool::class, ['id' => $linked->id])
        ->assertOk();
});
```

Add to the Chat file:

```php
it('keeps a teammate email no record links to away from the assistant', function (): void {
    $linked = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL]);
    $unlinked = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL], linked: false);

    expect(array_column(chatEmailTool($this->viewer, ListEmailsTool::class)['items'], 'id'))->toBe([$linked->id])
        ->and(chatEmailTool($this->viewer, GetEmailTool::class, ['id' => $unlinked->id]))->toBe(['error' => 'Email not found.'])
        ->and(chatEmailTool($this->coworker, ListEmailsTool::class)['items'])->toHaveCount(2);
});
```

- [ ] **Step 2: Run them.** `php artisan test --compact tests/Feature/Mcp/EmailToolsTest.php tests/Feature/Chat/EmailToolsTest.php`. Expected: the unlinked tests PASS already, because Task 3.1 changed the scope. `keeps a full page of teammate email under a fixed query budget` (`Mcp/EmailToolsTest.php:381`) FAILS, because `effectiveTier()` now queries `emailables` once per row.
- [ ] **Step 3: Implement.** In `paginate()`, chain `->withRecordLinkState()` before `->with([...])`. Do the same in `withDetail()`.
- [ ] **Step 4: Sweep the fixtures.** Run `composer test:affected`. For each failing test whose name says a teammate email is visible, link that email to a record in its arrange step. Never relax an assertion, and never pass `$linked = false` to make a test pass. Re-run until it is green.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 6: Commit.** `test(email): hold rela, mcp and record tabs to the linked-only rule`

---

### Task 3.3: Raising the workspace default never widens past mail

**Files:**
- Modify: `packages/EmailIntegration/src/Enums/EmailPrivacyTier.php` (next to `openness()`, line 29)
- Modify: `packages/EmailIntegration/src/Actions/ApplyDefaultSharingTierToExistingEmailsAction.php` (lines 15-28)
- Modify: `lang/en/filament/pages/email-privacy-settings.php` (line 19)
- Modify: `tests/Feature/EmailIntegration/ApplyDefaultSharingTierToExistingEmailsActionTest.php` (lines 114-166), `tests/Feature/EmailIntegration/EmailPrivacySettingsPageTest.php` (line 62)

**Interfaces:**
- Consumes: `SaveWorkspaceEmailSharingDefaultAction::execute()`, which still calls `executeForWorkspace()` only when the tier changes.
- Produces: `EmailPrivacyTier::moreOpenTiers(): list<self>`. `executeForWorkspace()` re-stamps only mail more open than the new default. New mail follows the live default through `EmailObserver::creating()`, unchanged. `executeForMailbox()` is unchanged: an owner who raises their own mailbox still widens its past mail, as D23 decided. The class name keeps its grandfathered `Action` suffix (`ConventionsTest.php:225, 286`).

- [ ] **Step 1: Write the failing tests.** Rewrite the four tests that expect a raise to widen (lines 114, 127, 157 and page line 62) as lowering cases, and add:

```php
it('leaves past mail at its level when the workspace default rises', function (): void {
    $mailbox = sharingMailbox($this->owner, $this->workspace);
    $past = sharingEmail($mailbox, EmailPrivacyTier::METADATA_ONLY);

    resolve(SaveWorkspaceEmailSharingDefaultAction::class)->execute($this->workspace, $this->owner, EmailPrivacyTier::SUBJECT);

    $future = Email::query()->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id,
        'connected_account_id' => $mailbox->id, 'creation_source' => EmailCreationSource::SYNC,
        'direction' => EmailDirection::INBOUND, 'status' => EmailStatus::SYNCED,
    ]);

    expect($past->fresh()->privacy_tier)->toBe(EmailPrivacyTier::METADATA_ONLY)
        ->and($future->privacy_tier)->toBe(EmailPrivacyTier::SUBJECT);
});

it('narrows past mail when the workspace default drops', function (): void {
    $this->workspace->update(['default_email_sharing_tier' => EmailPrivacyTier::SUBJECT]);
    $mailbox = sharingMailbox($this->owner, $this->workspace);
    $open = sharingEmail($mailbox, EmailPrivacyTier::SUBJECT);
    $private = sharingEmail($mailbox, EmailPrivacyTier::PRIVATE);

    resolve(SaveWorkspaceEmailSharingDefaultAction::class)->execute($this->workspace, $this->owner, EmailPrivacyTier::METADATA_ONLY);

    expect($open->fresh()->privacy_tier)->toBe(EmailPrivacyTier::METADATA_ONLY)
        ->and($private->fresh()->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE);
});
```

The second test catches an implementation that sets every row to the new tier: the Private email must not rise to Participants only.

- [ ] **Step 2: Run them.** `php artisan test --compact tests/Feature/EmailIntegration/ApplyDefaultSharingTierToExistingEmailsActionTest.php tests/Feature/EmailIntegration/EmailPrivacySettingsPageTest.php`. Expected: FAIL. Past mail rises to Subject, and the Private email rises to Participants only.
- [ ] **Step 3: Implement.**

```php
/** @return list<self> */
public function moreOpenTiers(): array
{
    return array_values(array_filter(self::cases(), fn (self $tier): bool => $tier->openness() > $this->openness()));
}
```

In `executeForWorkspace()`, replace `->where('privacy_tier', '!=', $tier->value)` with `->whereIn('privacy_tier', array_map(fn (EmailPrivacyTier $open): string => $open->value, $tier->moreOpenTiers()))`. Change the description copy to: `'Applied to mailboxes that follow the workspace default. A more open level applies to mail synced from now on. A more private level also applies to existing emails, except emails a member changed individually. Full access is set on each mailbox, never here.'`
- [ ] **Step 4: Run tests.** Same command. Expected: PASS.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 6: Commit.** `fix(email): stop a raised workspace default from widening past mail`

---

### Task 3.4: Verify and open the PR

- [ ] **Step 1:** Seed a second member of Acme Sales with their own mailbox and two synced emails, one linked to a person and one unlinked. A tinker-free path is a `LocalSeeder` entry or the composer. Sign in as the member at 1440 and 390px, light and dark. The linked email shows on its person's Emails tab. The unlinked one shows nowhere, and Rela's "list my teammates' emails" does not return it. Sign in as the owner and confirm both show on their own record tabs. Screenshots go to `.amp/in/artifacts/pr3-*.png`.
- [ ] **Step 2:** On the privacy settings page, raise the default and check that the new description renders in both themes.
- [ ] **Step 3:** Run the once-per-PR checks from the master plan, then push.

**PR checklist**
- [ ] No migration, so no rehearsal. The PR body shows `EXPLAIN ANALYZE` for one `VisibleEmailsQuery` page on the local seed, before and after. Pass: no sequential scan on `emailables`.
- [ ] Fixture sweep: every changed test links an email it expects to see. None has a weaker assertion.
- [ ] The PR body states plan notes 5 and 6.
- [ ] `tests/Feature/CRM/SurfaceParityTest.php` and `composer test:arch` pass.

---

# PR 10: sync health

**Branch:** `feat/email-sync-health`
**Goal:** A stored health state per mailbox, written every 15 minutes, read by a banner, a nav dot, notices and SystemAdmin. A failed store batch no longer looks like a fresh sync.

## File map

| File | Change | Owns |
|---|---|---|
| `packages/EmailIntegration/src/Jobs/IncrementalEmailSyncJob.php` | modify | `last_synced_at` moves only on success |
| `database/migrations/2026_10_31_100000_add_health_to_connected_accounts_table.php` | create | the four health columns |
| `packages/EmailIntegration/src/Enums/MailboxHealth.php` | create | cases, labels, remedies |
| `packages/EmailIntegration/src/Models/ConnectedAccount.php` | modify | columns, `assessedHealth()`, `healthReason()`, `unhealthy()`, `stale()`, `isStale()` |
| `database/factories/ConnectedAccountFactory.php` | modify | `health()` state |
| `packages/EmailIntegration/src/Actions/RecordMailboxHealth.php` | create | writes a change of health |
| `packages/EmailIntegration/src/Actions/ConnectAccountAction.php` | modify | a reconnect resets health |
| `packages/EmailIntegration/src/Console/Commands/CheckMailboxHealthCommand.php` | create | `email:check-mailbox-health` |
| `packages/EmailIntegration/src/EmailIntegrationServiceProvider.php` | modify | registers the command (line 181) |
| `bootstrap/app.php` | modify | schedule in the email block (383-412) |
| `packages/EmailIntegration/src/Notifications/MailboxHealthNotification.php` | create | owner and admin notice |
| `packages/EmailIntegration/resources/views/components/mailbox-health-banner.blade.php` | create | banner with Reconnect |
| `packages/EmailIntegration/src/Filament/Pages/EmailInboxPage.php`, `resources/views/filament/pages/email-inbox.blade.php` | modify | banner and nav dot |
| `packages/EmailIntegration/src/Filament/RelationManagers/BaseEmailsRelationManager.php`, `resources/views/filament/relation-managers/emails-relation-manager.blade.php` | modify | banner on record tabs |
| `lang/en/filament/pages/email-accounts.php`, `lang/en/filament/pages/email-inbox.php`, `lang/en/filament/notifications/mailbox-health.php` | modify, create | copy |
| `tests/Feature/EmailIntegration/IncrementalEmailSyncJobMicrosoftTest.php` | modify | the sync fix |
| `tests/Feature/EmailIntegration/MailboxHealthTest.php` | create | watchdog, notices, reconnect. No test covers health today |
| `tests/Feature/Commands/EmailIntegrationScheduleTest.php` | modify | schedule entry |
| `tests/Feature/SystemAdmin/ConnectedAccountResourceTest.php`, `tests/Feature/SystemAdmin/Overview/ProblemsStatsTest.php` | modify | read the column |
| `tests/Feature/EmailIntegration/EmailPageTabsTest.php`, `EmailsRelationManagerReadingTest.php` | modify | banner and dot |
| `tests/Browser/EmailIntegration/EmailAccountSyncErrorBrowserTest.php` | modify | banner in both themes and at 390px |

---

### Task 10.1: Stop a failed batch from moving `last_synced_at`

**Files:**
- Modify: `packages/EmailIntegration/src/Jobs/IncrementalEmailSyncJob.php` (lines 174-183)
- Modify: `tests/Feature/EmailIntegration/IncrementalEmailSyncJobMicrosoftTest.php` (`does not advance the cursor when the store batch fails`, near line 148)

**Interfaces:**
- Consumes: the batch `finally` callback.
- Produces: `last_synced_at` is the time of the last delta that fully stored, or was empty. Task 10.3 relies on this.

- [ ] **Step 1: Write the failing test.** In `does not advance the cursor when the store batch fails`, set `$account->forceFill(['last_synced_at' => now()->subDay()])->save();` after `syncableAccount()`, freeze time with `$this->travelTo(now()->startOfSecond())` at the top, and extend the final expectation:

```php
expect($account->refresh()->sync_cursor)->toBe('old-cursor')
    ->and($account->status)->toBe(EmailAccountStatus::ACTIVE)
    ->and($account->last_error)->toContain('1 email(s)')
    ->and($account->last_synced_at->equalTo(now()->subDay()))->toBeTrue();
```

- [ ] **Step 2: Run it.** `php artisan test --compact tests/Feature/EmailIntegration/IncrementalEmailSyncJobMicrosoftTest.php --filter='store batch fails'`. Expected: FAIL, because `last_synced_at` equals now.
- [ ] **Step 3: Implement.** In the `$batch->failedJobs > 0` branch, remove `'last_synced_at' => now(),` and keep `last_error`. Update the comment above it to two lines: the cursor and the sync time stay put, so the next run refetches what failed and the watchdog can see the stall.
- [ ] **Step 4: Run the file.** Expected: PASS.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 6: Commit.** `fix(email): keep the last sync time when a store batch fails`

---

### Task 10.2: Store a health state and let SystemAdmin read it

**Files:**
- Create: `database/migrations/2026_10_31_100000_add_health_to_connected_accounts_table.php`
- Create: `packages/EmailIntegration/src/Enums/MailboxHealth.php`
- Modify: `packages/EmailIntegration/src/Models/ConnectedAccount.php` (docblock 32-63, `#[Fillable]` 65-96, const 105, `stale()` 209-218, `isStale()` 358-366, casts 711-733)
- Modify: `database/factories/ConnectedAccountFactory.php`
- Modify: `tests/Feature/SystemAdmin/ConnectedAccountResourceTest.php` (50-71), `tests/Feature/SystemAdmin/Overview/ProblemsStatsTest.php` (297-328)

**Interfaces:**
- Consumes: nothing new.
- Produces: the contract columns `health` (`string(24)`, default `healthy`), `health_reason` (`string(64)`, nullable), `health_changed_at` and `health_notified_at` (timestamps, nullable). `MailboxHealth` has the contract cases `Healthy`, `Stale`, `ReauthRequired`, `Error` and `PausedBilling`, and implements `HasLabel` and `HasColor`. It also has `needsReconnect(): bool` (Stale, ReauthRequired, Error), `notifiesAdminsAtOnce(): bool` (PausedBilling) and `remedyUrl(Workspace $workspace): string` (Billing for PausedBilling, the accounts page otherwise, like `SendFailureRemedy::url()`). `ConnectedAccount::stale()` means `health = stale`. `#[Scope] unhealthy()` means health is not `healthy`. `isStale()` reads the column. `failing()` and `needingAttention()` keep their shape, so a billing pause is no longer counted as stale.

- [ ] **Step 1: Write the failing tests.** In `ConnectedAccountResourceTest.php`, build `$late` and `$neverSynced` with `->health(MailboxHealth::Stale)` instead of old `last_synced_at` values. Replace `keeps a mailbox that synced within the hour out of the attention list` with:

```php
it('keeps healthy and billing-paused mailboxes out of the attention list', function (): void {
    $healthy = syncedMailbox(['last_synced_at' => now()->subDays(3)]);
    $paused = ConnectedAccount::factory()->health(MailboxHealth::PausedBilling)->create(['sync_cursor' => 'cursor', 'last_synced_at' => now()->subDays(9)]);
    $stale = ConnectedAccount::factory()->health(MailboxHealth::Stale)->create(['sync_cursor' => 'cursor']);

    livewire(ListConnectedAccounts::class)
        ->filterTable('needs_attention')
        ->assertCanSeeTableRecords([$stale])
        ->assertCanNotSeeTableRecords([$healthy, $paused]);
});
```

In `ProblemsStatsTest.php`, build the two `$late` mailboxes with `->health(MailboxHealth::Stale)`.

- [ ] **Step 2: Run them.** `php artisan test --compact tests/Feature/SystemAdmin/ConnectedAccountResourceTest.php tests/Feature/SystemAdmin/Overview/ProblemsStatsTest.php`. Expected: FAIL, because `MailboxHealth` and the `health` column do not exist.
- [ ] **Step 3: Write the migration.** Use `php artisan make:migration add_health_to_connected_accounts_table --no-interaction`, then:

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->string('health', 24)->default('healthy');
            $table->string('health_reason', 64)->nullable();
            $table->timestamp('health_changed_at')->nullable();
            $table->timestamp('health_notified_at')->nullable();
        });
    }
};
```

- [ ] **Step 4: Implement the enum and the model.**

```php
enum MailboxHealth: string implements HasColor, HasLabel
{
    case Healthy = 'healthy';
    case Stale = 'stale';
    case ReauthRequired = 'reauth_required';
    case Error = 'error';
    case PausedBilling = 'paused_billing';

    // getLabel(): __('filament/pages/email-accounts.health.labels.'.$this->value)
    // getColor(): Healthy success, Stale and PausedBilling warning, ReauthRequired and Error danger

    public function needsReconnect(): bool
    {
        return match ($this) {
            self::Stale, self::ReauthRequired, self::Error => true,
            self::Healthy, self::PausedBilling => false,
        };
    }

    public function notifiesAdminsAtOnce(): bool
    {
        return $this === self::PausedBilling;
    }

    public function remedyUrl(Workspace $workspace): string
    {
        return $this === self::PausedBilling
            ? Billing::getUrl(panel: 'app', tenant: $workspace)
            : EmailAccountsPage::getUrl(panel: 'app', tenant: $workspace);
    }
}
```

On `ConnectedAccount`, add the four columns to the docblock and `#[Fillable]`, and add the casts `'health' => MailboxHealth::class`, `'health_changed_at' => 'datetime'` and `'health_notified_at' => 'datetime'`. Replace `STALE_AFTER_MINUTES = 60` with `STALE_AFTER_HOURS = 24`. Then:

```php
#[Scope]
protected function stale(Builder $query): Builder
{
    return $query->where('health', MailboxHealth::Stale);
}

#[Scope]
protected function unhealthy(Builder $query): Builder
{
    return $query->whereNot('health', MailboxHealth::Healthy);
}

public function isStale(): bool
{
    return $this->health === MailboxHealth::Stale;
}

public function assessedHealth(bool $workspaceHasHostedAccess): MailboxHealth
{
    return match (true) {
        ! $workspaceHasHostedAccess => MailboxHealth::PausedBilling,
        $this->status === EmailAccountStatus::REAUTH_REQUIRED => MailboxHealth::ReauthRequired,
        $this->status === EmailAccountStatus::ERROR => MailboxHealth::Error,
        $this->sync_cursor !== null && ($this->last_synced_at === null || $this->last_synced_at->lt(now()->subHours(self::STALE_AFTER_HOURS))) => MailboxHealth::Stale,
        default => MailboxHealth::Healthy,
    };
}

public function healthReason(MailboxHealth $health): ?string
{
    return match ($health) {
        MailboxHealth::Stale => 'no_sync_24h',
        MailboxHealth::Error, MailboxHealth::ReauthRequired => SyncErrorReason::tryFrom((string) $this->last_error)?->value,
        MailboxHealth::Healthy, MailboxHealth::PausedBilling => null,
    };
}
```

Billing comes first because no reconnect helps while `syncable()` skips the workspace (`ConnectedAccount.php:154-158`). A null `sync_cursor` means the history import is still running, and the stale rule skips it, as `stale()` did. Add the factory state `health(MailboxHealth $health)`, which sets `health` and `health_changed_at => now()`. Add the `health.labels.*` lang keys.
- [ ] **Step 5: Run tests.** `php artisan migrate --no-interaction`, then the step 2 command. Expected: PASS. Then `grep -rn "isStale\|->stale()\|needingAttention" packages/SystemAdmin` and confirm each caller still reads correctly. `packages/SystemAdmin` is outside PHPStan.
- [ ] **Step 6:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 7: Commit.** `feat(email): store a health state on every connected mailbox`

---

### Task 10.3: The watchdog command, its schedule and the reconnect reset

**Files:**
- Create: `packages/EmailIntegration/src/Actions/RecordMailboxHealth.php`
- Create: `packages/EmailIntegration/src/Console/Commands/CheckMailboxHealthCommand.php`
- Modify: `packages/EmailIntegration/src/EmailIntegrationServiceProvider.php` (`registerCommands()`, line 181)
- Modify: `bootstrap/app.php` (inside `if (Feature::for(null)->active(EmailIntegration::class))`, after `email:expire-access-requests` at 407-411)
- Modify: `packages/EmailIntegration/src/Actions/ConnectAccountAction.php` (constructor, and after `updateOrCreate` near line 78)
- Create: `tests/Feature/EmailIntegration/MailboxHealthTest.php`
- Modify: `tests/Feature/Commands/EmailIntegrationScheduleTest.php`

**Interfaces:**
- Consumes: `ConnectedAccount::assessedHealth()`, `healthReason()`, `Workspace::withHostedAccess()` (`Workspace.php:482-489`).
- Produces: `RecordMailboxHealth::execute(ConnectedAccount $account, MailboxHealth $health, ?string $reason = null): void`, which is idempotent. It moves `health_changed_at` only when the state changes. It has no acting user, because its callers are the scheduler and the OAuth callback. `email:check-mailbox-health` runs `everyFifteenMinutes()->withoutOverlapping()->onOneServer()`. A reconnect that is not waiting on a running import sets health to Healthy at once, so the banner goes away without waiting up to 15 minutes. The command reads no workspace-scoped model, so it needs no `CurrentWorkspace::within()`.

- [ ] **Step 1: Write the failing tests.**

```php
<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Features\Billing;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Actions\ConnectAccountAction;
use Relaticle\EmailIntegration\Actions\RecordMailboxHealth;
use Relaticle\EmailIntegration\Console\Commands\CheckMailboxHealthCommand;
use Relaticle\EmailIntegration\Data\ConnectAccountData;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\MailboxHealth;
use Relaticle\EmailIntegration\Enums\SyncErrorReason;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Notifications\MailboxHealthNotification;
use Tests\Helpers\BillingStatusFixture;

mutates(CheckMailboxHealthCommand::class, RecordMailboxHealth::class, ConnectedAccount::class, MailboxHealth::class, MailboxHealthNotification::class);

beforeEach(function (): void {
    $this->travelTo(now()->startOfMinute());
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
});

function healthMailbox(User $owner, array $attributes = []): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $owner->current_workspace_id, 'user_id' => $owner->id,
        'sync_cursor' => 'cursor', 'last_synced_at' => now()->subMinutes(5), ...$attributes,
    ]));
}

it('marks an active mailbox stale after a day without a successful sync, and not before', function (): void {
    $quiet = healthMailbox($this->owner, ['last_synced_at' => now()->subHours(23), 'email_address' => 'quiet@acme.test']);
    $stalled = healthMailbox($this->owner, ['last_synced_at' => now()->subHours(25)]);
    $importing = healthMailbox($this->owner, ['sync_cursor' => null, 'last_synced_at' => null, 'email_address' => 'new@acme.test']);

    $this->artisan('email:check-mailbox-health')->assertSuccessful();

    expect($quiet->fresh()->health)->toBe(MailboxHealth::Healthy)
        ->and($stalled->fresh()->health)->toBe(MailboxHealth::Stale)
        ->and($stalled->fresh()->health_reason)->toBe('no_sync_24h')
        ->and($stalled->fresh()->health_changed_at->equalTo(now()))->toBeTrue()
        ->and($importing->fresh()->health)->toBe(MailboxHealth::Healthy);
});

it('takes reconnect and error states from the mailbox status', function (): void {
    $reauth = healthMailbox($this->owner, ['status' => EmailAccountStatus::REAUTH_REQUIRED]);
    $error = healthMailbox($this->owner, ['status' => EmailAccountStatus::ERROR, 'last_error' => SyncErrorReason::GoogleMailDisabled->value, 'email_address' => 'b@acme.test']);

    $this->artisan('email:check-mailbox-health')->assertSuccessful();

    expect($reauth->fresh()->health)->toBe(MailboxHealth::ReauthRequired)
        ->and($error->fresh()->health)->toBe(MailboxHealth::Error)
        ->and($error->fresh()->health_reason)->toBe(SyncErrorReason::GoogleMailDisabled->value);
});

it('reports a mailbox in a workspace without paid access as paused, never stale', function (): void {
    Feature::define(Billing::class, true);
    $workspace = BillingStatusFixture::workspace();
    $paused = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $workspace->user_id,
        'sync_cursor' => 'cursor', 'last_synced_at' => now()->subDays(15),
    ]));

    $this->artisan('email:check-mailbox-health')->assertSuccessful();

    expect($paused->fresh()->health)->toBe(MailboxHealth::PausedBilling)
        ->and(ConnectedAccount::query()->stale()->whereKey($paused->id)->exists())->toBeFalse();
});

it('returns a reconnected mailbox to healthy at once', function (): void {
    $account = healthMailbox($this->owner, ['status' => EmailAccountStatus::REAUTH_REQUIRED]);
    $this->artisan('email:check-mailbox-health');
    Bus::fake();

    resolve(ConnectAccountAction::class)->execute(new ConnectAccountData(
        userId: (string) $this->owner->id, workspaceId: (string) $this->workspace->id,
        provider: $account->provider->value, emailAddress: $account->email_address,
        displayName: $account->display_name, providerAccountId: $account->provider_account_id,
        accessToken: 'new-access', refreshToken: 'new-refresh', tokenExpiresAt: now()->addHour(),
        hasCalendar: false, hasSend: true,
    ));

    expect($account->fresh()->health)->toBe(MailboxHealth::Healthy)
        ->and($account->fresh()->health_reason)->toBeNull();
});
```

In `EmailIntegrationScheduleTest.php`, add `->expectsOutputToContain('email:check-mailbox-health')` to the first test. In the second test, add `|| str_contains($haystack, 'email:check-mailbox-health')` to the filter and change `toHaveCount(4)` to `toHaveCount(5)`.

- [ ] **Step 2: Run them.** `php artisan test --compact tests/Feature/EmailIntegration/MailboxHealthTest.php tests/Feature/Commands/EmailIntegrationScheduleTest.php`. Expected: FAIL, because the command is not defined.
- [ ] **Step 3: Implement.**

```php
final readonly class RecordMailboxHealth
{
    public function execute(ConnectedAccount $account, MailboxHealth $health, ?string $reason = null): void
    {
        if ($account->health === $health && $account->health_reason === $reason) {
            return;
        }

        $account->forceFill([
            'health_changed_at' => $account->health === $health ? $account->health_changed_at : now(),
            'health' => $health,
            'health_reason' => $reason,
        ])->save();
    }
}
```

```php
#[Description('Store each connected mailbox health and send the notices it calls for.')]
#[Signature('email:check-mailbox-health')]
final class CheckMailboxHealthCommand extends Command
{
    public function handle(RecordMailboxHealth $recordMailboxHealth): int
    {
        ConnectedAccount::query()
            ->connected()
            ->with(['user', 'workspace'])
            ->withExists(['workspace as workspace_has_hosted_access' => fn (Builder $workspace): Builder => $workspace->withHostedAccess()])
            ->eachById(function (ConnectedAccount $account) use ($recordMailboxHealth): void {
                $health = $account->assessedHealth((bool) $account->getAttribute('workspace_has_hosted_access'));

                $recordMailboxHealth->execute($account, $health, $account->healthReason($health));
            });

        return self::SUCCESS;
    }
}
```

Register it next to `IncrementalEmailSyncCommand`, and schedule it:

```php
$schedule->command('email:check-mailbox-health')
    ->everyFifteenMinutes()
    ->name('email:check-mailbox-health')
    ->withoutOverlapping()
    ->onOneServer();
```

In `ConnectAccountAction`, inject `RecordMailboxHealth`, and after `updateOrCreate` add `if (! $importStillDraining) { $this->recordMailboxHealth->execute($account, MailboxHealth::Healthy); }`.
- [ ] **Step 4: Run tests.** Same command, plus `tests/Feature/EmailIntegration/EmailAccountsPageTest.php` and `MicrosoftAccountConnectionTest.php`. Expected: PASS.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 6: Commit.** `feat(email): check mailbox health every fifteen minutes`

---

### Task 10.4: Tell the owner at once and the admins later

**Files:**
- Create: `packages/EmailIntegration/src/Notifications/MailboxHealthNotification.php`
- Create: `lang/en/filament/notifications/mailbox-health.php`
- Modify: `packages/EmailIntegration/src/Console/Commands/CheckMailboxHealthCommand.php`
- Modify: `tests/Feature/EmailIntegration/MailboxHealthTest.php`

**Interfaces:**
- Consumes: `App\Notifications\InboxNotification` (`InboxNotification.php:23-88`). It sends by inbox and mail when `toMail()` exists.
- Produces: `MailboxHealthNotification::__construct(ConnectedAccount $account, bool $forAdmins)`. Facts: `health`, `mailbox`, `owner`, `for_admins`. Group key `mailbox-health:{account id}`. The owner's notice links to `remedyUrl()`. The admins' notice links only for `PausedBilling`, because only the owner can grant OAuth consent. The rules are:
  - On a change into any unhealthy state, the owner is told once.
  - Admins (`EmailManage`, never the owner a second time) are told once, 72 hours after the change.
  - For `PausedBilling`, admins are told at once.
  - `health_notified_at` stops repeats.

- [ ] **Step 1: Write the failing tests.** Add to `MailboxHealthTest.php`, with `Notification::fake()` at the top of each:

```php
it('tells workspace admins only after the mailbox has been unhealthy for three days', function (): void {
    Notification::fake();
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    healthMailbox($member, ['status' => EmailAccountStatus::REAUTH_REQUIRED]);

    $this->artisan('email:check-mailbox-health');
    $this->travel(71)->hours();
    $this->artisan('email:check-mailbox-health');

    Notification::assertNotSentTo($admin, MailboxHealthNotification::class);

    $this->travel(2)->hours();
    $this->artisan('email:check-mailbox-health');
    $this->travel(15)->minutes();
    $this->artisan('email:check-mailbox-health');

    Notification::assertSentToTimes($admin, MailboxHealthNotification::class, 1);
    Notification::assertSentToTimes($this->owner, MailboxHealthNotification::class, 1);
    Notification::assertSentToTimes($member, MailboxHealthNotification::class, 1);
});

it('tells admins at once when billing pauses a mailbox', function (): void {
    Notification::fake();
    Feature::define(Billing::class, true);
    $workspace = BillingStatusFixture::workspace();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    $workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $member->id, 'sync_cursor' => 'cursor',
    ]));

    $this->artisan('email:check-mailbox-health');

    Notification::assertSentTo($member, MailboxHealthNotification::class, fn (MailboxHealthNotification $notice): bool => $notice->facts()['for_admins'] === false);
    Notification::assertSentTo($workspace->owner, MailboxHealthNotification::class, fn (MailboxHealthNotification $notice): bool => $notice->facts()['for_admins'] === true);
});

it('tells the owner again after a recovery and a new failure', function (): void {
    Notification::fake();
    $account = healthMailbox($this->owner, ['status' => EmailAccountStatus::ERROR]);
    $this->artisan('email:check-mailbox-health');

    $account->update(['status' => EmailAccountStatus::ACTIVE, 'last_synced_at' => now()]);
    $this->travel(15)->minutes();
    $this->artisan('email:check-mailbox-health');
    $account->update(['status' => EmailAccountStatus::ERROR]);
    $this->travel(15)->minutes();
    $this->artisan('email:check-mailbox-health');

    Notification::assertSentToTimes($this->owner, MailboxHealthNotification::class, 2);
});
```

The admin test uses a member's mailbox, so the workspace owner (an admin) is also told after 72 hours. That catches an implementation that skips every admin, and one that tells admins on every run.

- [ ] **Step 2: Run them.** `php artisan test --compact tests/Feature/EmailIntegration/MailboxHealthTest.php`. Expected: FAIL, because the notification class does not exist.
- [ ] **Step 3: Implement.** Shape the notification after `EmailSendFailedNotification` (`EmailSendFailedNotification.php:17-118`). Its `present()` builds the title and body from `mailbox-health.{health}.{owner|admin}.title|body`, uses `->danger()` for ReauthRequired and Error and `->warning()` otherwise, and adds one action labelled `mailbox-health.actions.{reconnect|billing}`. `toMail()` uses the same keys. After `$recordMailboxHealth->execute(...)` in the command, call `$this->sendDueNotices($account)`:

```php
private const int ADMIN_NOTICE_AFTER_HOURS = 72;

private function sendDueNotices(ConnectedAccount $account): void
{
    $changedAt = $account->health_changed_at;

    if ($account->health === MailboxHealth::Healthy || $changedAt === null || ! $account->user instanceof User) {
        return;
    }

    $notifiedAt = $account->health_notified_at;
    $ownerDue = $notifiedAt === null || $notifiedAt->lt($changedAt);
    $adminsDue = $account->health->notifiesAdminsAtOnce()
        ? $ownerDue
        : $changedAt->lte(now()->subHours(self::ADMIN_NOTICE_AFTER_HOURS))
            && ($notifiedAt === null || $notifiedAt->lt($changedAt->addHours(self::ADMIN_NOTICE_AFTER_HOURS)));

    if ($ownerDue) {
        $account->user->notify(new MailboxHealthNotification($account, forAdmins: false));
    }

    if ($adminsDue) {
        Notification::send($this->adminsOf($account), new MailboxHealthNotification($account, forAdmins: true));
    }

    if ($ownerDue || $adminsDue) {
        $account->forceFill(['health_notified_at' => now()])->save();
    }
}

/** @return Collection<int, User> */
private function adminsOf(ConnectedAccount $account): Collection
{
    return $account->workspace->allUsers()
        ->reject(fn (User $user): bool => $user->is($account->user))
        ->filter(fn (User $user): bool => $user->hasWorkspaceCapability((string) $account->workspace_id, WorkspaceCapability::EmailManage))
        ->values();
}
```

Copy, one line each, no em-dash. Owner Stale: "Your mailbox :mailbox has not synced for a day. Reconnect it to bring in new mail." Owner ReauthRequired: "Reconnect :mailbox. Relaticle lost access to it." Owner PausedBilling: "Mail sync for :mailbox is paused until billing is resumed." Admin versions name `:owner`.
- [ ] **Step 4: Run tests.** Same command. Expected: PASS.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 6: Commit.** `feat(email): notify mailbox owners, then admins, when sync stops`

---

### Task 10.5: Banner on the Emails page and record tabs, dot on the nav item

**Files:**
- Create: `packages/EmailIntegration/resources/views/components/mailbox-health-banner.blade.php`
- Modify: `packages/EmailIntegration/src/Filament/Pages/EmailInboxPage.php`, `resources/views/filament/pages/email-inbox.blade.php`
- Modify: `packages/EmailIntegration/src/Filament/RelationManagers/BaseEmailsRelationManager.php`, `resources/views/filament/relation-managers/emails-relation-manager.blade.php` (top of the `@else` branch at line 18)
- Modify: `lang/en/filament/pages/email-accounts.php` (`health.banner.*`), `lang/en/filament/pages/email-inbox.php` (`health.nav_badge`, `health.nav_tooltip`)
- Modify: `tests/Feature/EmailIntegration/EmailPageTabsTest.php`, `EmailsRelationManagerReadingTest.php`, `tests/Browser/EmailIntegration/EmailAccountSyncErrorBrowserTest.php`

**Interfaces:**
- Consumes: `ConnectedAccount::ownedBy($user, $workspace)->connected()->unhealthy()`, `MailboxOAuthWorkspace::redirectUrl()` (as in `HasConnectedAccountActions.php:101-113`), `MailboxHealth::needsReconnect()`.
- Produces: `#[Computed] unhealthyMailboxes(): Collection<int, ConnectedAccount>` on `EmailInboxPage` and on `BaseEmailsRelationManager`. They list the viewer's own mailboxes only. `<x-email-integration::mailbox-health-banner :mailboxes>` renders one `role="status"` row per mailbox with `data-mailbox-health="<value>"` and Reconnect where `needsReconnect()`. `EmailInboxPage::getNavigationBadge()` returns `__('filament/pages/email-inbox.health.nav_badge')` while any own mailbox is unhealthy. Its colour is `danger`, with a tooltip.

- [ ] **Step 1: Write the failing tests.** Import `Relaticle\EmailIntegration\Enums\MailboxHealth` in each file. In `EmailPageTabsTest.php`:

```php
it('warns the viewer about their own mailbox that needs reconnecting, with a reconnect link', function (): void {
    $this->account->forceFill(['health' => MailboxHealth::ReauthRequired, 'health_changed_at' => now()])->save();

    Livewire::test(EmailInboxPage::class)
        ->assertSeeHtml('data-mailbox-health="reauth_required"')
        ->assertSee(__('filament/pages/email-accounts.actions.reconnect'));

    expect(EmailInboxPage::getNavigationBadge())->toBe(__('filament/pages/email-inbox.health.nav_badge'));
});

it('shows no banner or dot for a teammate unhealthy mailbox or a paused one without reconnect', function (): void {
    $teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($teammate, ['role' => 'member']);
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->health(MailboxHealth::Error)->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $teammate->id,
    ]));

    Livewire::test(EmailInboxPage::class)->assertDontSeeHtml('data-mailbox-health');
    expect(EmailInboxPage::getNavigationBadge())->toBeNull();

    $this->account->forceFill(['health' => MailboxHealth::PausedBilling, 'health_changed_at' => now()])->save();

    Livewire::test(EmailInboxPage::class)
        ->assertSeeHtml('data-mailbox-health="paused_billing"')
        ->assertDontSee(__('filament/pages/email-accounts.actions.reconnect'));
});
```

In `EmailsRelationManagerReadingTest.php`:

```php
it('warns on the record tab about the viewer own stale mailbox', function (): void {
    $this->account->forceFill(['health' => MailboxHealth::Stale, 'health_changed_at' => now(), 'last_synced_at' => now()->subDays(2)])->save();

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->person, 'pageClass' => ViewPeople::class])
        ->assertSeeHtml('data-mailbox-health="stale"');

    $this->actingAs($this->viewer);

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->person, 'pageClass' => ViewPeople::class])
        ->assertDontSeeHtml('data-mailbox-health');
});
```

In `EmailAccountSyncErrorBrowserTest.php`, add a test with `->with(['inLightMode', 'inDarkMode'])`. It sets the mailbox to `ReauthRequired`, visits `/app/{slug}/email`, asserts `[data-mailbox-health="reauth_required"]` is visible and a Reconnect link points at the OAuth redirect, then resizes to 390x844 and asserts the banner does not overflow (`document.documentElement.scrollWidth <= document.documentElement.clientWidth`). It ends with a screenshot.
- [ ] **Step 2: Run them.** `php artisan test --compact tests/Feature/EmailIntegration/EmailPageTabsTest.php tests/Feature/EmailIntegration/EmailsRelationManagerReadingTest.php`. Expected: FAIL, because no banner and no badge exist.
- [ ] **Step 3: Implement.** Both computed properties return `ConnectedAccount::query()->ownedBy($user, $workspace)->connected()->unhealthy()->with('workspace')->get()`, or an empty collection without a tenant. `getNavigationBadge()` uses the same chain with `->exists()`. The banner uses warning tokens (`bg-warning-50 text-warning-800 dark:bg-warning-500/10 dark:text-warning-200`), a fill status icon (`ri-error-warning-fill`) per `ui.md`, and copy from `email-accounts.health.banner.{value}` with `:mailbox` and `:ago` (`last_synced_at?->diffForHumans()`). Render it above the tabs in `email-inbox.blade.php` and above the search bar in the relation manager view.
- [ ] **Step 4: Run tests, then the browser file in Chromium and WebKit.** `php artisan test --compact tests/Browser/EmailIntegration/EmailAccountSyncErrorBrowserTest.php`, then the same with `--browser safari`. Expected: PASS.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 6: Commit.** `feat(email): show a reconnect banner and nav dot for an unhealthy mailbox`

---

### Task 10.6: Rehearse the migration

The migration touches `connected_accounts`, so it is rehearsed per core.md, Database.

- [ ] **Step 1: Export, read-only.** Export `pg_dump -s` and `pg_dump -a -t migrations`. Then `\copy (select id, workspace_id, user_id, provider, 'mailbox-' || row_number() over () || '@example.test' as email_address, null as display_name, '' as access_token, null as refresh_token, sync_cursor, last_synced_at, status, case when last_error like 'google.%' then last_error else null end as last_error, deleted_at, created_at, updated_at from connected_accounts) to 'connected_accounts.csv' csv header`. Names, addresses, tokens and free-text errors do not leave production.
- [ ] **Step 2: Load the scratch database.** Run `createdb relaticle_rehearsal`, load the schema, the migrations rows and the CSV with `set session_replication_role = replica`. Save the before-state: `select count(*), status from connected_accounts group by status`.
- [ ] **Step 3: Migrate.** `DB_DATABASE=relaticle_rehearsal php artisan migrate --force`. Confirm only `add_health_to_connected_accounts_table` ran. Record the run time and the lock time from `log_lock_waits`, or time the `ALTER` directly. Postgres 17 adds a column with a constant default without rewriting, so expect milliseconds. The after-state must show the same row counts, every row `health = 'healthy'`, and the other columns unchanged.
- [ ] **Step 4: Predict the first watchdog run, read-only.** Use one aggregate on production (or on the scratch copy plus a `workspaces` billing export): the count per would-be state, using `assessedHealth()`'s rules in SQL. Expect about 3 ReauthRequired or Error and at least 1 Stale (spec, "What is wrong today"). Every one of those owners gets one notice on the first run after deploy. Say so in the PR body.
- [ ] **Step 5:** Run `migrate` again to prove it does nothing. Drop the scratch database and delete the CSV.

---

### Task 10.7: Verify and open the PR

- [ ] **Step 1:** Run `amp orb services ensure`. As `owner@relaticle.test`, set one seeded mailbox to each unhealthy state. Use `php artisan email:check-mailbox-health` after backdating `last_synced_at` for Stale, and the `past-due@relaticle.test` workspace for PausedBilling. Check at 1440 and 390px, light and dark: the Emails page banner and nav dot, a person's Emails tab banner, the inbox notice in the sidebar with its action, and the SystemAdmin mailbox list "needs attention" filter as `sysadmin@relaticle.com`. Screenshots go to `.amp/in/artifacts/pr10-*.png`, including a healthy page with no banner.
- [ ] **Step 2:** Run Horizon with `QUEUE_CONNECTION=redis`. Check that the queued notifications reach `storage/logs` with `MAIL_MAILER=log`.
- [ ] **Step 3:** Run the once-per-PR checks from the master plan, then push.

**PR checklist**
- [ ] The rehearsal notes are in the PR body: row counts before and after, run time, lock time, and the predicted first-run notice count.
- [ ] `MailboxHealth` is a new enum, and SystemAdmin has no `match` over it. `grep -rn "MailboxHealth" packages/SystemAdmin` returns nothing, or each hit is exhaustive.
- [ ] `tests/Feature/Commands/ScheduledTasksRunOnOneServerTest.php` passes.
- [ ] The browser tests pass in Chromium and `--browser safari`. Screenshots are in the body.
- [ ] The PR body states plan notes 7, 8 and 9.
- [ ] Findings 2 and 3 are marked closed in `docs/email/findings.md` in the PR.

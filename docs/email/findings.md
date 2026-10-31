# Email findings: bugs and inconsistencies

Each finding says how it was confirmed:

- **Seen**: reproduced in the browser on 2026-10-31.
- **Code**: read in the source. The behaviour follows from it but was not clicked through.
- **Data**: production aggregate.
- **Inferred**: likely from the code, not reproduced.

Priorities:

- **P0**: wrong output a customer sees today.
- **P1**: a visible quality gap, cheap to fix.
- **P2**: cleanup.

Paths are relative to `packages/EmailIntegration/` unless they start with `app/`.

## P0

| # | Finding | Evidence | Fix |
|---|---|---|---|
| 1 | Replying to a thread whose subject already starts with "Re:" produces "Re: Re: ..." | Seen. `src/Livewire/EmailComposer.php:322` always prepends `Re: ` or `Fwd: ` | **Closed in PR 1.** Skip the prefix when the subject already carries one, case-insensitively. Cover `Re`, `RE`, `Fwd`, `FW`, `Aw`, `Sv`, `Wg`. Add one regression test through the composer |
| 2 | An `active` mailbox can stop syncing for days with no error. One production mailbox shows 375 hours since `last_synced_at` | Data | Watchdog marks a mailbox degraded after 24 h without a sync. Show it on the Emails page and the nav item (decision 26) |
| 3 | 3 of 32 mailboxes sit in `error` or `reauth_required`, visible only on the accounts page | Data, Code (`resources/views/filament/pages/email-accounts.blade.php`) | Banner with Reconnect on the Emails page and the record Emails tabs |
| 4 | The import-complete notification's "Open inbox" button leads to a page that shows no received mail, only an empty Drafts table | Code, `src/Notifications/MailboxHistoryImportCompletedNotification.php:162`; Seen (page opens on Drafts) | Point it at the Conversations list once it exists (decision 1). Until then, point it at the People list sorted by last interaction |

## P1

| # | Finding | Evidence | Fix |
|---|---|---|---|
| 5 | The email body renders in the browser default serif font, while the app around it uses the app font | Seen (light and dark) | **Closed in PR 1.** Inject a base stylesheet into the iframe `srcdoc`: system font stack, 14px, 1.6 line height. Apply it only where the email sets no font of its own |
| 6 | In dark mode the body is a hard white block with text touching its left edge | Seen | **Closed in PR 1.** Give the iframe document body padding. For plain-text emails, render the text in the app theme instead of a white frame |
| 7 | The reader repeats the snippet as a grey line under the subject, then shows the same text in the body | Seen; `resources/views/components/email-view.blade.php:138-147` | **Closed in PR 1.** Remove the snippet line from the reader. Keep it in list rows |
| 8 | The unread state is loaded but never shown, so unread mail looks like read mail | Code, `src/Filament/RelationManagers/BaseEmailsRelationManager.php:105` vs `resources/views/components/list-row-wide.blade.php`; Seen | Bold the sender and subject and add a dot (decision 7) |
| 9 | The record Emails tab lists one row per message. Five conversations rendered as 13 rows | Seen; `BaseEmailsRelationManager.php:118` | Thread rows (decision 5) |
| 10 | The Outbox shows a removable filter chip labelled "Status tab: Queued" | Seen; `src/Livewire/OutboxTable.php:182` has no `->label()` | **Closed in PR 1.** Label the filter. Or better, render the statuses as segmented tabs and drop the filter chip |
| 11 | Outbox row actions and several columns have no translated labels: `cancel`, `reschedule`, `retry`, `subject`, `status`, `last_error`. Templates `name` and `subject` columns likewise | Code, `OutboxTable.php:79,89,105` and the column definitions; `src/Filament/Resources/EmailTemplateResource.php` | **Closed in PR 1.** Add `__()` labels. The PHPStan i18n rules miss Filament's auto-generated labels. PR 1 labelled the Outbox, then the Templates columns and actions |
| 12 | With no mailbox connected, the page shows only a connect card. Drafts and Templates disappear, though both work without a mailbox | Seen; `resources/views/filament/pages/email-inbox.blade.php:2` | **Closed in PR 1.** Show the connect card above the tabs (decision 27) |
| 13 | At 390px the record tab bar clips the active `Emails` tab off screen | Seen | **Closed in PR 1.** Scroll the active tab into view on load, or let the tab bar overflow into a "More" menu |
| 14 | Reading an email hides the list behind a modal. Moving to the next email takes close plus click | Seen; `resources/views/components/reader-overlay.blade.php:42` | Drawer or split pane with `j/k` (decision 3) |
| 15 | The record tab's reader cannot be linked to; refresh closes it. A panel-wide `?email=` link exists (`Email.php:449-456`) but opens on the Dashboard | Code, `BaseEmailsRelationManager.php:51` | Resolver route plus reader id in each host page URL (decision 4) |
| 16 | Share, Summarize and Request access are unlabelled icons in the reader header. Production shows 0 shares, 0 requests and 2 summaries ever | Seen; Data | Label the primary action. Put the rest in a labelled overflow with shortcut hints |
| 17 | Rows the viewer cannot read do not open. They show a "Request access from {owner}" button (`list-row-wide.blade.php:177-183`), but the reader's privacy gate is unreachable, and it prints the snippet at subject access (`email-view.blade.php:138`) | Code | Open the reader at the metadata gate with a labelled Request access button (decision 9) |
| 18 | The Outbox tab badge counts every queued email. The default "Queued" filter hides future scheduled sends, so the badge can show 3 over an empty list | Code, `src/Filament/Pages/EmailInboxPage.php:118-122` vs `OutboxTable.php:198-204` | **Closed in PR 1.** Count what the default view shows, or show Scheduled by default |
| 19 | The Outbox has no default sort | Code, `OutboxTable.php` has no `defaultSort` | **Closed in PR 1.** Sort by `scheduled_for`, soonest first |
| 20 | No way to read a queued or failed email's content from the Outbox | Code | Row click opens the email in the reader with Cancel, Edit and Retry |

## P2

| # | Finding | Evidence | Fix |
|---|---|---|---|
| 21 | `category-badge.blade.php` keeps its own colour map, which disagrees with `EmailCategory::getColor()`: Invoice is red there, Support green. It also lists `Notifications` and `News`, which are not enum cases | Code, `resources/views/components/category-badge.blade.php:4-13` vs `src/Enums/EmailCategory.php` | **Closed in PR 1.** The enum owns the colour; the badge reads it (`architecture.md`, "One fact, one owner") |
| 22 | `EmailCategory::getLabel()` returns the raw English value, and `EmailFolder` labels are hardcoded | Code, `src/Enums/EmailCategory.php`, `src/Enums/EmailFolder.php:16-25` | Translate through `__()` |
| 23 | Orphan translation keys left from the removed inbox: `account_filter`, `list_empty.*`, `row_actions`, `mark_all_read`, `compose.*` and others | Code, `lang/en/filament/pages/email-inbox.php` | Delete the unused keys, or reuse them for the Conversations list |
| 24 | On small screens the reader's back arrow calls `deselectEmail` directly. The close button and `Esc` first await the inline composer's `close()` so the draft is saved; the back arrow does not | Inferred from `email-view.blade.php:104-113` and `reader-overlay.blade.php:4-19`. Not reproduced | Route the back arrow through the same `closeReader()` |
| 25 | The participant popover never shows Bcc, even to the sender who wrote it | Code, `email-view.blade.php` popover | Show Bcc to the mailbox owner only |
| 26 | "Summarize thread" summarises a conversation the reader never shows; the reader renders one message | Code | Thread reader (decision 3) |
| 27 | Four tab-count queries run on every page request, including tab switches | Code, `EmailInboxPage.php:106-135` | Cache the counts and update them from the events that change them |
| 28 | Search is `ILIKE '%term%'` with correlated visibility subqueries and no index. The largest mailbox holds 156,632 emails | Code, `src/Services/EmailSearchService.php:24-58`; Data | Postgres full-text search (decision 13) |
| 29 | `CancelQueuedEmailAction` and `RetryFailedEmailAction` are called without a user, so the table query scope is the only ownership check | Code, `OutboxTable.php:85,110,124`; neither action takes or checks a user | The action checks the acting user, as `architecture.md` requires for actions |
| 30 | 37% of synced mail is Marketing, and 24,007 Marketing emails appear on company and person tabs | Data | Hide promotional mail on record tabs by default (decision 8) |

## Checked and not a bug

- **The no-mailbox card shows only "Connect Google account".** The Microsoft button
  hides when Azure credentials are not configured
  (`ConnectMailboxAction::microsoft()` checks `isConfigured()`). It shows in production.
- **`AccessRequestsTable` is in use.** It renders on the access requests settings page
  (`resources/views/filament/pages/email-access-requests.blade.php:4`).

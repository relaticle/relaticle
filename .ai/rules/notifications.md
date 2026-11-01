---
paths:
  - 'app/Notifications/**'
  - 'app/Livewire/App/AppDatabaseNotifications.php'
  - 'app/Contracts/Inbox/**'
  - 'app/Support/Inbox/**'
  - 'resources/views/livewire/app/database-notifications.blade.php'
  - 'resources/views/livewire/app/notifications/**'
  - 'packages/*/src/Notifications/**'
  - 'packages/*/src/Inbox/**'
  - 'packages/Cloud/src/**/Notifications/**'
---

# Notifications and requests

## Every in-app notice extends the inbox base

A notification that writes a bell row extends `App\Notifications\InboxNotification`. The row
stores facts, and the class's static `present()` renders them when the panel reads the row, so
translations and renamed workspace slugs reach old rows too. `InboxChannel` throws for any other
database notification, and `tests/Arch/ConventionsTest.php` fails a class that declares
`toDatabase` without the base.

## Name what `present()` reads

`present()` runs once per row on every render of the panel, on every poll and broadcast. A
subclass whose `present()` reads relations of its subject lists them in
`presentingRelations()`, and the panel loads them once per page. Without it, 50 shared-email
rows cost 308 queries and threw a lazy-loading violation outside production.
`NotificationInboxTest` ("renders shared email rows without per-row queries") fails when a row
type adds a per-row query.

## Bell rows are archived, never deleted

Closing, clearing and deciding archive a row through `App\Actions\Notifications\ArchiveNotification`.
A deleted row loses the history a member may come back for, and Filament's own close event
deleted rows until the panel overrode it.

## A request is not a notification

A request asks someone to decide. Each kind keeps its own table and state machine in the module
that owns it, and implements `App\Contracts\Inbox\RequestSource`. A package tags its source in
its service provider, so `app/` never imports the package, and the source returns nothing while
the package's feature flag is off.

- A decision is one conditional update on `status = 'pending'`, so two approvers cannot both win.
  `Relaticle\EmailIntegration\Actions\DecideEmailAccessRequest` is the precedent, and it also
  writes the activity row and refreshes the owner's bell.
- `approve()` and `deny()` return whether this call decided. The panel shows "already decided"
  when another member won.
- `tests/Feature/Notifications/InboxRequestsPanelTest.php` fails when a source leaks another
  workspace's or another owner's request.

## Escape user text in mail lines

A `MailMessage` line runs through CommonMark, so a subject such as `[x](https://evil.example)`
becomes a link. Mail lines that carry names or subjects go through
`Relaticle\EmailIntegration\Support\MarkdownText::escape()`, and the plain-text mail layout strips
those escapes again. `EmailSharedNotificationTest` fails on a rendered link or a visible backslash.

## Two traps in the panel view

- `@js` inside a Blade component tag's attributes is not compiled: `<x-filament::button
  x-on:click="approve(@js($id))">` reaches Alpine as literal text and the click does nothing.
  Put the values in `x-data` on a plain ancestor and reference them by name.
- An Alpine method called from an element a Livewire morph removes loses `this.$refs` and
  `$nextTick` after its `await`. Capture DOM handles before the call and use
  `requestAnimationFrame` and `querySelector` after it.
- `tests/Browser/Panel/NotificationBellTest.php` clicks for real. Run it in both engines after
  changing the panel: `--browser safari` for WebKit.

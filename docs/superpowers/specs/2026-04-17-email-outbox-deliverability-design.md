# Email Outbox & Deliverability — Phase 5 Design Spec

**Date:** 2026-04-17
**Phase:** 5 (follows Phase 4 email integration)
**Package:** `packages/EmailIntegration` (`Relaticle\EmailIntegration`)

## Goal

Introduce a real outbox for outbound email: persist queued emails before they send, dispatch them under per-account rate limits, and give users visibility and control (preview, cancel, reschedule, retry, undo-send) from a Filament page.

## Background

Phase 4 ships synchronous sending: `SendEmailAction` dispatches `SendEmailJob`, which calls `EmailSendingService::send()`. That service throws if a rate limit is exceeded, so the job fails and retries via Horizon. Outbound `Email` rows are created only *after* a successful provider call, so queued emails have no representation in the database.

Phase 5 inverts this: the `Email` row is persisted *first* in `queued` state, and a scheduled dispatcher releases it to the provider within capacity. This unlocks the outbox UI, scheduled sends, mass-send throttling, cancellation, and undo-send.

## Scope

In scope:

1. Outbox UI (Filament page) — Scheduled / Queued / Sending / Failed / Sent (24h) tabs
2. Per-account rate limits (hourly, daily) with config-level defaults
3. Single-send priority over mass sends (via separate Horizon queues)
4. Preview, Cancel, Reschedule queued emails; Retry failed emails
5. Undo-send (30s window, fixed)
6. Max queued per user (100, config-level)

Out of scope:

- Editing queued email content or recipients (by explicit decision — timing-only reschedule)
- Transient vs terminal error classification (all failures auto-retry 3×)
- Per-provider throttle heuristics beyond hourly/daily caps

## Architectural Decisions

| # | Decision | Alternatives Considered | Why |
|---|----------|-------------------------|-----|
| 1 | Reuse `emails` table; add columns | New `email_outbox` table, two-table dance | `EmailStatus` enum already anticipates `queued/sending/failed`; reuses indexes, model, soft deletes |
| 2 | Scheduled dispatcher (`email:dispatch-outbox`) runs every minute | Job self-release on limit; Horizon `perMinute` rate limiter | Cleanest when outbox must hold rows for minutes-to-days; user-chosen approach |
| 3 | Two Horizon queues: `emails-priority`, `emails-bulk` | Single queue with priority column; bypass for single sends | Laravel/Horizon-idiomatic; priority queue drains first naturally |
| 4 | Fixed 30s undo-send window | Configurable per-account; no undo at all | Matches Gmail convention; simpler than per-account config |
| 5 | Max 100 queued per user (queued state only) | Total lifetime cap; scheduled-only cap | Guards system against runaway queues without penalizing throughput |
| 6 | Timing-only reschedule (no content edit) | Full recompose; content edit only | Avoids recipient drift on mass sends; "cancel and re-send" covers content changes |
| 7 | All failures auto-retry 3× with backoff | Transient-vs-terminal classification | Simpler; provider error classification can come in a later phase |

## Data Model

### Migration: alter `emails`

```php
Schema::table('emails', function (Blueprint $table): void {
    $table->timestamp('scheduled_for')->nullable()->after('sent_at');
    $table->text('last_error')->nullable();
    $table->unsignedTinyInteger('attempts')->default(0);
    $table->string('priority', 10)->default('bulk'); // priority | bulk

    $table->index(['connected_account_id', 'status', 'scheduled_for'], 'idx_emails_dispatcher');
    $table->index(['user_id', 'status'], 'idx_emails_user_status');
});
```

### Enum: `EmailStatus`

Add case `CANCELLED = 'cancelled'` with `gray` color and "Cancelled" label.

### Config: `config/email-integration.php` (extend existing)

```php
return [
    // ...existing keys...
    'defaults' => [
        'hourly_send_limit' => 12,
        'daily_send_limit' => 200,
    ],
    'undo_send_window_seconds' => 30,
    'max_queued_per_user' => 100,
    'dispatcher_interval_seconds' => 60,
];
```

Per-account values on `connected_accounts.hourly_send_limit` / `daily_send_limit` override the config default; `null` means "use default."

### Horizon: add supervisors

In `config/horizon.php`, add two supervisor blocks for `emails-priority` (higher `minProcesses`) and `emails-bulk`, with `balance => 'auto'` and priority ordering such that priority drains first.

## Components

### New

- **`SendEmailAction`** (rewrite) — persist `Email` row with `status=queued`, `scheduled_for`, `priority`, store body / participants / emailables links, enforce `max_queued_per_user`. Returns `Email`.
- **`CancelQueuedEmailAction`** — transitions `queued → cancelled`. Rejects if status changed.
- **`RescheduleQueuedEmailAction`** — updates `scheduled_for` on `queued` rows only.
- **`RetryFailedEmailAction`** — resets `status=queued`, clears `last_error`, `attempts=0` on `failed` rows.
- **`DispatchOutboxCommand`** (`email:dispatch-outbox`) — scheduled every minute via `bootstrap/app.php`; per account, computes remaining hourly/daily capacity and dispatches up to that many due `SendEmailJob`s ordered by `priority DESC, scheduled_for ASC`.
- **`EmailOutboxPage`** — Filament page with tabs, table, per-row actions, bulk-cancel.

### Modified

- **`SendEmailJob`** — now takes `Email $email` id. Pessimistically locks the row, confirms `status=queued`, marks `sending`, calls provider via `EmailSendingService`, marks `sent` with provider identifiers. `failed()` hook writes `last_error` and sets `status=failed`.
- **`EmailSendingService::send()`** — drop rate-limit throw (dispatcher owns gating); update the pre-existing queued row instead of creating a new one on success.
- **`EmailAccountsPage`** — extend edit form with `hourly_send_limit` and `daily_send_limit` (nullable inputs with placeholder showing the config default).
- **`bootstrap/app.php`** — schedule `email:dispatch-outbox` every minute (per `CLAUDE.md` scheduling convention).
- **Compose UI** (`EmailInboxPage` compose + reply/forward actions) — add an optional "Schedule send" date-time picker. When set, passed through to `SendEmailAction` as `scheduled_for`. Default unset (send with 30s undo window for single sends, immediate for mass).

## Send Flow

1. **Compose → `SendEmailAction::execute(data, linkTo...)`**
   - Enforce `max_queued_per_user` (throws if exceeded; surfaced via Filament notification).
   - Determine `scheduled_for`:
     - User-scheduled: user's chosen time
     - Single send (undo window): `now() + 30s`
     - Mass send: `null` (dispatcher picks earliest available slot)
   - Create `Email` row (`status=queued`, `priority`), `EmailBody`, `EmailParticipant` rows, `emailables` links, attach `batch_id` if mass.

2. **Dispatcher — `email:dispatch-outbox` every minute**
   - For each `ConnectedAccount`:
     - `hourlyLimit = account->hourly_send_limit ?? config('email-integration.defaults.hourly_send_limit')`
     - Same for daily.
     - `remainingHour = hourlyLimit - sentCountInLastHour(account)`
     - `remainingDay = dailyLimit - sentCountToday(account)`
     - `capacity = max(0, min(remainingHour, remainingDay))`
   - Query:
     ```php
     Email::query()
         ->where('connected_account_id', $account->id)
         ->where('status', EmailStatus::QUEUED)
         ->where(fn ($q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now()))
         ->orderByRaw("CASE priority WHEN 'priority' THEN 0 ELSE 1 END")
         ->orderBy('scheduled_for')
         ->limit($capacity)
         ->get();
     ```
   - Dispatch `SendEmailJob` for each, onto `emails-priority` or `emails-bulk`.

3. **`SendEmailJob::handle($emailId)`**
   - Wrap in DB transaction; `Email::query()->lockForUpdate()->find($emailId)`.
   - If status ≠ `queued`: return silently (cancelled or already sent).
   - Mark `status=sending`, increment `attempts`.
   - Call `EmailSendingService::send()` → updates row to `status=sent` with provider identifiers.
   - Links + batch bookkeeping as in Phase 4.

4. **`failed(Throwable $e)`** (after 3 tries exhausted)
   - `status=failed`, `last_error=$e->getMessage()`.
   - If provider returned 401/invalid_grant: set `ConnectedAccount->status = reauth_required`.
   - If batch: increment `failed_count`, refresh batch status.

5. **User actions**
   - Cancel: valid while `status=queued`. Sets `status=cancelled`.
   - Reschedule: valid while `status=queued`. Updates `scheduled_for`.
   - Retry: valid while `status=failed`. Resets to `queued`, clears error.
   - Undo send: same code path as Cancel; succeeds if dispatcher hasn't picked up the row yet.

## UI

### Navigation

New **Outbox** entry in the Email navigation group, next to Inbox / Accounts / Signatures / Access Requests. Follows the existing `EmailInboxPage` / `EmailSignaturesPage` Filament Schema pattern.

### Page: `EmailOutboxPage`

- **Header:** "Outbox" title; right-aligned account selector + usage badge ("4/12 hr · 37/200 day").
- **Tabs:** Scheduled (queued with future `scheduled_for`), Queued (queued with `scheduled_for` null or `<= now`, awaiting capacity — this is where mass-send rows and rate-held rows live), Sending, Failed, Sent (24h window, read-only).
- **Table columns:** Checkbox, Subject, Recipients, Scheduled for, Priority, Actions.
- **Row actions (Scheduled / Queued):** Preview (modal), Reschedule (date-time picker), Cancel. Bulk-cancel via checkboxes.
- **Row actions (Failed):** Preview, Retry, Dismiss.
- **Row actions (Sending):** Preview only.
- **Row actions (Sent 24h):** Preview only (links into existing email detail).
- **Undo-send rows:** show countdown ("in 12s · Undo") and replace Cancel with **Undo send** button until the 30s window elapses.

### Email Accounts settings

In `EmailAccountsPage` edit modal, add two nullable integer inputs for hourly and daily limits. Placeholder shows the current config default.

### Post-send toast

After `SendEmailAction` queues a single send with a 30s `scheduled_for`, the Filament notification includes an "Undo" action that POSTs to `CancelQueuedEmailAction`. If it's already picked up, the notification becomes "Too late — email already sent."

## Error Handling & Edge Cases

- **Max queued exceeded:** `SendEmailAction` throws a domain exception; surfaces as a Filament notification.
- **Token revoked mid-send:** captured as `last_error`, email `failed`, `ConnectedAccount->status = reauth_required`.
- **Cancel races with dispatcher:** pessimistic lock in `SendEmailJob` ensures that if dispatcher has flipped status to `sending`, concurrent cancel is rejected ("Already sending").
- **Reschedule on non-queued row:** rejected with notification; UI refreshes.
- **Batch partial failure:** existing `EmailBatch` `sent_count` / `failed_count` logic preserved; status resolves to `partial_failure` when processed ≥ total.
- **Limits changed mid-queue:** dispatcher recomputes capacity every tick; new caps apply immediately.
- **Soft-deleted emails:** excluded from outbox queries (existing `SoftDeletes` scope).
- **Cancelled emails:** excluded from all outbox tabs except via a separate "Cancelled (24h)" surfacing (out of scope for this phase — may just rely on soft-delete).

## Testing

Per `CLAUDE.md`, test through real entry points:

- **`EmailOutboxPage` Livewire tests:**
  - Tab filtering per status
  - Cancel / Reschedule / Retry actions (per-status eligibility)
  - Bulk cancel
  - Max-queued guard blocks 101st send
- **`DispatchOutboxCommand` test:**
  - Respects hourly and daily caps per account
  - Priority ordering drains `priority` before `bulk`
  - Skips `scheduled_for > now()`
  - Null `scheduled_for` (mass sends) picked up when capacity available
  - Per-account limits fall back to config default when `null`
  - Uses `$this->travelTo()` for day/hour boundary safety
- **`SendEmailJob` test:**
  - Marks sent on success
  - Retries on provider exception, marks failed with `last_error` after exhaustion
  - Rejects (no-op) if status already `cancelled`
- **`EmailAccountsPage` test:** can set/clear per-account limits.
- **Undo-send flow test:** single send queued with 30s delay → cancel within window succeeds → outside window (after dispatcher run) fails.

Declare `mutates(...)` per action / command on the corresponding test. No `--min` threshold.

## Rollout

- No feature flag. Ship as a single migration + code change.
- Existing queued/sending/sent `Email` rows from Phase 4 are negligible (all successful sends have `status=sent` already); no backfill needed.
- After deploy, run `php artisan horizon:terminate` so new supervisor config loads.

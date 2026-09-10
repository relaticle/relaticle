---
paths:
  - 'packages/EmailIntegration/**'
---

# Email integration

## Never import provider drafts

Gmail drafts carry the `DRAFT` label and not `SENT`, so `fetchMessage()` classifies
them as inbound. `StoreEmailJob` skips `EmailFolder::Drafts` before the inbox/sent
toggle, and Gmail backfill lists with `-in:drafts`. Importing a draft would store it
as `SYNCED` with the account's sharing default, so teammates could read unsent mail
through linked CRM records. Composer drafts (`EmailStatus::DRAFT`) are a different
path and stay local.

## Privacy-aware email search

Mailbox search (inbox and record email pages) must go through
`EmailSearchService`. Never `ilike` on `subject` or `snippet` alone.
Metadata-only teammate rows stay in the list, but those columns are hidden.
A guessed subject must not match. Participants remain searchable.

## Per-viewer shares override the email default in search

`PrivacyService::effectiveTier()` applies a viewer's share before the email's
`privacy_tier`. Search must do the same. Do not `OR` `privacy_tier` FULL or
SUBJECT with a share: a metadata-only share on a FULL email would still match
subject and snippet. Use the share when one exists (direct first, then another
copy of the same `rfc_message_id`). Fall back to `privacy_tier` only when the
viewer has no share.

## Meeting attendee "self"

`MeetingAttendee.is_self` is the mailbox that owns that meeting copy, not the
current viewer. Name that row from `meeting.connectedAccount.user` (or the
account display name). Never substitute `auth()->user()`. Alice viewing
Bob's copy must still show Bob.

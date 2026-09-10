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

## Personal calendar vs workspace meetings

Home (`ListMeetingsForDay`, `MeetingsHomeWidget`) is a personal calendar.
Use `VisibleMeetingScope::personal($viewer)`. Show only meetings synced from
one of the viewer's connected mailboxes or where the viewer is on the guest
list (user email plus every connected-account address).

Record Meetings tabs, communication-intelligence aggregates, and other
workspace surfaces keep the default `VisibleMeetingScope` (teammate meetings
with an external guest stay visible there).

## All-day meetings are calendar dates

All-day events are stored as a UTC date at midnight (`GoogleCalendarService`
parses the provider's bare `Y-m-d` in UTC). Filter them with `whereDate` on
that calendar date. Do not convert them through the viewer's timezone: a
Los Angeles day window starts at 07:00 UTC, so a September 10 all-day event
would otherwise appear on the 9th. Timed meetings still use local-day UTC
bounds.

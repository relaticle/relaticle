---
paths:
  - 'packages/EmailIntegration/**'
---

# Email integration

## Microsoft Graph replies

`sendMail` always starts a new conversation. Reply with
`POST /me/messages/{id}/reply` after resolving the original Graph message
by `internetMessageId` (`in_reply_to`) or `conversationId` (`thread_id`).
Fall back to `sendMail` only when this mailbox has no matching message.

## Bind mailbox OAuth to the initiating workspace

`RedirectController` stores the current team id in the session before sending
the user to Google or Microsoft. `CallbackController` connects the mailbox to
that stored team, not `$user->currentTeam`. Switching workspaces in another tab
during consent must not import history under the other workspace's sharing
defaults. A missing or non-member binding fails closed: no account is created.

## Vanished provider messages must not fail the mailbox

A listed Gmail or Graph id can be permanently deleted before `StoreEmailJob`
fetches it. That 404 is skippable: returning from the job keeps the store batch
successful so later mail still imports. Retrying until failure marks the mailbox
`ERROR`, which excludes it from scheduled incremental syncs.

## Never import provider drafts

Gmail drafts carry the `DRAFT` label and not `SENT`, so `fetchMessage()` classifies
them as inbound. `StoreEmailJob` skips `EmailFolder::Drafts` before the inbox/sent
toggle, and Gmail backfill lists with `-in:drafts`. Importing a draft would store it
as `SYNCED` with the account's sharing default, so teammates could read unsent mail
through linked CRM records. Composer drafts (`EmailStatus::DRAFT`) are a different
path and stay local.

Graph mail folders must be identified by well-known path names
(`/me/mailFolders/drafts`, `inbox`, `sentitems`). `displayName` is localized
(German `Entwürfe` is not `Drafts`) and would classify unsent mail as archive.

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
current viewer. Name that row from `meeting.connectedAccount.user` only when
the mailbox address is that user's email. Never substitute `auth()->user()`,
and never apply the connecting user's workspace name to a different mailbox
address. A shared inbox stays that address, or its calendar name. Alice viewing
Bob's copy still shows Bob when the mailbox is Bob's email.

## Meeting attendee mailbox names

`MailboxDisplayNameDirectory` must resolve names through `VisibleEmailScope`
for the current viewer. A team-wide participant search leaks names from
private and mailbox-blocked mail onto another user's meeting.

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

## Microsoft calendar delta windows

Graph `calendarView/delta` tokens stay bound to the original start and end
datetimes. Keep every 5-year window's `deltaLink` in `calendar_sync_cursor`
as a JSON list, and replay all of them on incremental sync. Do not keep only
the last future window. A raw Graph delta URL is expired so the account
rebuilds full coverage.

## Microsoft sent mail must be adopted on import

Graph `/me/sendMail` returns 202 with no body. A successful send stores
`ms-pending-*` placeholders. The next delta delivers a different Graph id
and `internetMessageId`. Match the RelaticleMessageId extended property
and update that SENT row. Creating a new synced row duplicates the send.

## Authorize composer inline images

`EmailInlineImageEmbedder` copies files named in composer HTML (`data-id` or
`src`). Those attributes are client-controlled. Only embed files on
`EmailAttachment::DISK` under `email-attachments/{current_team_id}/` with an
`image/` MIME type, plus `data:image/` URIs. Do not treat `/storage/...` URLs
as arbitrary disk paths.

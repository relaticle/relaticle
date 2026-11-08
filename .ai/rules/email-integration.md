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

`RedirectController` stores the current workspace id in the session before sending
the user to Google or Microsoft. `CallbackController` connects the mailbox to
that stored workspace, not `$user->currentWorkspace`. Switching workspaces in another tab
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

Mailbox search (inbox and the record emails tab) must go through
`EmailSearchService`. Never `ilike` on `subject` or `snippet` alone.
Metadata-only teammate rows stay in the list, but those columns are hidden.
A guessed subject must not match. Participants remain searchable, except
other people's BCC rows (same gate as recipient suggestions).

## Per-viewer shares override the email default in search

`PrivacyService::effectiveTier()` applies a viewer's share before the email's
`privacy_tier`. Search must do the same. Do not `OR` `privacy_tier` FULL or
SUBJECT with a share: a metadata-only share on a FULL email would still match
subject and snippet. Use the share on that email when one exists. Fall back to
`privacy_tier` only when the viewer has no share.

## A Message-ID is never proof of access

Anyone can put a message with a chosen `Message-ID` into their own mailbox, and a
reply carries the original's ID in `In-Reply-To`. So a copy of the same
`rfc_message_id` in the viewer's mailbox grants nothing on a teammate's copy, and a
share applies only to the email it was made on. `PrivacyService::effectiveTier()`,
`VisibleEmailScope` and `EmailSearchService` read each copy on its own.
`PreferredEmailCopyService` still shows one row per message: the viewer's own copy,
then the copy most open to them, counting their share on it.
`tests/Feature/Mcp/EmailToolsTest.php` fails when a held copy or a share on another
copy opens a teammate's email.

## Meeting attendee "self"

`MeetingAttendee.is_self` is the mailbox that owns that meeting copy, not the
current viewer. Name that row from `meeting.connectedAccount.user` only when
the mailbox address is that user's email. Never substitute `auth()->user()`,
and never apply the connecting user's workspace name to a different mailbox
address. A shared inbox stays that address, or its calendar name. Alice viewing
Bob's copy still shows Bob when the mailbox is Bob's email.

## Meeting attendee mailbox names

`MailboxDisplayNameDirectory` must resolve names through `VisibleEmailScope`
for the current viewer. A workspace-wide participant search leaks names from
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

## The sharing level lives on the mailbox

`connected_accounts.sharing_tier` holds the level. Resolve it with
`PrivacyService::tierForMailbox()` from the mailbox the email is sent or synced
through, never from the user or `currentWorkspace`: the same person shares
differently in each workspace. A null level falls back to
`workspaces.default_email_sharing_tier`, which no screen edits any more: it keeps
the level admins chose before the workspace Sharing tab was removed. Write a level
only through `SaveMailboxSharingTier`, which re-stamps that one mailbox's mail.
The account page saves it only when the chosen level differs from the resolved
one, so a null level is never stamped by an unrelated save.
`tests/Feature/EmailIntegration/EmailAccountSettingsPageTest.php` fails when it is.
`tests/Feature/EmailIntegration/PrivacyServiceTest.php` fails when a second
workspace's mailbox changes the level, and
`ApplyDefaultSharingTierToExistingEmailsActionTest.php` fails when a save
reaches another mailbox's mail.

A sender passes no level. `SendEmailAction` and `StoreEmailAction` create the
email through `$account->emails()->chaperone()`, and `EmailObserver` stamps the
level from that mailbox. An explicit `privacy_tier` on create means the owner
chose it for that one email, and a later level change skips it.
`tests/Feature/EmailIntegration/EmailComposerTest.php` fails when a composed
email takes a level from anywhere but the mailbox it is sent from.

## A mailbox never outlives the membership

Removing a member, or leaving, disconnects that person's mailboxes in the
workspace and cancels the mail they had queued there. Both go through
`DisconnectFormerMemberMailbox`. `DisconnectRemovedMemberMailboxesListener`
runs it on `TeamMemberRemoved`, and it is registered outside the feature flag,
because a stored token must not survive with the feature off.
`email:disconnect-former-member-mailboxes` runs it for anyone removed earlier,
and a migration queues that command. A new path that ends a membership fires
`TeamMemberRemoved` or calls the action itself.
`tests/Feature/Workspaces/RemoveWorkspaceMemberTest.php` fails when a removal
leaves a token, a syncing mailbox or a queued email behind.

## An agent's queued email answers to the connection that queued it

`HoldAgentEmail` stamps an MCP email with `oauth_client_id` or
`personal_access_token_id`. `SendEmailJob::agentRefusal()` checks it when the
email is due: the credential must still be live (`LiveAgentCredentialsQuery`),
and its user must still hold `EmailAgentSend`. Check at send time, never on a
revoke path, because one check covers revocation, token deletion, expiry and
demotion. The user's own Retry (`RetryFailedEmailAction`) clears the binding,
and an MCP retry (`RequeueAgentEmail`) rebinds it. A reschedule keeps it, so
revoking the assistant that wrote an email always stops it. Rela emails carry
no binding, because the person approves each one.
`tests/Feature/Mcp/EmailToolsTest.php` fails when any of this changes.

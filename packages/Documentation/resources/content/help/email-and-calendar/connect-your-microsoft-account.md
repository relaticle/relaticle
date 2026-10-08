---
title: Connect your Microsoft account
description: Link an Outlook or Microsoft 365 mailbox so its email and calendar sync into Relaticle, and see what access Relaticle asks Microsoft for.
order: 2
updated: "2026-10-07"
related: [help/email-and-calendar/connect-your-google-account, help/email-and-calendar/read-and-reply-to-email, help/email-and-calendar/choose-who-sees-your-email, help/email-and-calendar/see-meetings-and-link-them-to-records]
---

Connecting a Microsoft account brings an Outlook or Microsoft 365 mailbox
and its calendar into Relaticle. Emails and meetings land on the records
they involve, and you can send from Relaticle with that address.

## Connect it

1. Open the workspace menu at the top of the sidebar and choose
   **Workspace Settings**.
2. Open the **Email and Calendar** tab. It opens on **Accounts**.
3. Click **Connect Microsoft account**.
4. Sign in at Microsoft and accept the access it lists.
5. Microsoft sends you back to Relaticle, and the account appears under
   **Connected accounts**.

The **Emails** page, a record's **Emails** tab, and the **Meetings** panel
on **Home** show the same button until you connect an account.

![The Accounts tab under Email and Calendar, with the Connect Microsoft account button beside Connect Google account below the connected accounts](/help-assets/email-and-calendar/connect-your-google-account-1.png)

On a self-hosted install, the button appears once the Microsoft client is
configured. The [Self-Hosting Guide](/developers/self-hosting) lists the
settings.

## What Relaticle asks Microsoft for

- **Read your mail**, so messages can appear on your records.
- **Send mail as you**, so what you write in Relaticle goes out from your
  address.
- **Read and update your calendar**, so meetings appear and your RSVP
  reaches your calendar.
- **Read your profile**, so Relaticle knows which address it connected.
- **Keep this access**, so syncing continues while you are signed out of
  Microsoft.

Relaticle never changes, moves, or deletes messages in your mailbox, and it
never imports your drafts. On your calendar, it changes only your own answer
to an invitation.

## Which folders sync

Relaticle syncs every mail folder, including folders inside your Inbox and
folders a rule moves mail into. It leaves out **Drafts**, **Junk Email**,
**Deleted Items**, **Outbox**, **Clutter**, and **Conversation History**,
along with any folder inside them.

## What happens after you connect

The account belongs to the workspace you connected it in. Relaticle imports
your mailbox and calendar in the background, and the account shows
**Syncing** with a percentage until it reads **In sync**.

What your workspace sees of each email depends on the mailbox's sharing level.
[Choose who sees your email](/help/email-and-calendar/choose-who-sees-your-email)
before you connect a mailbox you consider sensitive.

A Microsoft account has the same menu as a Google account. See
[Manage a connected account](/help/email-and-calendar/connect-your-google-account#manage-a-connected-account)
for **Manage**, **Set as default**, and **Disconnect account**.

## If Microsoft asks for admin approval

Some organizations let only an administrator approve a new app. Ask your
Microsoft 365 administrator to approve Relaticle, then connect again.

## If the account shows Reconnect needed

Microsoft has stopped accepting the saved access. Open the account's menu
and click **Reconnect**, then sign in and accept the access again.

## After you disconnect

**Disconnect account** stops syncing and deletes the access tokens Relaticle
holds. Microsoft still lists Relaticle among the apps you approved. Remove
it in your Microsoft account settings to end that approval.

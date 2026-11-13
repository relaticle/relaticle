---
title: Set workspace email privacy
description: Owners and admins hide email from protected or blocked addresses and decide which records sync creates.
order: 6
updated: "2026-11-08"
related: [help/email-and-calendar/choose-who-sees-your-email, help/workspace/manage-members-and-roles, help/email-and-calendar/connect-your-google-account]
---

Owners and admins set the email rules for the whole workspace: the addresses
whose email stays hidden, and the records that syncing creates. Open
**Workspace Settings**, then **Email and Calendar**. The two tabs below appear
only for those two roles. How much of each mailbox the workspace sees is set
on the mailbox itself, as
[Choose who sees your email](/help/email-and-calendar/choose-who-sees-your-email)
explains.

## Hide email that involves certain addresses

The **Email visibility** tab hides emails and calendar events that involve
the addresses and domains you list, everywhere in Relaticle.

1. Click **Add contacts**.
2. Enter **Email addresses** or **Domains**, pressing Enter after each.
3. Tick **Include subdomains** to cover addresses such as
   `user@mail.example.com` when you add `example.com`.
4. Click **Submit**.

Each entry has an enforcement level, which **Change enforcement level**
switches.

- **Protected**: hidden only when every address on the email or event is
  protected or blocked. Internal conversations stay private, and a
  conversation with a customer still shows.
- **Blocked**: always hidden when that address is on the email or event.

Workspace members' own addresses are protected by default, and so are
their work domains. Those entries read **System default**
and always apply.

![The Email visibility tab listing protected addresses and domains with their enforcement level and who added them](/help-assets/email-and-calendar/set-workspace-email-privacy-1.png)

## Decide which records syncing creates

The **Record creation** tab sets what happens when a synced email or event
involves someone who is not in your CRM yet.

| Contact creation mode | What gets created |
|---|---|
| **All contacts** | A record for everyone who appears in members' emails and calendar events. |
| **Selective contact creation** | A record only for someone a member has emailed, or who appears in a member's calendar events. |
| **None** | Nothing. Emails and events still link to records you create yourself. |

**Selective contact creation** is the recommended mode and the one a new
workspace starts on. It keeps newsletters and cold inbound mail from
filling your CRM.

**Automatically create company records** adds a company from a new person's
email domain. Free mail domains such as gmail.com never create a company,
and automated senders such as no-reply addresses never create a record.

A change here affects only email and events synced afterwards.

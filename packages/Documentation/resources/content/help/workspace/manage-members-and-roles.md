---
title: Manage members and roles
description: What the Owner, Admin, Member, and Viewer roles can each do, plus invitations, join links, and removing members.
order: 1
updated: "2026-09-22"
related: [help/getting-started/invite-your-team, help/workspace/rename-or-delete-your-workspace]
---

Membership is managed on **Workspace Settings**. Click your workspace name
at the top of the sidebar to reach it, then click the **Members** tab.
Invitations, roles, and removals all live there. **Invite people** sends the
invites. **Members** below it lists everyone with access, whether they have
joined or not.

## A recent change to Admin access

Admins can now invite people, revoke invitations, and change member
roles. They could not before. Check your **Members** list if you granted that
role earlier, because everyone holding it gained those abilities.

## The four roles

| | Owner | Admin | Member | Viewer |
|---|---|---|---|---|
| View records | Yes | Yes | Yes | Yes |
| Create and update records | Yes | Yes | Yes | No |
| Delete and restore records | Yes | Yes | Yes | No |
| **Force delete** records (permanent) | Yes | Yes | No | No |
| Import data | Yes | Yes | Yes | No |
| Export data | Yes | Yes | Yes | No |
| Manage custom fields | Yes | Yes | No | No |
| View the activity log | Yes | Yes | No | No |
| Invite, revoke, and change member roles | Yes | Yes | No | No |
| Promote someone to Admin | Yes | No | No | No |
| Manage billing | Yes | No | No | No |
| Rename or delete the workspace | Yes | No | No | No |

Admins manage members and custom fields alongside working with records, but a
few things stay with the **workspace owner** alone, whatever anyone's role:
promoting someone *to* Admin, renaming or deleting the workspace, and
billing.

The owner isn't a role you assign. Whoever owns the workspace always
appears with an **Owner** badge, has no role to change, and can't leave or
be removed.

**Compare roles**, in the footer of the **Invite team members** dialog and
of the **Change role** dialog, opens this table in the app so it never
falls out of date with what each role can actually do.

## Invitations

Click **Invite team members** to send up to 10 invites at once. Paste the
email addresses separated by a comma, a space, or a new line, pick the role
they all join with, then send.

An invited person joins the **Members** list straight away, above everyone
who has accepted, marked **Invite pending** with the role they will get and
how long the invite has left. Its actions menu offers:

- **Resend** the email.
- **Revoke** the invitation.

An invite lasts 7 days. After that the row reads **Invite expired**, and
**Resend** issues a fresh one.

![The Members list showing an invited email marked Invite pending above the people who have joined](/help-assets/workspace/manage-members-and-roles-1.png)

## The workspace link

**Invite link**, next to **Invite team members**, holds a single link anyone
can join with. Copy it to share directly, and set the role people get when
they join with it, Member by default. The role saves as soon as you pick it.
The link lasts 7 days and can grant Member or Viewer. Admins are
invited by email, so the person is always named.

Two controls sit under it:

- **Generate a new link** replaces the link if the current one leaked. The
  old one stops working right away.
- **Turn off the link** removes it entirely, leaving email invitations as
  the only way in. Turning it back on issues a different link, so the one
  you turned off stays dead.

## Changing and removing members

Each person's role sits next to their name, and the actions menu at the end
of the row changes it. **Change role** switches them between Admin,
Member, and Viewer. **Remove** takes a member out of the workspace: their
records stay, they lose access. Any member but the owner can **Leave** the
workspace themselves; the owner has no **Leave** action.

The list is searchable by name or email address, so a long roster stays
workable.

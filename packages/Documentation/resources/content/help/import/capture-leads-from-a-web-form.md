---
title: Capture leads from a web form
description: Connect a Maxforms form to Relaticle Cloud so each submission creates linked people, companies, opportunities, notes and tasks.
order: 5
updated: "2026-10-04"
related: [help/import/update-existing-records, help/ai-assistant/connect-claude-or-chatgpt, docs/guides/rest-api]
---

Relaticle has no form builder of its own.
[Maxforms](https://maxforms.com/integrations/relaticle), a form builder,
connects to Relaticle Cloud and turns each submission into records in your
workspace. You set the connection up in Maxforms, and Relaticle asks you to
approve access once.

## What a submission creates

One submission can create up to five records, each linked to the others:

| Record | What it usually holds |
|--------|----------------------|
| **Company** | The submitter's organisation |
| **Person** | The submitter, linked to the company |
| **Opportunity** | What the form opens, linked to both |
| **Note** | The answers, attached to the records above |
| **Task** | A follow-up, attached the same way |

You switch each record on or off per form. A form that needs only a person
and a note creates only those two.

## Connect a form

1. In Maxforms, open the form's **Integrate** tab, find the **Relaticle**
   card, and select **Connect**.
2. Select **Connect New Account** and sign in to Relaticle if asked.
3. Approve access and **pick the workspace** the form may write to.
4. Back in Maxforms, switch on the records you want and map each Relaticle
   field to a form answer. Your custom attributes are listed beside the
   built-in ones.
5. Save, send a test submission, and open the new records in Relaticle.

Maxforms asks for two permissions: reading your records, and creating and
updating them. It cannot delete anything.

## Avoid duplicates

Maxforms can match a submission to a record you already have instead of
creating a second one. People match on **Emails** and companies on
**Domains**, the two fields Relaticle keeps unique.

In the Relaticle card in Maxforms, map the form's email answer to Emails,
then choose Emails under **Match existing people by**. Do the same with
Domains for companies.

A repeat submitter then updates their existing person and company. The
opportunity, note and task are created new each time.

## See and revoke the connection

Open **Settings → Access Tokens** from your avatar menu. Maxforms appears in
the **AI Connectors** list beside any assistants you connected, with the
workspace it writes to. **Revoke** cuts its access immediately, for every
form you connected. New submissions then fail to deliver until you connect
again.

## Good to know

- The connection works with Relaticle Cloud. Maxforms cannot connect to a
  self-hosted install. A self-hosted install can take form data through
  the upsert endpoints in the [REST API guide](/developers/rest-api).
- Maxforms acts as the person who connected it, inside the one workspace
  picked on the consent screen.
- A paused workspace can't be picked on the consent screen. Deliveries to a
  workspace that pauses later fail until it is subscribed again.

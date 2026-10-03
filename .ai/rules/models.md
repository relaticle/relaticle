---
paths:
  - 'app/Models/**'
  - 'app/Casts/**'
---

# Models

## Activity timeline email titles

`VisibleEmailScope` admits metadata-only emails (participants and timestamp). Field masking is a view/policy concern. Timeline titles must go through `$viewer->can('viewSubject', $email)` and render `(subject hidden)` when that is false. Never copy `$email->subject` onto a teammate-facing surface.

## Email canonicalization

- Emails are stored canonical: trimmed, lowercase. The `AsCanonicalEmail`
  inbound cast enforces it on `User.email` and `WorkspaceInvitation.email`; reuse it
  for any new email column. Casts never touch query input, so every lookup
  against an email column must canonicalize first via
  `App\Support\EmailAddress::canonicalize()`; never write a raw
  `where('email', $userInput)`. Same ladder for future scalar normalization:
  one inbound cast per concept in `app/Casts`, value objects only for
  compound values.

## `custom_field_values.visible_text` is generated, never written

The column is `GENERATED ALWAYS AS (...) STORED`: Postgres derives it from `text_value` on every
write, so search sees the visible text of rich-text fields whatever path wrote them. Never set it,
and never copy whole rows with `replicate()` or `INSERT ... SELECT *`; both fail on a generated
column. Its SQL mirrors `App\Support\PlainText::fromHtml()`, so change the two together. Adding or
changing a stored generated column rewrites the table under an exclusive lock (about 8 s at the
2026-10 production size), so ship such migrations at a quiet moment.


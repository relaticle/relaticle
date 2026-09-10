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
  inbound cast enforces it on `User.email` and `TeamInvitation.email`; reuse it
  for any new email column. Casts never touch query input, so every lookup
  against an email column must canonicalize first via
  `App\Support\EmailAddress::canonicalize()`; never write a raw
  `where('email', $userInput)`. Same ladder for future scalar normalization:
  one inbound cast per concept in `app/Casts`, value objects only for
  compound values.

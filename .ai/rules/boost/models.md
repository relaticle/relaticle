---
paths:
  - 'app/Models/**'
---

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

### Email canonicalization

- Emails are stored canonical: trimmed, lowercase. The `AsCanonicalEmail`
  inbound cast enforces it on `User.email` and `TeamInvitation.email`; reuse it
  for any new email column. Casts never touch query input, so every lookup
  against an email column must canonicalize first via
  `App\Support\EmailAddress::canonicalize()`; never write a raw
  `where('email', $userInput)`. Same ladder for future scalar normalization:
  one inbound cast per concept in `app/Casts`, value objects only for
  compound values.

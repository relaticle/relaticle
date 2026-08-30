---
paths:
  - 'app/Models/**'
---

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

### Email canonicalization

- Emails are stored canonical: trimmed, lowercase (`User.email` and
  `TeamInvitation.email` set-mutators enforce it). Postgres compares
  case-sensitively, so every lookup against an email column must canonicalize
  the input first via `App\Support\EmailAddress::canonicalize()`; never write
  a raw `where('email', $userInput)`.

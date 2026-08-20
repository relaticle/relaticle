# Spatie Security Guidelines (Reference)

## Passwords

- Store all passwords in 1Password.
- Ensure each password is unique; no reuse.
- Enable two-factor authentication through 1Password when available.

## GitHub

- Sign all commits.
- Follow [GitHub's commit-signing instructions](https://docs.github.com/en/authentication/managing-commit-signature-verification/signing-commits); signing can be configured through [1Password](https://blog.1password.com/git-commit-signing/).

## Application Security

- Transmit all HTTP traffic over SSL.
- Use CSRF tokens in all forms.
- Use appropriate HTTP methods for significant actions: `DELETE`, `POST`, `PUT` — never `GET`.
- Add automated authorization tests to verify only authorized users can access restricted functionality.

## Database Security

- Hash all stored passwords.
- Encrypt API keys stored in databases.
- Use separate database users per database with appropriate read/write permissions.
- Restrict database access to whitelisted hosts only (webserver and developer machines).

## Server Security

- Keep NGINX, PHP, Ubuntu, and similar software up to date.
- Use SSH with private key authentication; disable password authentication.
- Install and enable `unattended-upgrades` for automatic security updates.
- Configure firewalls to permit only necessary traffic (typically ports 22 and 443).
- Manage all servers through Ansible for rapid patching and access revocation.

## General

- Use backups (e.g. BackBlaze) and test them periodically.
- Protect all private keys with passwords.
- Enable FileVault (full-disk encryption) on all Macs.
- Never use public searchable services like Pastebin or Gist for sensitive code or data.
- Never install pirated software on a computer or phone.
- Install browser extensions only from the Chrome Web Store or App Store, keep them to a minimum, and account for ownership changes or malicious takeovers.
- Never use browser extensions that can track typed keys, passwords, or browser history; the 1Password browser extension is allowed.

---

Source: https://spatie.be/guidelines/security

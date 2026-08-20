---
name: spatie-security
description: Apply Spatie's security guidelines when configuring applications, databases, servers, credentials, or signed Git commits, or when reviewing code for security concerns; use for SSL setup, CSRF protection, password hashing, database permissions, and server hardening.
license: MIT
metadata:
  author: Spatie
---

# Spatie Security Guidelines

## Overview

Apply Spatie's security best practices when building, configuring, or reviewing applications and infrastructure.

## When to Activate

- Activate this skill when configuring application security (authentication, authorization, forms).
- Activate this skill when setting up or reviewing database configurations.
- Activate this skill when configuring servers or reviewing infrastructure.
- Activate this skill when reviewing code for security vulnerabilities.
- Activate this skill when configuring or creating signed Git commits.

## Scope

- In scope: Application security, database security, server configuration, credential management, signed Git commits.
- Out of scope: Code style, business logic, UI/UX design.

## Workflow

1. Identify the application, database, server, credential, or Git security concern.
2. Read `references/spatie-security-guidelines.md` and focus on the relevant sections.
3. Apply the narrowest relevant security controls without weakening existing protections.

## Core Rules (Summary)

- Store unique passwords in 1Password, enable two-factor authentication, and password-protect private keys.
- Sign all Git commits.
- Use SSL, CSRF protection, appropriate HTTP methods, and automated authorization tests.
- Hash passwords, encrypt stored API keys, isolate database users, and restrict database hosts.
- Keep servers current, disable SSH password authentication, enable unattended security updates, and restrict firewall traffic.
- Protect devices, backups, sensitive data, and browser activity.

## References

- `references/spatie-security-guidelines.md`

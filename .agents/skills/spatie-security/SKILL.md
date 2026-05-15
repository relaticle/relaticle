---
name: spatie-security
description: Apply Spatie's security guidelines when configuring applications, databases, or servers, or when reviewing code for security concerns; use for SSL setup, CSRF protection, password hashing, database permissions, and server hardening.
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

## Scope

- In scope: Application security, database security, server configuration, credential management.
- Out of scope: Code style, business logic, UI/UX design.

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

## Credential Management

- Store all passwords in a password manager (e.g. 1Password).
- Ensure each password is unique; no reuse.
- Enable two-factor authentication when available.
- Protect all private keys with passwords.

## General

- Use backups (e.g. BackBlaze) and test them periodically.
- Enable FileVault (full-disk encryption) on all Macs.
- Never use public services like Pastebin for sensitive code or data.
- Install browser extensions only from official stores; minimize usage.

---

Source: https://spatie.be/guidelines/security
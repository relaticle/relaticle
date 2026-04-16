# Email Integration — Test Coverage Design

**Date:** 2026-04-16  
**Branch:** feat/email-calendar-integration  
**Approach:** Risk-layered, file-per-component (matching existing test structure)

---

## Context

The `packages/EmailIntegration` package already has 12 test files covering the *sending* side of email (compose, reply, forward, mass send, store, link, send actions, and service-level tests for blocklist, privacy, sharing).

The *reading* side and all three settings pages have zero coverage. This spec defines five new test files to close those gaps, ordered by risk — security-critical paths first.

---

## Files to Create (in priority order)

All files live in `tests/Feature/EmailIntegration/`.

---

### 1. `EmailsRelationManagerReadingTest.php`

**Mutates:** `BaseEmailsRelationManager`  
**Priority:** Highest — contains security-critical privacy enforcement logic with no tests.

#### `requestAccess` row action

| Scenario | Expected outcome |
|---|---|
| Non-owner with metadata-only view requests full access | `EmailAccessRequest` created, owner notified via `EmailAccessRequestedNotification` |
| Same requester already has a pending request | Warning notification sent, no new request created |
| Viewer is the email owner | Action is hidden |
| Viewer already has full body access via an `EmailShare` | Action is hidden |

#### `manageSharing` row action (owner only)

| Scenario | Expected outcome |
|---|---|
| Owner saves a new `privacy_tier` | `Email.privacy_tier` updated in DB |
| Owner saves with teammate shares | `EmailShare` rows created for each teammate |
| Owner saves again with a different share list | Shares **the owner previously created** are deleted and the new set is inserted (shares created by other users are unaffected) |
| Non-owner views the action | Action is hidden |

#### `shareAllOnRecord` header action

| Scenario | Expected outcome |
|---|---|
| Owner saves a bulk privacy tier | All owner-linked emails on the record get the new tier |
| Owner adds teammate shares | `EmailShare` rows created for each email × teammate |
| Authenticated user owns no emails on the record | Action is hidden |

#### Column behavior (privacy enforcement)

| Scenario | Expected outcome |
|---|---|
| Viewer cannot `viewSubject` on an email | `subject` column shows `(subject hidden)` |
| Viewer can `viewSubject` | Real subject is shown |
| Email has no `thread_id` | `summarizeThread` row action is hidden |
| Viewer cannot `viewSubject` | `summarizeThread` row action is hidden |

---

### 2. `EmailAccountsPageTest.php`

**Mutates:** `EmailAccountsPage`

#### `editSettings` action

| Scenario | Expected outcome |
|---|---|
| Owner saves sync/contact settings | `ConnectedAccount` updated with all four fields |
| User submits with another user's `account_id` | No update occurs (ownership scope prevents it) |

#### `disconnect` action

| Scenario | Expected outcome |
|---|---|
| Owner confirms disconnect | `ConnectedAccount` deleted |
| User passes another user's `account_id` | Account is not deleted |

#### Page mounting

| Scenario | Expected outcome |
|---|---|
| User has accounts in the current team | Only their accounts are loaded |
| Another user's account exists in the same team | It does not appear in `connectedAccounts` |

---

### 3. `EmailPrivacySettingsPageTest.php`

**Mutates:** `EmailPrivacySettingsPage`

#### `save` action

| Scenario | Expected outcome |
|---|---|
| User saves a new default tier | `teams.default_email_sharing_tier` updated |
| User saves protected email addresses | `ProtectedRecipient` rows created with `type = email` |
| User saves protected domains | `ProtectedRecipient` rows created with `type = domain` |
| User saves with an empty list after previous entries existed | All prior `ProtectedRecipient` rows for the team are deleted |
| Save completes | Success notification sent |

#### Page mounting

| Scenario | Expected outcome |
|---|---|
| Team has an existing default tier | `default_email_sharing_tier` pre-filled from team |
| Team has existing protected recipients | `protected_emails` and `protected_domains` pre-filled |

---

### 4. `EmailSignaturesPageTest.php`

**Mutates:** `EmailSignaturesPage`

#### `createSignature` action

| Scenario | Expected outcome |
|---|---|
| Valid data submitted | `EmailSignature` created, success notification sent, `signatures` collection refreshed |
| `connected_account_id` missing | Validation error |
| `name` missing | Validation error |
| `content_html` missing | Validation error |

#### `editSignature` action

| Scenario | Expected outcome |
|---|---|
| Owner updates name and content | Signature updated, success notification sent, `signatures` refreshed |
| `is_default` toggled on | Signature marked default, previous default cleared |

#### `deleteSignature` action

| Scenario | Expected outcome |
|---|---|
| Owner confirms delete | Signature deleted, success notification sent, `signatures` refreshed |
| User passes another user's `signature_id` | Signature is not deleted (ownership scope prevents it) |

#### Page mounting

| Scenario | Expected outcome |
|---|---|
| User has signatures across their accounts | Only their signatures appear |
| Another user has signatures in the same team | They do not appear |

---

### 5. `EmailAccessRequestsPageTest.php`

**Mutates:** `EmailAccessRequestsPage`

#### Tab switching

| Scenario | Expected outcome |
|---|---|
| Default tab is `incoming` | Requests where user is owner are shown |
| User switches to `outgoing` | Requests where user is requester are shown |
| Tab switches | `selectedRequestId` is cleared |

#### `selectRequest`

| Scenario | Expected outcome |
|---|---|
| User selects a request | `selectedRequestId` set to that request's ID |

#### `approveAccessRequest` action

| Scenario | Expected outcome |
|---|---|
| Owner approves a pending request | `ApproveEmailAccessRequestAction` executes, success notification sent, `selectedRequestId` cleared |
| User passes a request they do not own | Action does nothing (ownership guard) |

#### `denyAccessRequest` action

| Scenario | Expected outcome |
|---|---|
| Owner denies a pending request | `DenyEmailAccessRequestAction` executes, success notification sent, `selectedRequestId` cleared |
| User passes a request they do not own | Action does nothing |

#### Navigation badge

| Scenario | Expected outcome |
|---|---|
| User has pending incoming requests | Badge shows the count as a string |
| User has no pending requests | `getNavigationBadge()` returns `null` |

---

## Test Setup Conventions

All test files follow the existing conventions in the project:

- `beforeEach` creates a user via `User::factory()->withTeam()->create()`, calls `actingAs()`, and sets `Filament::setTenant()`
- `ConnectedAccount` is created with `withoutEvents()` where observer side-effects are irrelevant
- `Email` records are created via `Email::factory()` with named states (`.private()`, `.full()`, etc.)
- Factories are used for all supporting models (`EmailAccessRequest::factory()`, `EmailShare::factory()`, etc.)
- No mock/stub of internal services — all services run against a real test database

---

## What is intentionally out of scope

- `CompanyEmailsRelationManager` / `OpportunityEmailsRelationManager` — they delegate entirely to `BaseEmailsRelationManager`; a smoke test adds no coverage value
- `summarizeThread` AI content — the AI call itself is not tested (no deterministic output); only visibility conditions are tested
- OAuth redirect URLs (`connectGmail`, `connectAzure`) — these are URL-generating actions, not form actions; no Livewire state to assert

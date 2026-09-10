# Durable Files and Agent Uploads Implementation Plan

> **For agentic workers:** REQUIRED: Use `sdd-lean` to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every durable user file in Relaticle lives in a medialibrary collection on the model that owns it, so one config change can move all of them to a private disk. On top of that storage model, an AI agent working through the MCP server can hand Relaticle a file it holds or can point at, receive a stable path back, and set a `file-upload` custom field to that path.

**Scope:** This plan closes #686 (route every durable user file through medialibrary) and delivers Plan B of the agent custom-field work (spec section 7). Phase 1 is #686. Phase 2 is the agent upload contract and depends on Phase 1 task 1, because the `file-upload` type is disabled product-wide today and its storage changes shape in Phase 1. Plan A (values) shipped in #698.

**Architecture:** Phase 1 replaces the package `FileUpload` component for `file-upload` custom fields with a media-backed component, registers a medialibrary `FileAttachmentProvider` for rich editor inline attachments, and leaves transient import CSVs and Jetstream profile photos where they are. Phase 2 adds two MCP tools (`create-upload-url`, `upload-file`) backed by one action, `App\Actions\Upload\StoreAgentUpload`, storing into a `Team` `agent-uploads` collection, and swaps the package `file` rule for `App\Rules\StoredUploadPath` on API, MCP and chat writes.

**Tech Stack:** Laravel 12, laravel/mcp, spatie/laravel-medialibrary (already installed, no Filament plugin added), relaticle/custom-fields 3.x, Pest 4

**Spec:** `docs/superpowers/specs/2026-09-10-agent-custom-field-writes-design.md`, sections 7, 9, 10. Issue #686 carries the file-path inventory and the done-when list.

**Skills to activate:** `@medialibrary-development`, `@mcp-development`, `@testing-best-practices`, `@spatie-laravel-php`

---

## Global Constraints

- Durable user files go through medialibrary with a named collection on the owning model. Transient files (import CSVs under `storage/app/imports`) and framework-owned files (Jetstream profile photos) are exempt.
- Custom-field values stay path strings for compatibility until every consumer reads the Media row. The path is derived from the Media row, never the other way round.
- One contract for MCP and REST. `StoredUploadPath` runs on both; no MCP-only validation.
- Actions stay untouched. Validation happens before the action, in `ValidCustomFields`.
- No new composer dependency. The attachment provider and the media-backed field component are ours.
- Every user-facing string goes through `__()`. No new PHPStan ignores. 100% type coverage.

## Upload paths (from #686, verified 2026-09-10)

| Upload path | Storage today | After this plan |
|---|---|---|
| Company logos | `logo` collection on `Company`, filled by `FetchFaviconForCompany` | unchanged, already medialibrary |
| Custom field `file-upload` values | package `FileUpload`, public disk, `uploads/custom-fields`, path string in the value | Media row on the record's model, value still a path (Phase 1, task 1) |
| Rich editor inline attachments | Filament default file attachments, public disk | Media row on the record being edited (Phase 1, task 2) |
| Agent uploads | none, no write path exists | `agent-uploads` collection on `Team` (Phase 2) |
| User profile photos | Jetstream, public disk, `profile-photos` | exempt, framework-owned |
| Import wizard CSVs | `storage/app/imports/{id}`, transient | exempt, correctly transient |

## Phase 1: every durable file through medialibrary (#686)

### Task 1: Media-backed `file-upload` custom field

**Files:**
- Create: `app/Filament/CustomFields/MediaFileUploadFieldType.php` (registered through `CustomFieldsType`, the same seam `DateFieldType` uses in `AppServiceProvider`)
- Create: `app/Support/Media/CustomFieldUploadPathGenerator.php`
- Modify: `config/media-library.php` (`custom_path_generators`)
- Modify: `config/custom-fields.php` (remove `file-upload` from `->disabled([...])`)
- Modify: the five CRM models that accept custom fields implement `HasMedia` where they do not already (`Company` does)
- Test: `tests/Feature/Filament/App/Resources/CustomFieldFileUploadTest.php`

- [ ] Uploading through the panel form creates a Media row in a `custom-field-{code}` collection on the record and stores the Media path as the field value.
- [ ] Files land at `uploads/custom-fields/{media_ulid}/{file_name}` so existing path values and previews keep working.
- [ ] Removing the file in the form deletes the Media row and clears the value. Deleting the record deletes its media.
- [ ] Existing path-string values without a Media row still preview and download (compatibility until the backfill below runs).
- [ ] A one-off command `media:backfill-custom-field-files` creates Media rows for legacy path values, idempotent, dry-run by default.

### Task 2: Rich editor attachments through medialibrary

**Files:**
- Create: `app/Support/Media/MediaFileAttachmentProvider.php` (implements Filament's `FileAttachmentProvider`)
- Modify: the package rich editor integration point for note bodies and task descriptions so the provider is attached (`RichEditorComponent` is package code; wire the provider through the field type registration in `AppServiceProvider`, not a vendor patch)
- Test: `tests/Feature/Filament/App/Resources/RichEditorAttachmentTest.php`

- [ ] Pasting or uploading an image into a note body or task description creates a Media row in an `attachments` collection on the record being edited.
- [ ] `cleanUpFileAttachments()` removes Media rows no longer referenced by the content.
- [ ] Attachment URLs resolve through medialibrary, so a disk change needs no content rewrite.

### Task 3: One switch to a private disk

**Files:**
- Modify: `config/filesystems.php` (a `media` disk whose driver and visibility come from env)
- Modify: `config/media-library.php` (`disk_name` reads the `media` disk)
- Modify: `app/Support/Media/*` (non-image types served with `Content-Disposition: attachment` when the disk is private)
- Test: `tests/Feature/Media/PrivateDiskTest.php`

- [ ] With `MEDIA_DISK=public` nothing changes for existing installs.
- [ ] With a private disk, every collection from this plan serves through signed URLs and non-image types download instead of rendering inline.

### Task 4: Guard the rule

**Files:**
- Create: `.ai/rules/file-uploads.md` (the durable-files rule and the two exemptions)
- Modify: `tests/Arch/ConventionsTest.php`
- Test: the arch test itself

- [ ] `tests/Arch` fails on a new `FileUpload::make` or `attachFiles(` outside `app/Livewire/App/Profile` (profile photos) and the medialibrary-backed components from tasks 1 and 2.

## Phase 2: agent uploads over MCP (spec section 7)

### Task 5: Team `agent-uploads` collection

**Files:**
- Modify: `app/Models/Team.php` (implement `HasMedia`, register `agent-uploads`)
- Modify: `config/media-library.php` (`custom_path_generators` for `Team` reuses `CustomFieldUploadPathGenerator`)
- Test: `tests/Feature/Mcp/UploadToolsTest.php`

- [ ] Media custom properties: `source` (`url`, `base64`, `signed_put`), `uploaded_by`, `original_name`.
- [ ] `max_file_size` stays at 10 MB and is the final size gate for every source.

### Task 6: `StoreAgentUpload` and `SsrfGuard::pinnedClient()`

**Files:**
- Create: `app/Actions/Upload/StoreAgentUpload.php` (`final readonly`, single `execute()`)
- Modify: `app/Services/Favicon/SsrfGuard.php` (add `pinnedClient()`)
- Test: `tests/Feature/Mcp/UploadToolsTest.php`, `tests/Feature/Services/SsrfGuardTest.php`

- [ ] `source_url`: https on 443 only, host resolved once, private and reserved ranges refused (IPv4 and IPv6, including IPv4-mapped), connect pinned to the resolved IP with the original `Host`, no redirects, 30 s timeout, 10 MB streaming ceiling into a temp file, then `addMedia`.
- [ ] `base64`: 5 MB decoded ceiling, then `addMediaFromBase64`.
- [ ] `upload_id`: temp path must exist under `tmp/`; `addMediaFromDisk` moves it into the collection.
- [ ] All sources: MIME sniffed with `finfo`; allowlist pdf, doc, docx, jpeg, png, gif, webp. svg and html never join the list. Final name `{ulid}.{ext}` from the sniffed type.
- [ ] Distinct `__()` errors: rate limited, file too large, MIME not allowed, URL unreachable, URL not allowed, upload not found or expired.

### Task 7: Signed PUT route and the two MCP tools

**Files:**
- Create: `app/Http/Controllers/Mcp/ReceiveUploadController.php`
- Modify: `routes/mcp.php` (`PUT /mcp/uploads/{upload}`, `signed` middleware, name `mcp.uploads.receive`)
- Create: `app/Mcp/Tools/CreateUploadUrlTool.php`, `app/Mcp/Tools/UploadFileTool.php`
- Modify: `app/Mcp/Servers/RelaticleServer.php` (register both, `create` ability)
- Modify: `bootstrap/app.php` (schedule the purge)
- Test: `tests/Feature/Mcp/UploadToolsTest.php`

- [ ] `create-upload-url`: input `filename`; output `{upload_id, url, headers, expires_at}`; URL is a 5-minute `temporarySignedRoute`.
- [ ] Receive controller rejects a missing or over-10 MB `Content-Length` before reading the body, streams to `uploads/custom-fields/tmp/{ulid}.{ext}`, returns 204.
- [ ] `upload-file`: exactly one of `source_url`, `base64` with `filename`, or `upload_id`; output `{file_id, path, url, mime_type, size, suggested_markdown}`.
- [ ] Per-team rate limit of 60 calls per hour on both tools, translated error.
- [ ] `app:purge-agent-uploads` removes `tmp/` entries older than 24 hours, hourly, `withoutOverlapping()->onOneServer()`.

### Task 8: `StoredUploadPath` rule and reads

**Files:**
- Create: `app/Rules/StoredUploadPath.php`
- Modify: `app/Rules/ValidCustomFields.php` (swap the package `file` rule for `file-upload`)
- Modify: `app/Http/Resources/V1/Concerns/FormatsCustomFields.php` (`file-upload` reads as `{path, url}`)
- Test: `tests/Feature/Mcp/CustomFieldWritesTest.php`, `tests/Feature/Api/V1/CustomFieldWritesApiTest.php`

- [ ] Accepted: a Media path in the caller's team `agent-uploads` collection; the record's current stored value on update.
- [ ] Rejected: another team's Media path, a `tmp/` path, a traversal path, a missing file.
- [ ] The MCP schema hint for `file-upload` already reads "path returned by the upload-file tool"; add the tool name to the resource `usage` string.

## Task 9: Whole-branch gate

- [ ] pint, rector, PHPStan, type coverage, `composer test:pest:full`.
- [ ] Browser pass: upload a file into a `file-upload` custom field and an image into a note body through the panel, then set the same field from MCP with an `upload-file` path.
- [ ] Deployment check (spec section 10): a 5 MB base64 payload is a 7 MB request body; confirm nginx `client_max_body_size` and PHP `post_max_size` allow at least 8 MB on the MCP host before release.
- [ ] #686 done-when: every durable path in the table reads medialibrary, one config change moves them to a private disk, the arch test guards new uploads.

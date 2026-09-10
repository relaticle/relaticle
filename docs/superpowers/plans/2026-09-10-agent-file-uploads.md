# Agent File Uploads Implementation Plan (Plan B: files)

> **For agentic workers:** REQUIRED: Use `sdd-lean` to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An AI agent working through the MCP server can hand Relaticle a file it holds or can point at, receive a stable path back, and set a `file-upload` custom field to that path. REST callers can set the same field with a path obtained through MCP.

**Status:** blocked on #686. The `file-upload` type is disabled product-wide (`config/custom-fields.php`, `->disabled(['file-upload'])`), and #686 moves its storage from a bare path string onto a medialibrary collection. Building the upload contract before that lands means building it twice. Tasks 1 to 4 below assume #686 item 1 (media-backed `file-upload` values) is merged.

**Architecture:** Two MCP tools (`create-upload-url`, `upload-file`) backed by one action, `App\Actions\Upload\StoreAgentUpload`. Uploads attach to `Team` in the `agent-uploads` medialibrary collection. `App\Rules\StoredUploadPath` replaces the package `file` rule for API, MCP and chat writes, so a `file-upload` value passes only when it names a Media row owned by the caller's team or echoes the record's current value. Remote fetches go through `SsrfGuard::pinnedClient()`.

**Tech Stack:** Laravel 12, laravel/mcp, spatie/laravel-medialibrary, Pest 4

**Spec:** `docs/superpowers/specs/2026-09-10-agent-custom-field-writes-design.md`, section 7 (tools and storage), section 9 (tests), section 10 (deployment check). Plan A (values) shipped in #698.

**Skills to activate:** `@mcp-development`, `@medialibrary-development`, `@testing-best-practices`, `@spatie-laravel-php`

---

## Global Constraints

- One contract for MCP and REST. `StoredUploadPath` runs on both; no MCP-only validation.
- Actions stay untouched. Validation happens before the action, in `ValidCustomFields`.
- Durable files go through medialibrary. The value stays a path string so the panel uploader keeps displaying it.
- Every user-facing string goes through `__()`.
- No new PHPStan ignores. 100% type coverage.

## Task 1: Team media collection and path generator

**Files:**
- Modify: `app/Models/Team.php` (implement `HasMedia`, register `agent-uploads` on the `public` disk)
- Create: `app/Support/Media/CustomFieldUploadPathGenerator.php`
- Modify: `config/media-library.php` (`custom_path_generators` for `Team`)
- Test: `tests/Feature/Mcp/UploadToolsTest.php`

- [ ] Files land at `uploads/custom-fields/{media_ulid}/{file_name}` so the panel `FileUpload` component previews them like its own uploads.
- [ ] Media custom properties: `source` (`url`, `base64`, `signed_put`), `uploaded_by`, `original_name`.
- [ ] `max_file_size` stays at 10 MB and is the final size gate for every source.

## Task 2: StoreAgentUpload action and SsrfGuard::pinnedClient()

**Files:**
- Create: `app/Actions/Upload/StoreAgentUpload.php` (`final readonly`, single `execute()`)
- Modify: `app/Services/Favicon/SsrfGuard.php` (add `pinnedClient()`)
- Test: `tests/Feature/Mcp/UploadToolsTest.php`, `tests/Feature/Services/SsrfGuardTest.php`

- [ ] `source_url`: https on 443 only, host resolved once, private and reserved ranges refused (IPv4 and IPv6, including IPv4-mapped), connect pinned to the resolved IP with the original `Host`, no redirects, 30 s timeout, 10 MB streaming ceiling into a temp file, then `addMedia`.
- [ ] `base64`: 5 MB decoded ceiling, then `addMediaFromBase64`.
- [ ] `upload_id`: temp path must exist under `tmp/`; `addMediaFromDisk` moves it into the collection.
- [ ] All sources: MIME sniffed with `finfo`; allowlist pdf, doc, docx, jpeg, png, gif, webp. svg and html never join the list. Final name `{ulid}.{ext}` from the sniffed type.
- [ ] Distinct `__()` errors: rate limited, file too large, MIME not allowed, URL unreachable, URL not allowed, upload not found or expired.

## Task 3: Signed PUT route and the two MCP tools

**Files:**
- Create: `app/Http/Controllers/Mcp/ReceiveUploadController.php`
- Modify: `routes/mcp.php` (`PUT /mcp/uploads/{upload}`, `signed` middleware, name `mcp.uploads.receive`)
- Create: `app/Mcp/Tools/CreateUploadUrlTool.php`, `app/Mcp/Tools/UploadFileTool.php`
- Modify: `app/Mcp/Servers/RelaticleServer.php` (register both, `create` ability)
- Test: `tests/Feature/Mcp/UploadToolsTest.php`

- [ ] `create-upload-url`: input `filename`; output `{upload_id, url, headers, expires_at}`; URL is a 5-minute `temporarySignedRoute`.
- [ ] Receive controller rejects a missing or over-10 MB `Content-Length` before reading the body, streams to `uploads/custom-fields/tmp/{ulid}.{ext}`, returns 204.
- [ ] `upload-file`: exactly one of `source_url`, `base64` with `filename`, or `upload_id`; output `{file_id, path, url, mime_type, size, suggested_markdown}`.
- [ ] Per-team rate limit of 60 calls per hour on both tools, translated error.
- [ ] `app:purge-agent-uploads` removes `tmp/` entries older than 24 hours; scheduled hourly in `bootstrap/app.php` with `withoutOverlapping()->onOneServer()`.

## Task 4: StoredUploadPath rule and the file-upload schema hint

**Files:**
- Create: `app/Rules/StoredUploadPath.php`
- Modify: `app/Rules/ValidCustomFields.php` (swap the package `file` rule for `file-upload`)
- Modify: `config/custom-fields.php` (re-enable `file-upload` once #686 lands)
- Test: `tests/Feature/Mcp/CustomFieldWritesTest.php`, `tests/Feature/Api/V1/CustomFieldWritesApiTest.php`

- [ ] Accepted: a Media path in the caller's team `agent-uploads` collection; the record's current stored value on update.
- [ ] Rejected: another team's Media path, a `tmp/` path, a traversal path, a missing file.
- [ ] The MCP schema hint for `file-upload` already reads "path returned by the upload-file tool"; keep it and add the tool name to the resource `usage` string.
- [ ] Reads return `{path, url}` through `FormatsCustomFields`, shared by REST and MCP.

## Task 5: Whole-branch gate

- [ ] pint, rector, PHPStan, type coverage, `composer test:pest:full`.
- [ ] Deployment check (spec section 10): a 5 MB base64 payload is a 7 MB request body; confirm nginx `client_max_body_size` and PHP `post_max_size` allow at least 8 MB on the MCP host before release.
- [ ] Record the file-upload rule in `.ai/rules/file-uploads.md`.

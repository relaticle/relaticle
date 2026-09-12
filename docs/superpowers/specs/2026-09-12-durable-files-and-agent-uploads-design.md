# Durable Files and Agent Uploads

Design for PR #699. Closes #686 and delivers the agent upload contract (Plan B of the agent custom-field work; Plan A shipped in #698).

## Goal

Every durable user file in Relaticle is a medialibrary Media row on the model that owns it. One config change moves all of them to a private disk. On top of that storage model, an AI agent working through the MCP server can hand Relaticle a file it holds or can point at, receive a stable path back, and set a `file-upload` custom field to that path.

## Decisions

Recorded 2026-09-12 with the founder.

1. One PR, one plan. Phase 1 (#686) and Phase 2 (agent uploads) ship together on `feat/agent-file-uploads`.
2. Build on relaticle/custom-fields 3.9.1. The 4.0 program (#597) has no code timeline.
3. Rich editor attachments use the component closure hooks, not Filament's `HasRichContent` provider route (spike result below).
4. An upload is owned by the `Team` while pending and moves to the record when a saved value references it. One field, one owner.
5. This spec replaces the spec the 2026-09-10 plan cited, which never existed.
6. #606 (team branding) stores its logo in a `logo` collection on `Team` before this PR lands. No arch-test exemption.

## Facts the design rests on

Verified 2026-09-12 in this checkout and in production.

- Stack: Laravel 13.31, Filament 5.8.1, Pest 5.1.4, relaticle/custom-fields 3.9.1, spatie/laravel-medialibrary 11.23.7, laravel/mcp 0.9.5.
- `file-upload` is disabled product-wide (`config/custom-fields.php:72`). Production holds 0 fields and 0 values of that type, so there is nothing to backfill.
- Production holds 30,472 rich-editor values; 15 contain `<img>`. Those images went through Filament's default flow: `data-id` is a bare filename on the public disk and `src` is the absolute public URL.
- `media` has 9,264 rows, all in the `logo` collection on `Company`, on the default disk. `Company` has no `registerMediaCollections()`.
- `media.id` is a bigint. medialibrary sets `media.uuid` on create (`HasUuid.php:16`). Paths in this design key on `uuid`.
- `Media::move()` copies to a new row and force-deletes the old one (`Media.php:457`), so it changes `uuid` and path. Ownership changes in this design are attribute writes on the existing row.
- Filament's `FileAttachmentProvider` only resolves through `HasRichContent` on the model (`RichEditor.php:886`). Note body and task description are custom-field values, not model attributes, and the create-page path calls `$record->setAttribute(<attribute>, ...)` (`RichEditor.php:655`), which the custom-fields trait cannot absorb. The component-level closures `saveUploadedFileAttachmentUsing` and `getFileAttachmentUrlUsing` (`HasFileAttachments.php:143,160`) run at upload time, before any record exists.
- Stored rich-editor HTML carries `data-id` and a fixed `src`. `RichContentRenderer` rewrites `src` from `data-id` at render time (`RichContentRenderer.php:246`); the package `HtmlEntry` renders the stored HTML raw. Chat strips tags and never renders images.
- The package `FileUploadComponent` hardcodes `disk => 'public'` and `uploads/custom-fields`.
- `SsrfGuard` allows http and follows redirects with per-hop validation. Agent uploads need a stricter client.
- MCP routes live in `routes/ai.php`; there is no `routes/mcp.php`.
- Chat defers `file-upload` (`ProposalFieldSchemaDescriber::isDeferred`), so chat cannot set the field today and gets no upload tool here.

## Prerequisites and merge order

1. #695 (document-style rich editor) creates `app/Filament/CustomFields/RichEditorFieldType.php`, the class this PR extends, and edits the `->disabled([...])` line this PR edits. It is mergeable against `main` and merges cleanly into this branch (checked 2026-09-12 at `0c4e8e38d`). #695 merges first; this branch rebases on `main` after that.
2. #606 (team branding) is reworked to a `logo` collection on `Team` with `Team implements HasMedia`. That branch owns the `HasMedia` change on `Team`; this PR adds collections to it. #606 merges before this PR.
3. #699 still targets `feat/custom-fields-agent-friendly-writes`, merged as #698. Retarget to `main` needs the founder's say-so.

## Storage model

### Collections

| Owner | Collection | Holds |
|---|---|---|
| `Company` | `logo` | unchanged |
| `Team` | `logo` | from #606 |
| `Team` | `pending-uploads` | every upload before a saved value claims it |
| record (`Company`, `People`, `Opportunity`, `Task`, `Note`) | `custom-field-{code}` | the file behind a `file-upload` value, or the inline images of a rich-editor value |

`Team` and the five CRM models implement `HasMedia`. `Company` already does.

### Paths

One app path generator replaces `DefaultPathGenerator`. For the `logo` collection it delegates to the default layout, so the 9,264 existing files stay put. For every other collection it returns `uploads/{uuid}/`. The path is model-independent, so a change of owner never moves the file.

The stored `file-upload` value is the Media path relative to the media disk, `uploads/{uuid}/{file_name}`. It is derived from the Media row, never the other way round.

### Lifecycle

1. Upload. Any entry point (panel `FileUpload`, panel rich editor, MCP `upload-file`) creates a Media row in the caller's `Team` `pending-uploads` collection with custom properties `uploaded_by`, `source` (`panel`, `url`, `base64`, `signed_put`), and `original_name`. medialibrary `max_file_size` (10 MB) is the final size gate on every source.
2. Claim. When a custom-field value is saved, an observer on the app `CustomFieldValue` model claims what the value references. For `file-upload` it looks up one pending Media by path; for `rich-editor` it collects every `data-id`. Each pending row owned by the same team is reassigned in place into the field's own `custom-field-{code}` collection: `model_type`, `model_id`, `collection_name` change, `uuid` and file do not. Media already on the record stays. One collection per field keeps a release on one rich-editor field from touching images another field on the same record still references.
3. Release. The same observer deletes record-owned media the new value no longer references: the replaced file on a `file-upload` field, images removed from a rich-editor body. Deleting the record deletes its media through `InteractsWithMedia`.
4. Purge. `app:purge-pending-uploads` deletes `pending-uploads` rows older than 24 hours and signed-PUT temp files under `tmp/` on the private `local` disk. Hourly, `withoutOverlapping()->onOneServer()`. Pending rows are never deleted by content scanning; two users drafting in one team would delete each other's images.

Claiming is a write after validation. It lives in the observer, not in actions, so panel, API, MCP and chat approval all get it without touching any action class.

## Custom field `file-upload`

`App\Filament\CustomFields\FileUploadFieldType` extends the package type and swaps the form component for one built on Filament's `FileUpload` with three closures: `saveUploadedFileUsing` adds the file to `pending-uploads` and returns the Media path, `getUploadedFileUsing` resolves a path to its Media URL and name, `deleteUploadedFileUsing` deletes the pending row. Accepted types, size, preview and download settings match the package defaults. Registered through `CustomFieldsType::register()` next to `DateFieldType`. `file-upload` leaves the disabled list.

Table column and infolist entry render the file name as a link to the Media URL.

## Rich editor attachments

The editor is the app's own `RichEditorFieldType` from #695: no toolbar, a `/` menu built from Filament's `RichEditorTool` registry, `->fileAttachments(true)`, and `AttachFilesAction` reachable from the menu. Pasting and the attach modal both end in `saveUploadedFileAttachment`, so one wiring point covers both.

`App\Support\Media\RichContentAttachments` implements Filament's `FileAttachmentProvider` once. `RichEditorFieldType` wires its methods into `saveUploadedFileAttachmentUsing` and `getFileAttachmentUrlUsing`, sets `fileAttachmentsMaxSize(10240)` so the editor's 12 MB default cannot pass a file medialibrary's 10 MB ceiling rejects, and keeps the image-only accepted types. `data-id` is the Media `uuid`.

An agent that embeds an `upload-file` result writes markdown, which becomes `<img src="...">` with no `data-id`. `CustomFieldInput::richText()`, the normalizer REST and MCP share, adds `data-id="{uuid}"` to any untagged `<img>` whose `src` names a `/uploads/{uuid}/` or `/media/{uuid}` path the team owns, so the claim finds it.

`getFileAttachmentUrl(id)` treats the id as untrusted client input. It resolves a uuid only when the row belongs to the current tenant, in `pending-uploads` or on a record the tenant owns. Anything else returns null.

Render surfaces:

| Surface | Today | After |
|---|---|---|
| Panel infolist | package `HtmlEntry`, raw HTML | app `RichContentEntry` rendering through `RichContentRenderer` with the slash-menu plugin's TipTap extensions and `fileAttachmentProvider()`; the table column strips tags and needs no change |
| REST API and MCP read tools | stored HTML | stored HTML with `src` rewritten by the same provider at read time |
| Chat | `strip_tags` | unchanged |

`media:backfill-rich-editor-attachments` migrates the 15 legacy values: create a Media row in the field's `custom-field-{code}` collection on the owning record from the public file, rewrite `data-id` to the uuid and `src` to the Media URL. Idempotent, dry-run by default.

## One switch to a private disk

`config/filesystems.php` gains a `media` disk whose driver and root come from env. It is private by definition: a public `media` disk would need its own `url` and symlink, and `public` already covers that case. The switch is forward-only. A Media row keeps the disk it was uploaded to, so `MEDIA_DISK=media` is set before the first upload on an install; no move command exists because production holds no uploads yet. Conversions and responsive images are not served through the signed route; nothing on this branch registers one, and the first conversion added needs a `conversion` parameter on the route. `config/media-library.php` `disk_name` reads `MEDIA_DISK`, default `public`; `.env.example` documents it.

An app URL generator returns the plain disk URL when the disk is public and a 30-minute signed link to `GET /media/{uuid}` when it is private. That route streams through the disk on any driver: images inline, every other type with `Content-Disposition: attachment`, the download named after the upload's `original_name`, `Cache-Control: no-store, private`, and a 60-per-minute throttle. The `upload-file` tool's `suggested_markdown` carries this expiring URL; `CustomFieldInput::richText()` tags such a `/media/{uuid}` image with `data-id` on write, and reads rewrite `src` from the Media row, so the stored link never has to outlive its signature.

The `logo` collections on `Company` and `Team` stay on the public disk through `useDisk('public')` in `registerMediaCollections()`. They feed every avatar in pickers, lists and search, and plain cacheable URLs matter more there than the private-disk option. Every collection this PR creates follows `MEDIA_DISK`. Decided 2026-09-12.

## Agent uploads over MCP

### Tools

`create-upload-url` takes `filename` and returns `{upload_id, url, headers, expires_at}`. The URL is a 5-minute `temporarySignedRoute` to `PUT /mcp/uploads/{upload}` in `routes/ai.php`. The receive controller rejects a missing or over-10 MB `Content-Length` before reading the body, streams to `tmp/{ulid}.{ext}` on the private `local` disk (the body is unauthenticated and unvalidated until `upload-file` sniffs it), and returns 204.

`upload-file` takes exactly one of `source_url`, `base64` plus `filename`, or `upload_id`, and returns `{file_id, path, url, mime_type, size, suggested_markdown}`. `path` is the value to put in a `file-upload` field.

Both register on `RelaticleServer` under the `create` ability with a per-team limit of 60 calls per hour and a translated error.

### `StoreAgentUpload`

`App\Actions\Upload\StoreAgentUpload`, `final readonly`, single `execute()`, authorization inside.

- `source_url`: https on 443 only, host resolved once, private and reserved ranges refused for IPv4 and IPv6 including IPv4-mapped, connection pinned to the resolved IP with the original `Host`, no redirects, 30 s timeout, 10 MB streaming ceiling into a temp file. Lives in `SsrfGuard::pinnedClient()`.
- `base64`: 5 MB decoded ceiling.
- `upload_id`: temp path must exist under `tmp/`; the file moves into the collection.
- All sources: MIME sniffed with `finfo`; allowlist pdf, doc, docx, jpeg, png, gif, webp. svg and html never join the list. Final name `{ulid}.{ext}` from the sniffed type.
- Distinct `__()` errors: rate limited, file too large, MIME not allowed, URL unreachable, URL not allowed, upload not found or expired.

## `StoredUploadPath` rule and reads

`App\Rules\StoredUploadPath` replaces the package `file` rule for `file-upload` in `ValidCustomFields`, so REST, MCP, and chat's `CustomFieldsRequestValidator` share it.

Accepted: a path that resolves to a Media row in the caller's team `pending-uploads`, or the record's current value on update. Rejected: another team's path, a `tmp/` path, a traversal path, a missing row.

`FormatsCustomFields` returns `file-upload` as `{path, url}`. The MCP schema hint already names the `upload-file` tool; the resource `usage` string gains it too.

## Guard

`.ai/rules/file-uploads.md` states the rule: durable user files go through medialibrary with a named collection on the owning model; import CSVs under `storage/app/imports` and Jetstream profile photos are exempt. `tests/Arch/ConventionsTest.php` fails on a new `FileUpload::make` or `attachFiles(` outside `app/Livewire/App/Profile` and the two media-backed components above.

## Testing

Feature tests through real entry points, per the testing rules:

- Panel: upload into a `file-upload` field creates a pending row, saving claims it onto the record, replacing releases the old one, deleting the record deletes its media.
- Panel: pasting an image into a note body on create and on edit, claim on save, release on removal, rendered `src` resolves through the provider.
- MCP: both tools, every `StoreAgentUpload` branch, rate limit, signed PUT size gate, purge command.
- REST and MCP writes: every accept and reject case of `StoredUploadPath`, read shape `{path, url}`.
- Private disk: with `MEDIA_DISK=public` nothing changes; with a private disk every collection from this design serves signed URLs and non-image types download.
- Arch: the guard fails on a new `FileUpload::make`.
- Browser pass before merge: panel file field, panel inline image, then the same field set from MCP with an `upload-file` path.

## Outside this PR

- Chat gets no upload tool. `file-upload` stays deferred there.
- Retargeting #699 to `main`.
- This workspace's branch is `feat/agent-file-uploads-v1`; the PR head is `feat/agent-file-uploads`. Pushes go `git push origin HEAD:feat/agent-file-uploads`.
- Deployment check: a 5 MB base64 payload is a 7 MB request body. nginx `client_max_body_size` and PHP `post_max_size` on the MCP host must allow at least 8 MB before release.

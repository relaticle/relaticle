# Durable Files and Agent Uploads

Design for PR #699. Closes #686 and delivers the agent upload contract (Plan B of the agent custom-field work; Plan A shipped in #698).

## Goal

Every durable user file in Relaticle is a medialibrary Media row on the model that owns it, on a private disk by default. On top of that storage model, an AI agent working through the MCP server can hand Relaticle a file it holds or can point at, receive a stable id back, and embed it in a rich-editor field.

## Decisions

Recorded 2026-09-12 with the founder, revised 2026-09-14 after a decision-by-decision review.

1. One PR, one plan. Phase 1 (#686) and Phase 2 (agent uploads) ship together on `feat/agent-file-uploads`.
2. Build on relaticle/custom-fields 3.9.1. The 4.0 program (#597) has no code timeline.
3. Rich editor attachments use the component closure hooks, not Filament's `HasRichContent` provider route. Note body and task description are custom-field values, not model attributes, so the provider has nothing to attach to.
4. An upload is owned by the `Workspace` while pending and moves to the record when a saved value references it. One record, one owner.
5. `media` carries one real column, `workspace_id` (indexed). Tenant scoping never reads a JSON property. A `custom_field_id` column was dropped after review: with one rich-editor field per entity today it discriminated nothing, and scoping the release to a single field made a release bug delete a sibling field's files. The release now reads every rich-editor value on the record.
6. A stored reference to a file is the Media `uuid`. Paths are derived from the row, never stored.
7. One `attachments` collection per record. A file belongs to the record, not to a field, so the same image can appear in two rich-editor fields and survives until no value names it.
8. Uploads are private by default. `MEDIA_DISK` names an existing disk from `config/filesystems.php`, default `local`. `logo` collections stay on `public`.
9. There is no `file-upload` custom field type. The components are removed and the type is disabled in config; rich-editor attachments and the MCP upload tools are the file surface. `CustomFieldType::FILE_UPLOAD` stays so code that excludes the type still compiles.
10. The agent contract returns a stable, unsigned `/media/{uuid}` link in `suggested_markdown`. Only rendering surfaces sign.
11. `SsrfGuard` and `HostResolver` live in `App\Support\Http`, shared by favicon fetching and agent uploads.

## Facts the design rests on

Verified 2026-09-12 in this checkout and in production.

- Stack: Laravel 13.31, Filament 5.8.1, Pest 5.1.4, relaticle/custom-fields 3.9.1, spatie/laravel-medialibrary 11.23.7, laravel/mcp 1.0.0. `filament/spatie-laravel-media-library-plugin` 5.8.1 is installed and serves the workspace logo.
- Production holds 0 fields and 0 values of type `file-upload`, so there is nothing to backfill.
- Production holds 30,472 rich-editor values; 15 contain `<img>`. Those images went through Filament's default flow: `data-id` is a bare filename on the public disk and `src` is the absolute public URL.
- `media` has 9,264 rows, all in the `logo` collection on `Company`, on the public disk.
- `media.id` is a bigint. medialibrary sets `media.uuid` on create. Values and paths key on `uuid`.
- `Media::move()` copies to a new row and force-deletes the old one, so it changes `uuid` and path. Ownership changes are attribute writes on the existing row.
- A user-defined custom field's `code` is editable after creation (`FieldForm.php` disables it only for system fields).
- Panel Create and Edit actions run in a database transaction, and `CustomFieldValue::save()` opens one regardless, so the row lock the `saving` observer takes is held to commit.
- Production is one Forge host. Signed-PUT bodies land on that host's `local` disk and are read back from it.
- Chat defers `file-upload` (`ProposalFieldSchemaDescriber::isDeferred`), so chat cannot set the field and gets no upload tool here.

## Storage model

### Columns and collections

`media.workspace_id` is set on every upload this design creates. Logo rows keep it null.

| Owner | Collection | Holds |
|---|---|---|
| `Company`, `Workspace` | `logo` | unchanged, public disk |
| `Workspace` | `pending-uploads` | every upload before a saved value claims it |
| record (`Company`, `People`, `Opportunity`, `Task`, `Note`) | `attachments` | the inline images and linked documents of every rich-editor value on the record |

### Paths

`UploadPathGenerator` keeps the default layout for `logo` so the 9,264 existing files stay put, and returns `uploads/{uuid}/` for everything else. The path is model-independent, so a change of owner never moves the file.

### Lifecycle

1. Upload. Any entry point (panel `FileUpload`, panel rich editor, MCP `upload-file`) creates a Media row in the caller's workspace `pending-uploads` collection. `name` is the original file name; custom properties carry `uploaded_by` and `source` (`panel`, `url`, `base64`, `signed_put`). medialibrary `max_file_size` (10 MB) is the size gate on every source.
2. Validate. `UploadClaims::assertClaimable()` runs from the `saving` observer hook. A referenced uuid must be pending in the caller's workspace or already an attachment on this record, so a race between two saves cannot persist a value that names a file another record claimed.
3. Claim. The `saved` observer hook reassigns every referenced pending row in place: `model_type`, `model_id`, `collection_name`. `uuid` and file do not change. A rich-editor image another workspace owns is left where it is.
4. Release. After commit, the record's `attachments` that no rich-editor value on it still references are deleted. Deleting the record deletes its media through `InteractsWithMedia`.
5. Purge. `app:purge-pending-uploads` deletes `pending-uploads` rows older than 24 hours and signed-PUT temp files under `tmp/` on the `local` disk. Hourly.

## Rich editor attachments

`App\Support\Media\RichContentAttachments` implements Filament's `FileAttachmentProvider`. `data-id` is the Media `uuid`. Reads (`FormatsCustomFields`, the record page) rewrite `src` from the row. `CustomFieldInput::richText()` tags an untagged `<img>` whose `src` names `/media/{uuid}` or `/uploads/{uuid}/` for a row the workspace owns, so an agent's markdown claims on save.

`media:backfill-rich-editor-attachments --force` migrates the 15 legacy values onto their records. Idempotent, dry-run by default.

## Serving

`MediaUrlGenerator` returns the plain disk URL when the row's disk is public and a 30-minute signed link to `GET /media/{uuid}` otherwise. The route streams through the disk: images inline, every other type as an attachment named after the upload, `Cache-Control: no-store, private`, 300 requests per minute.

The native alternative is `Storage::temporaryUrl()`. It skips the PHP round trip on S3 but cannot set the attachment disposition or the original file name. When S3 arrives, the generator can branch on `providesTemporaryUrls()` for images and keep the route for downloads.

## Agent uploads over MCP

`create-upload-url` takes `filename` and returns a 5-minute signed `PUT /mcp/uploads/{upload}` URL. The `upload_id` is `{workspace}.{ulid}.{slug}.{ext}`, so `upload-file` can name the media after the file the agent asked to upload even when it passes no `filename`. The slug is the only part derived from user input and is restricted to `[a-z0-9-]{1,60}`. The receiver rejects a missing or over-10 MB `Content-Length` before reading, streams to `tmp/{workspace}.{ulid}.{ext}` on the `local` disk, and answers 204.

`upload-file` takes exactly one of `source_url`, `base64` plus `filename`, or `upload_id`, and returns `{file_id, name, url, mime_type, size, suggested_markdown}`. `url` is signed and expires. `suggested_markdown` carries the stable `/media/{uuid}` link. 60 calls per hour per workspace through `RateLimiter::increment`, which counts refused files too.

`StoreAgentUpload`: `source_url` is https on 443 only, resolved once, private ranges refused, connection pinned to the checked address, no redirects, 10 MB streaming ceiling. All sources are sniffed with `finfo` against the allowlist: pdf, doc, docx, xlsx, pptx, jpeg, png, gif, webp. svg and html never join it.

## Guard

`.ai/rules/file-uploads.md` states the rule. `tests/Arch/ConventionsTest.php` fails on a new `FileUpload::make` or attachment disk configuration outside the rich editor field type and the profile photo form.

## Outside this PR

- Chat gets no upload tool. The rich editor is the only file surface in the panel, and chat cannot set a rich-editor attachment.
- Deleting a rich-editor custom field releases its images on the next save of any rich-editor value on that record, because the deleted field's value no longer contributes references.
- Logo rows keep `workspace_id` null, so the column cannot become `NOT NULL` without a backfill.
- Deployment check: a 10 MB base64 payload is a 14 MB request body. nginx `client_max_body_size` and PHP `post_max_size` on the MCP host must allow at least 14 MB.

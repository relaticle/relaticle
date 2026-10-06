---
paths:
  - 'app/Filament/**'
  - 'app/Livewire/**'
  - 'app/Support/Media/**'
  - 'app/Actions/Upload/**'
  - 'app/Mcp/Tools/**'
  - 'app/Http/Controllers/Media/**'
  - 'app/Observers/**'
  - 'app/Jobs/FetchFaviconForCompany.php'
  - 'app/Services/Favicon/**'
  - 'app/Console/Commands/**'
  - 'packages/Chat/src/**'
  - 'packages/ImportWizard/src/**'
  - 'packages/EmailIntegration/src/Livewire/**'
  - 'packages/EmailIntegration/src/Services/EmailTemplateRenderService.php'
---

# File uploads

Durable user files go through medialibrary with a named collection on the owning
model (`App\Enums\MediaCollection`). Three exemptions: import CSVs under
`storage/app/imports` (transient), Jetstream profile photos (framework-owned),
and outbound email compose/template images on `EmailAttachment::DISK`.

- `media.workspace_id` is a real column. Scope every media query on it, never on a
  `custom_properties` JSON path. There is deliberately no `media.custom_field_id`:
  an attachment belongs to the record, not to one field.
- There is no `file-upload` custom field type. Rich-editor attachments and the
  MCP upload tools are the only ways a user file reaches a record; the type is
  listed in `->disabled()` in `config/custom-fields.php` so the package's own
  version never resolves either.
- Every upload lands in the workspace's `pending-uploads` collection first
  (`App\Actions\Upload\StorePendingUpload`); `logo` collections are written
  directly. `App\Support\Media\UploadClaims` claims a pending row into the
  record's `attachments` collection when a saved value references it, and releases
  the record's attachments that NO rich-editor value on it still names. A release
  must therefore read every rich-editor value on the record: scoping it to the one
  being saved deletes the other fields' files. The `saving` observer hook refuses a
  body embedding an image another record owns.
- Rich-editor images go through `App\Support\Media\RichContentAttachments`,
  the Filament `FileAttachmentProvider` behind `RichEditorFieldType` and
  `RichContentEntry`. `data-id` is the Media `uuid`; reads rewrite `src` from the
  row, and `CustomFieldInput::richText()` tags an untagged `<img>` whose URL
  names an owned upload so the claim finds it. A bare-filename `data-id`, or an
  `<img>` with no `data-id` whose `src` sits under `/storage/`, is a legacy
  Filament upload on the public disk; `media:backfill-rich-editor-attachments
  --force` moves those onto their records.
- Never call `Media::move()`. It copies and deletes, changing `uuid` and path.
  Ownership changes are attribute writes on the existing row.
- A company logo is replaced only on evidence. `HighQualityDriver::fetch()` returns null when a
  site answers and no source has an icon, and throws the fetcher's `ConnectionException` when the
  host does not resolve or the site is unreachable with no fallback icon. `FetchFaviconForCompany`
  drops the logo of a previous domain on null and keeps it on a throw. Catching the exception
  inside the driver turns a dead host into "no favicon" and wipes a working logo.
  `tests/Feature/Services/Favicon/HighQualityDriverTest.php` and
  `tests/Feature/Jobs/FetchFaviconForCompanyTest.php` fail when either half changes.
- `logo` collections stay on the public disk. Everything else, `chat-attachments`
  included, follows `MEDIA_DISK`, default `local`, which must name a disk in
  `config/filesystems.php`. A row keeps the disk it was uploaded to.
- A parser that needs a filesystem path reads through `App\Support\Media\LocalCopy`,
  never `Media::getPath()` or `getRealPath()`: on a bucket disk those name no local file.
- Import store writes go through `ImportStore::withWriteLock()`, reads through `forRead()`.
  Never dispatch a job inside the lock closure: under the sync queue it waits on that lock.
- A chat CSV attachment belongs to its `AgentConversation` (`chat-attachments`
  collection), never to the workspace: an upload made before the first message opens
  the conversation so the row has its owner from the start, and deleting the
  conversation deletes the file. It is parsed from a temp copy
  (`ChatAttachment::withLocalFile()`) and handed to the import wizard, never served, and
  it stays out of `pending-uploads`, whose allowlist is documents and images. `sent_at`
  in `custom_properties` is metadata (the single-use guard), not ownership. Only
  uploads nobody sent within a day are deleted, by `chat:purge-unsent-attachments`. It
  still carries `workspace_id`, so workspace deletion sweeps it with everything else.
- Do not add a `FileUpload::make(` or configure rich editor attachments with
  `fileAttachmentsDisk(` / `fileAttachmentsDirectory(` outside
  `app/Filament/CustomFields/RichEditorFieldType.php`,
  `app/Livewire/App/Profile/UpdateProfileInformation.php`,
  `packages/EmailIntegration/src/Livewire/EmailComposer.php`, and
  `packages/EmailIntegration/src/Services/EmailTemplateRenderService.php`.
  `tests/Arch/ConventionsTest.php` fails on it.

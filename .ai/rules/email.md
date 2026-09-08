---
paths:
  - 'app/Jobs/Email/**'
  - 'app/Support/Email/**'
---

# Email (Mailcoach subscriber sync)

## Mailcoach validates email against DNS, and a 422 is permanent
Mailcoach's hosted API rejects any subscriber `email` whose domain has no resolvable MX or A record (parked domains with null MX `0 .`, or domains whose nameservers were dropped) with HTTP 422 `InvalidData`, even when it already stores that exact address. `email` is required on `PATCH /subscribers/{uuid}` (Mailcoach API docs), so the update path cannot omit it to dodge the check. A 422 therefore never clears on retry: `SyncSubscriberJob` catches `InvalidData`, records the hash in `rejected_subscriber_profile_hash` so `subscribers:reconcile` stops re-offering the same profile every night, and fails once. Do not add retries, `retryUntil`, or throttling middleware around Mailcoach writes (PR #555 explains why), and do not branch on the `email` key: every 422 is permanent for its payload.

## subscriber_profile_hash caches what Mailcoach holds; rejections live in their own column
`subscriber_profile_hash` is only ever written together with `mailcoach_subscriber_uuid` after a successful write, so it always describes the profile Mailcoach actually stores. Never write it on a failed write. A 422 goes to `rejected_subscriber_profile_hash` instead; `SubscriberProfile::needsSync()` treats a profile as settled when it matches either column, so a rejected user is re-offered only once their derived profile changes (an email fix, a new tag). To force a retry after fixing a payload bug on our side, null that column for the affected users.

# Custom Fields

- Models using the `UsesCustomFields` trait handle `custom_fields` automatically — do NOT manually extract, strip, or call `saveCustomFields()` in actions
- The trait merges `'custom_fields'` into `$fillable`, intercepts it during `saving`, and persists values during `saved` — just pass `custom_fields` through in the `$data` array to `create()`/`update()`
- Tenant context for the custom-fields package is set in `SetApiTeamContext` middleware via `TenantContextService::setTenantId()` — actions don't need `withTenant()` wrappers
- In Filament, the package's own `SetTenantContextMiddleware` handles tenant context — no action-level code needed there either
- `CustomFieldValidationService` intentionally uses explicit `where('tenant_id', ...)` with `withoutGlobalScopes()` — this is defensive and correct, don't change it to rely on ambient state
- Every write path that is NOT a Filament panel request or behind `SetApiTeamContext` (chat action approval, queued jobs, webhooks, commands) must set `TenantContextService::setTenantId()` before saving custom fields — otherwise `saveCustomFields` iterates every tenant (gateway timeouts + cross-tenant writes). Wrap manual calls in try/finally restoring the previous tenant id (mirror `SetApiTeamContext`)
- Writing null/empty for a custom field is how a value is cleared — never skip or filter out "empty" values on save; only keys absent from the payload are left untouched. Verify any persistence change in both directions (set a value AND clear it), through both the panel form and the chat/API path

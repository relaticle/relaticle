# Custom Fields

- Models using the `UsesCustomFields` trait handle `custom_fields` automatically — do NOT manually extract, strip, or call `saveCustomFields()` in actions
- The trait merges `'custom_fields'` into `$fillable`, intercepts it during `saving`, and persists values during `saved` — just pass `custom_fields` through in the `$data` array to `create()`/`update()`
- Tenant context for the custom-fields package is set in `SetApiTeamContext` middleware via `TenantContextService::setTenantId()` — actions don't need `withTenant()` wrappers
- In Filament, the package's own `SetTenantContextMiddleware` handles tenant context — no action-level code needed there either
- `CustomFieldValidationService` intentionally uses explicit `where('tenant_id', ...)` with `withoutGlobalScopes()` — this is defensive and correct, don't change it to rely on ambient state

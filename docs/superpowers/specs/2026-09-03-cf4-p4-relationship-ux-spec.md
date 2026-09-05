# Custom Fields 4.0, Phase 4: Relationship UX and Table Surfaces

Status: approved design. Program: Custom Fields 4.0 (phase 4 of 5).
Plans: 2026-09-03-cf4-p4.1-relationship-ux.md, 2026-09-03-cf4-p4.2-through-relations.md,
2026-09-03-cf4-p4.3-bulk-paste.md. Depends on the phase 3 substrate.
Tracking: relaticle/custom-fields#210.

## Decisions

1. UI: a best-in-class relationship experience, a full field-management polish pass, and a
   dual flavor registry. The registry was re-examined in the 2026-09-01 architecture review
   and kept; its cost is bounded by the rule in 3.4.
2. Two ergonomics features ride the major rather than shipping as 3.x minors: through-relation
   table surfaces (custom-fields#53, section 3.5) and bulk paste for choice options
   (section 3.3). #53 must wait for phase 3, because the substrate rewrites the sort and
   filter paths it hooks into and the builder verb has to be named while "relationship" is
   being redefined. Bulk paste has no such dependency; it rides the major by decision, so the
   whole options editor changes once (with the phase 2 category column).

## 3.1 Relationship creation

Create-attribute flow, type Relationship: two entity cards with the cardinality selector
between them reading as a sentence; per-side associated attribute name inputs with smart
defaults; the sync banner as education; a symmetric toggle when both ends match, collapsing
to one name input; cmd+enter submits; one transaction. The machine code is auto-generated,
editable under an Advanced disclosure. This replaces the stock record-config form phase 3
shipped.

## 3.2 Record-side

Record chips with avatars and click-through; ordered chips with +N overflow, never a bare
count. Picker: debounced instant search, avatars, keyboard navigation, inline create-new.
Unlink on the chip; 1:1 steal confirms inline. Provenance on hover from the ledger
(changed by Chat Assistant, 2d ago).

## 3.3 Field-management polish

Searchable type-picker grid with icons and descriptions. A modern attribute table:
reorder handles, type icons, badges, inline activate; relationship pairs visually connected.
Educational empty states. Slide-over editing, cmd+enter, skeletons, dark-mode audit.

Bulk paste for choice options: a hint action on the options repeater opens a textarea, one
option name per line, appended as deduplicated rows. Today a 200-value vocabulary is 200
clicks through the add-option button. Names only; the color column is conditionally visible,
so a two-column paste format would read wrong on most tenants. State is appended through the
repeater (generated item keys, child-schema fill), never by writing option models directly,
so tenant stamping and sort_order keep working. A cap protects the Livewire payload.

## 3.4 Flavor registry

Two presentation flavors over one logic layer:

- polished (default): the experience above, custom Blade views where Filament has no primitive.
- native: same Livewire classes, stock Filament views, for hosts that want visual uniformity.

Config: ui.flavor plus a per-component override map. Rule: logic never forks; only views fork,
and only for the surfaces where polished is custom (configurator, chips and picker,
type-picker grid, attribute table). Critical browser paths run in both flavors in CI.
Cost accepted: 15 to 20 percent on UI work. The registry is built first so every surface
routes through it from the start.

## 3.5 Through-relation table surfaces

Issue #53. A relation manager whose record does not itself hold custom fields cannot show the
related record's fields today: every column resolver type-hints HasCustomFields on the row
record. The table builder gains a through path:

    CustomFields::table()->forModel(Member::class)->through('member')->columns()

Scope wall: to-one relations only (BelongsTo, HasOne, MorphOne). Filters generalize to any
relation via whereHas, but sorting does not, because a to-many relation gives no single value
to sort by. The API rejects a to-many path rather than documenting the limitation later.

Mechanics, verified against Filament 5:

- Columns need no interface change. getStateUsing, sortable(condition, query) and
  searchable(condition, query) are idempotent public setters, so TableBuilder re-wraps the
  factory-built column exactly as it already re-wraps formatStateUsing.
- Filters do need one. modifyQueryUsing is protected with no public getter, and each filter
  type builds a different query, so the path must reach the filter's own make(). It rides the
  TableFilterInterface break in phase 1; the interface must not break twice in one major.
- Visibility conditions evaluate against the related record, not the row record. TableBuilder
  passes $record->{$relation} to BackendVisibilityService or columns silently mis-hide.
- Sorting a BelongsTo needs no join: entity_id correlates to the foreign key already on the
  main table. HasOne and MorphOne take one more hop.
- Eager loading is the host's job and must be documented. Column names are already dotted
  (custom_fields.code), so Filament's relationship inference contributes nothing here, and
  under strict lazy loading an N+1 is a 500, not a slow page.

Record-type columns through a relation are display and filter only in 4.0; sorting them means
joining the link ledger through a second relation hop, which is not worth the first release.

## 3.6 Verification

Every screen: agent-browser click-through, light and dark, mobile viewport, empty states,
before any done claim. Package UI stays themeable; no Relaticle styling leaks in.

## Breaking change owned by this phase

The UI flavor registry config surface.

## Testing strategy

Both UI flavors on the critical browser paths in CI; Livewire tests per surface; through
relations per relation kind and per filter type, including the visibility case and the
null related record; bulk paste parser and repeater-state tests.

## Exit criterion

Polished UI in both flavors, #53 and bulk paste shipped, `4.0.0-beta.1` tagged.

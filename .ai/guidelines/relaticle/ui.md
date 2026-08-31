# UI

## Verifying visual work

- Verify every visual change with an agent-browser screenshot (light + dark, and
  mobile viewport where relevant) before reporting it done. Never make the user
  act as the renderer.
- Any change to a Blade view, Livewire component, or Filament page must be
  clicked through with agent-browser (including empty-state data) before being
  reported done. Tests passing is not sufficient for UI work.

## Marketing & demo surfaces

- Mockups of the product (hero tabs, demos) mirror the real app UI 1:1.
  Screenshot the actual app first and match sidebar, spacing, and component
  placement. External sites (e.g. attio.com) are inspiration for concept only,
  never for visual specifics.
- Use design tokens from `resources/css/theme.css`; don't introduce ad-hoc pixel
  values or colors without a semantic token.
- Demo/example content (names, companies, conversations) must read like real CRM
  data for the buyer persona. No placeholder-looking values.

## Icons (Remix Icon)

- **Brand/social icons** (GitHub, Discord, Twitter, LinkedIn) → always `fill` variant
- **UI/functional icons** (arrows, chevrons, checks, close) → always `line` variant
- **Feature/section icons** → `line` variant, stay consistent within a section
- **Status/emphasis icons** (success checkmarks, alerts) → `fill` variant

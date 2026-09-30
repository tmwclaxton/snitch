---
paths:
  - 'resources/css/app.css'
  - 'resources/js/**/*.{vue,ts,css}'
  - 'resources/views/app.blade.php'
---

# Evidence File + Caution Tape tokens

Appearance is light / dark / system via `useAppearance`, the `appearance` cookie, and the `dark` class on `<html>`.

## Palettes

- **Logged-in app (Evidence File):** cream paper `#F3EEE3`, near-black ink `#141414`, highlighter yellow `#FFD60A`, alert red `#E5341D`. Cards are white / light cream with `ink/10` borders. Fonts: Inter (body), Bricolage Grotesque (`font-display` headings), Space Mono (`font-mono` labels, stats, numbers ≥14px).
- **Landing (Caution Tape):** black `#0E0E10`, bold yellow `#FCD700`, pink `#FF3D8B` sparingly, fog `#EDEAE2`. Hero headlines use Archivo Black (`font-hero` / `.snitch-hero-display`) in caps. TrackedBy may keep its own Caution Tape treatment.

## Token rules

- Flip `--snitch-paper`, `--snitch-ink`, `--snitch-fog` (and related grade / halftone / washi) under `.dark` so `snitch-*` utilities and `text-snitch-ink` / `bg-snitch-paper` work everywhere without per-page `dark:` classes.
- Keep `--snitch-press` (near-black) and `--snitch-stock` (cream) stable for film gutters, ticket rings, and type on press.
- Keep `--snitch-on-spot` near-black for text on highlighter fills (seg active, spot buttons, yellow chips). Never leave cream type on a yellow face.
- Use `--snitch-lift` instead of bare `white` in `color-mix` surface lifts.
- Use `--snitch-print-blend` (`multiply` light / `soft-light` dark) for print overlays on paper.
- Spot highlighter stays `#FFD60A` in both modes. Alert stays `#E5341D`.
- Caution Tape tokens (`--snitch-caution-*`) stay fixed for landing accents.
- Chart stipple / heat high levels use `--snitch-stipple-spot` (spot mixed with press) so marks stay AA-readable on cream; do not use bright `--snitch-spot` alone for thin chart strokes (prefer ink `#141414` for "You" series).

## Public shell is pinned to Caution Tape night

- `PublicLayout` carries `snitch-public-shell dark`, so every public page (landing, pricing, blog, legal, contact, 404, agents, analytics, onboarding) renders the night snitch tokens and `dark:` variants whatever the OS / appearance cookie. Light and dark visitors see the same page; only the logged-in app follows the appearance setting.
- Public page copy uses Caution Tape classes (`text-snitch-caution-fog` + `/70`-`/85` for secondary, `snitch-hero-display` headings, `border-white/10 bg-[#141416]` panels, yellow `bg-snitch-caution-yellow` primary / fog-outline secondary buttons). Never `text-neutral-950`, `bg-white` cards, `text-xs`, or `line-clamp` on the black shell.
- Sections that are deliberately cream/white (e.g. `/beta`) add `snitch-light` to re-scope the day tokens.

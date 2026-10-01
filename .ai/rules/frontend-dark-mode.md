---
paths:
  - 'resources/css/app.css'
  - 'resources/js/**/*.{vue,ts,css}'
  - 'resources/views/app.blade.php'
---

# Evidence File + Caution Tape tokens

Appearance is light / dark / system via `useAppearance`, the `appearance` cookie, and the `dark` class on `<html>`. When dark is active, set `color-scheme: dark` on the root (CSS + `documentElement.style.colorScheme` in `updateTheme` / the boot script) so native scrollbars follow night; light uses `color-scheme: light`. Dark also styles scrollbar thumbs fog-on-`#1A1A1D`.

## Palettes

- **Logged-in light (Evidence File):** cream paper `#F3EEE3`, near-black ink `#141414`, highlighter yellow `#FFD60A`, alert red `#E5341D`. Cards use `--snitch-lift` (white) with `ink/10` borders. Fonts: Inter (body), Bricolage Grotesque (`font-display` headings), Space Mono (`font-mono` labels, stats, numbers ≥14px).
- **Logged-in dark (Caution Tape night):** paper `#0E0E10`, fog type `#EDEAE2`, panels `#141416`, bold yellow `#FCD700`, thin `white/10` borders. Yellow primary buttons + outline secondary (matches live `/pricing`). Follows appearance light / dark / system (system default).
- **Public shell (Caution Tape):** same night tokens, pinned via `PublicLayout` `snitch-public-shell dark` so appearance does not flip marketing pages. Hero headlines use Archivo Black (`font-hero` / `.snitch-hero-display`) in caps. TrackedBy keeps its own Caution Tape treatment in both app modes.

## Token rules

- Flip `--snitch-paper`, `--snitch-ink`, `--snitch-fog`, `--snitch-spot`, and related grade / halftone / washi under `.dark` so `snitch-*` utilities and `text-snitch-ink` / `bg-snitch-paper` / `bg-snitch-lift` work everywhere without per-page `dark:` classes.
- Prefer `bg-snitch-lift` over bare `bg-white` for cards and panels.
- Keep `--snitch-on-spot` near-black for text on yellow fills. Never leave fog/cream type on a yellow face.
- Use `--snitch-lift` instead of bare `white` in `color-mix` surface lifts.
- Use `--snitch-print-blend` (`multiply` light / `soft-light` dark) for print overlays on paper.
- Light spot stays `#FFD60A`; dark spot is `#FCD700`. Alert stays `#E5341D`.
- Caution Tape tokens (`--snitch-caution-*`) stay fixed for landing accents and TrackedBy.
- Chart series read live CSS vars (`snitchTheme.ts`). Dark: You = `#FCD700`, rivals = pink/blue/green/orange (`SNITCH_RIVAL_COLOURS_DARK`), rivals' average = dashed fog. Light: You = ink, existing teal/red rival set.
- Never invert ink↔paper for selected chips in dark (pale pill + pale text). Use `.snitch-choice` / `.snitch-choice-active` (selected = spot yellow + on-spot text; idle = `#1A1A1D` + fog border). Format tags use `.snitch-format-tag`. Meter bars use `.snitch-meter-fill` / `-lead`.

## Public shell is pinned to Caution Tape night

- `PublicLayout` carries `snitch-public-shell dark`, so every public page (landing, pricing, blog, legal, contact, 404, agents, analytics, onboarding) renders the night snitch tokens and `dark:` variants whatever the OS / appearance cookie. Light and dark visitors see the same page; only the logged-in app follows the appearance setting.
- Public page copy uses Caution Tape classes (`text-snitch-caution-fog` + `/70`-`/85` for secondary, `snitch-hero-display` headings, `border-white/10 bg-[#141416]` panels, yellow `bg-snitch-caution-yellow` primary / fog-outline secondary buttons). Never `text-neutral-950`, `bg-white` cards, `text-xs`, or `line-clamp` on the black shell.
- Sections that are deliberately cream/white (e.g. `/beta`) add `snitch-light` to re-scope the day tokens.

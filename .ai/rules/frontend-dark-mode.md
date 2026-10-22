---
paths:
  - 'resources/css/app.css'
  - 'resources/js/**/*.{vue,ts,css}'
  - 'resources/views/app.blade.php'
---

# Caution Tape light + dark appearance

Appearance is light / dark / system via `useAppearance`, the `appearance` cookie, and the `dark` class on `<html>`. When dark is active, set `color-scheme: dark` on the root (CSS + `documentElement.style.colorScheme` in `updateTheme` / the boot script) so native scrollbars follow night; light uses `color-scheme: light`. Dark also styles scrollbar thumbs fog-on-`#1A1A1D`. Default is **system**.

The **logged-in app follows Appearance**. Do not pin authenticated pages with `snitch-app-night`, nested `dark`, or `data-app-night`. `HandleAppearance` shares `appNight` as `false`. Settings → Appearance is live (`AppearanceController`, `AppearanceTabs`). Public marketing stays night via `PublicLayout` `snitch-public-shell dark`. Charts read `snitchIsDark()` from `html.dark` only.

## Palettes

- **Light (logged-in):** paper `#EDEAE2`, ink `#0E0E10`, panels white / fog `#E4E0D6`, spot `#FCD700`, pink alerts `#FF3D8B`, thin ink/12 borders. Sidebar cream paper; hover grey `#E0DDD4`; selected item full yellow `#FCD700` + black text. Primary buttons yellow with black type. Pills / segs / filters: grey selected states with dark or cream text - never yellow.
- **Dark (logged-in / public):** paper `#0E0E10`, fog type `#EDEAE2`, panels `#141416`, spot `#FCD700`, pink `#FF3D8B`, thin `white/10` borders. Sidebar black; hover grey `#1E1E22`; selected yellow `#FCD700` + black text.
- Fonts: Inter (body), Archivo Black (`font-hero` / `.snitch-hero-display` uppercase headlines), Space Mono (small labels and stats only). Executive Brief memo takeaway uses `.snitch-memo-headline` (sentence case, `text-transform: none`) so long headlines are not forced uppercase.
- Highlight **one** key word or number per headline with `.snitch-highlight` (`#FCD700` block, `#0E0E10` text).

## Token rules

- Flip `--snitch-paper`, `--snitch-ink`, `--snitch-fog`, `--snitch-spot`, and related grade / halftone / washi under `.dark` so `snitch-*` utilities and `text-snitch-ink` / `bg-snitch-paper` / `bg-snitch-lift` work everywhere without per-page `dark:` classes.
- Prefer `bg-snitch-lift` / `bg-snitch-paper` / `border-snitch-ink/10` over hard-coded `#0E0E10`, `#141416`, `border-white/10`, or `bg-black` on logged-in surfaces (header, chat, sidebar, modals, dropdowns, toasts, mobile drawer).
- Keep `--snitch-on-spot` near-black for text on yellow fills. Never leave fog/cream type on a yellow face.
- **Light mode: never use `#FCD700` / `snitch-spot` as text, icon, or border colour on cream or white.** Yellow is only a fill behind near-black text (`.snitch-highlight`, primary buttons, selected sidebar link). Use ink for accent figures (`.snitch-stat-accent`), format tags, link underlines, and icons on paper. Yellow type/underlines are fine on night paper (`.dark`).
- Pink alert badges (`#FF3D8B`) use near-black type, not white (white on that pink fails contrast).
- Use `.snitch-choice` / `.snitch-choice-active` and `.snitch-seg` for pills (grey selected in dark; ink selected in light - never yellow). Yellow stays on headline highlight blocks, primary buttons, and the one active sidebar item.
- App header (`AppSidebarHeader`) uses `bg-snitch-paper` + `border-snitch-ink/10` so it matches the page in both modes.
- Chart series read live CSS vars (`snitchTheme.ts`). ApexCharts use `snitchApexTheme()`. Light chart series stay on ink / teal / warm AA hues - not spot yellow.

## Sidebar spacing

Product nav is MCP-first: Executive Brief, Chat, Competitors, Connect your AI (App group), Admin collapsible for admins, Billing/Settings in the footer. Landing URL is `/executive-brief` (route name `dashboard`; `/dashboard` redirects). Rows are ~44px (`2.75rem`) with 14px type, no truncate. Comfortable group gaps (`pt-4`, `mb-2` labels, `gap-1` between rows). Logo block and footer use `py-3`. Must still fit without a scrollbar on an 800px-tall window with Admin collapsed. Desktop sidebar: peer flex item stretches with main; rail uses `h-full min-h-svh` + `bg-sidebar` (not `self-stretch` under a block parent) with sticky inner `h-svh` so full-page screenshots keep the sidebar paint for the whole page height (do not use a viewport-only fixed shell that ends mid-memo).

## Public shell is pinned to Caution Tape night

- `PublicLayout` carries `snitch-public-shell dark`, so every public page renders the night snitch tokens whatever the OS / appearance cookie.
- Sections that are deliberately cream/white (e.g. `/beta`) add `snitch-light` to re-scope the day tokens.

---
paths:
  - 'resources/css/app.css'
  - 'resources/js/**/*.{vue,ts,css}'
  - 'resources/views/app.blade.php'
---

# Risograph light + dark appearance

Appearance is light / dark / system via `useAppearance`, the `appearance` cookie, and the `dark` class on `<html>`. When dark is active, set `color-scheme: dark` on the root (CSS + `documentElement.style.colorScheme` in `updateTheme` / the boot script) so native scrollbars follow night; light uses `color-scheme: light`. Dark also styles scrollbar thumbs fog-on-`#1C1915`. Default is **system**.

The **logged-in app follows Appearance**. Do not pin authenticated pages with `snitch-app-night`, nested `dark`, or `data-app-night`. `HandleAppearance` shares `appNight` as `false`. Settings → Appearance is live (`AppearanceController`, `AppearanceTabs`). Public marketing uses the same appearance as the logged-in app. Charts read `snitchIsDark()` from `html.dark` only.

## Palettes

- **Light (logged-in and public):** paper `#EFE6D8`, ink `#1C1B1A`, panels white / fog `#E4D9C8`, spot `#F0C400`, thin ink/12 borders. Sidebar cream paper; hover fog `#E4D9C8`; selected item full mustard `#F0C400` + charcoal text. Primary buttons mustard with charcoal type. Pills / segs / filters: grey selected states with dark or cream text - never yellow.
- **Dark (logged-in and public):** paper `#1C1915`, fog type `#EFE6D8`, panels `#3A342C`, spot `#F0C400`, thin cream/14 borders. Sidebar warm night; hover `#2A251F`; selected mustard `#F0C400` + charcoal text.
- Fonts: Inter (body), Archivo Black (`font-hero` / `.snitch-hero-display` uppercase headlines), Space Mono (small labels and stats only). Executive Brief memo takeaway uses `.snitch-memo-headline` (sentence case, `text-transform: none`) so long headlines are not forced uppercase.
- Highlight **one** key word or number per headline with `.snitch-highlight` (`#F0C400` block, `#1C1B1A` text).

## Token rules

- Flip `--snitch-paper`, `--snitch-ink`, `--snitch-fog`, `--snitch-spot`, and related grade / halftone / washi under `.dark` so `snitch-*` utilities and `text-snitch-ink` / `bg-snitch-paper` / `bg-snitch-lift` work everywhere without per-page `dark:` classes.
- Prefer `bg-snitch-lift` / `bg-snitch-paper` / `border-snitch-ink/10` over hard-coded night hexes or `bg-black` on logged-in surfaces (header, chat, sidebar, modals, dropdowns, toasts, mobile drawer).
- Keep `--snitch-on-spot` near-black for text on yellow fills. Never leave fog/cream type on a yellow face.
- **Light mode: never use `#F0C400` / `snitch-spot` as text, icon, or border colour on cream or white.** Yellow is only a fill behind near-black text (`.snitch-highlight`, primary buttons, selected sidebar link). Use ink for accent figures (`.snitch-stat-accent`), format tags, link underlines, and icons on paper. Yellow type/underlines are fine on night paper (`.dark`).
- Alert badges use cream type on the deep red fill (`#9F2D3A`). Do not use fluorescent pink as a brand spot.
- Use `.snitch-choice` / `.snitch-choice-active` and `.snitch-seg` for pills (grey selected in dark; ink selected in light - never yellow). Yellow stays on headline highlight blocks, primary buttons, and the one active sidebar item.
- App header (`AppSidebarHeader`) uses `bg-snitch-paper` + `border-snitch-ink/10` so it matches the page in both modes.
- Chart series read live CSS vars (`snitchTheme.ts`). ApexCharts use `snitchApexTheme()`. Light chart series stay on ink / teal / warm AA hues - not spot yellow.

## Sidebar spacing

Product nav is the 14 Sep shell: Dashboard, Brand, Snitches, Feed, Winners, Explore, Brand Deals, and MCP (`/connect`). Billing and Settings sit in the footer. Landing URL is `/dashboard`. Executive Brief, Chat, Today, and This week are removed; those paths redirect to `/dashboard`. `/connect` is full width in the app shell (not the settings column). Body copy on that page is at least 14px, labels sit beside values, and the server URL is not truncated. Rows are ~44px (`2.75rem`) with 14px type, no truncate. Comfortable group gaps (`pt-4`, `mb-2` labels, `gap-1` between rows). Logo block and footer use `py-3`. Desktop sidebar uses `h-svh` and `bg-sidebar`.

## Public shell follows appearance

- `PublicLayout` uses `snitch-public-shell` with paper and ink tokens. It does not force `dark`.
- Sections that must stay on day paper (e.g. `/beta`) add `snitch-light` to re-scope the day tokens.

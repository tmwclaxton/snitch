# Dark mode (Snitch tokens)

Appearance is light / dark / system via `useAppearance`, the `appearance` cookie, and the `dark` class on `<html>`.

## Token rules

- Flip `--snitch-paper`, `--snitch-ink`, `--snitch-fog` (and related grade / halftone / washi) under `.dark` so `snitch-*` utilities and `text-snitch-ink` / `bg-snitch-paper` work everywhere without per-page `dark:` classes.
- Keep `--snitch-press` (charcoal) and `--snitch-stock` (cream) stable for film gutters, ticket rings, and type on press.
- Keep `--snitch-on-spot` charcoal for text on mustard fills (seg active, spot buttons, yellow chips).
- Use `--snitch-lift` instead of bare `white` in `color-mix` surface lifts.
- Use `--snitch-print-blend` (`multiply` light / `soft-light` dark) for print overlays on paper.
- Spot yellow stays `#F0C400` in both modes. Paper grade stays warm - no cold gray SaaS dark theme.

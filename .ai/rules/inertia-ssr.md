---
paths:
  - 'resources/js/app.ts'
  - 'vite.config.ts'
---

# Inertia Vite SSR (app.ts)

`@inertiajs/vite` runs the same `createInertiaApp` entry for SSR in `npm run dev`. Server setup receives `el: null` and must **return** a Vue app.

## Rules

- Do not throw when `el` is missing.
- Use `createSSRApp` when `el` is null, `createApp` when mounting in the browser, and always `return` the app instance.
- Guard browser-only boot (`initializeTheme`, `initializeFlashToast`, DOM/localStorage) with `typeof window !== 'undefined'`.

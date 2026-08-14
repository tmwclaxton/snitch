import { router } from '@inertiajs/vue3';

function gtag(...args: unknown[]): void {
    if (typeof window.gtag === 'function') {
        window.gtag(...args);

        return;
    }

    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push(args);
}

export function pwaDisplayMode(): string {
    if (typeof window === 'undefined') {
        return 'browser';
    }

    const nav = window.navigator as Navigator & { standalone?: boolean };

    if (
        window.matchMedia('(display-mode: standalone)').matches ||
        nav.standalone === true
    ) {
        return 'standalone';
    }

    if (window.matchMedia('(display-mode: fullscreen)').matches) {
        return 'fullscreen';
    }

    if (window.matchMedia('(display-mode: minimal-ui)').matches) {
        return 'minimal-ui';
    }

    return 'browser';
}

function pagePath(): string {
    return window.location.pathname + window.location.search;
}

function syncDisplayMode(): void {
    gtag('set', 'user_properties', { pwa_display: pwaDisplayMode() });
}

function sendPageView(): void {
    const display = pwaDisplayMode();

    gtag('event', 'page_view', {
        page_title: document.title,
        page_location: window.location.href,
        page_path: pagePath(),
        pwa_display: display,
    });
}

export function initializeGoogleAnalytics(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (typeof window.gtag !== 'function') {
        return;
    }

    syncDisplayMode();

    let lastPath = pagePath();

    router.on('navigate', () => {
        const path = pagePath();

        if (path === lastPath) {
            return;
        }

        lastPath = path;
        syncDisplayMode();
        sendPageView();
    });

    window.addEventListener('appinstalled', () => {
        syncDisplayMode();
        gtag('event', 'pwa_install', {
            pwa_display: pwaDisplayMode(),
        });
    });

    const media = window.matchMedia('(display-mode: standalone)');

    if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', syncDisplayMode);
    }
}

import { router } from '@inertiajs/vue3';

const WAIT_MS = 2500;
const POLL_MS = 50;
const HEADER_OFFSET_PX = 72;
const REALIGN_MS = [80, 250, 600, 1200];
const REALIGN_WINDOW_MS = 1400;
const CANCEL_EVENTS = ['wheel', 'touchstart', 'keydown', 'pointerdown'] as const;

type JumpHandle = {
    stop: () => void;
};

let activeJump: JumpHandle | null = null;

function waitForElement(id: string, timeoutMs: number): Promise<HTMLElement | null> {
    const existing = document.getElementById(id);

    if (existing) {
        return Promise.resolve(existing);
    }

    return new Promise((resolve) => {
        const started = Date.now();

        const tick = (): void => {
            const el = document.getElementById(id);

            if (el) {
                resolve(el);

                return;
            }

            if (Date.now() - started >= timeoutMs) {
                resolve(null);

                return;
            }

            window.setTimeout(tick, POLL_MS);
        };

        tick();
    });
}

function landingOf(section: HTMLElement): HTMLElement {
    const eyebrow = section.querySelector('.snitch-dash-eyebrow');

    if (eyebrow instanceof HTMLElement) {
        return eyebrow;
    }

    return section;
}

function alignToAnchor(section: HTMLElement): void {
    const target = landingOf(section);
    const top = window.scrollY + target.getBoundingClientRect().top - HEADER_OFFSET_PX;
    window.scrollTo({ top: Math.max(0, top), behavior: 'auto' });
}

export async function scrollToDashboardAnchor(id: string): Promise<boolean> {
    const el = await waitForElement(id, WAIT_MS);

    if (!el) {
        return false;
    }

    activeJump?.stop();

    let stopped = false;
    let ignoreScrollUntil = 0;
    const pendingImages: HTMLImageElement[] = [];

    const realign = (): void => {
        if (stopped) {
            return;
        }

        ignoreScrollUntil = performance.now() + 80;
        alignToAnchor(el);
    };

    const onUserIntent = (): void => {
        stop();
    };

    const onScroll = (): void => {
        if (stopped || performance.now() < ignoreScrollUntil) {
            return;
        }

        stop();
    };

    const stop = (): void => {
        if (stopped) {
            return;
        }

        stopped = true;

        for (const timer of timers) {
            window.clearTimeout(timer);
        }

        window.clearTimeout(windowTimer);
        removeFinish();

        for (const type of CANCEL_EVENTS) {
            window.removeEventListener(type, onUserIntent, true);
        }

        window.removeEventListener('scroll', onScroll, true);

        for (const img of pendingImages) {
            img.removeEventListener('load', realign);
        }

        if (activeJump?.stop === stop) {
            activeJump = null;
        }
    };

    realign();

    const timers = REALIGN_MS.map((delay) => window.setTimeout(realign, delay));
    const windowTimer = window.setTimeout(stop, REALIGN_WINDOW_MS);

    document.querySelectorAll('img').forEach((img) => {
        if (!img.complete) {
            pendingImages.push(img);
            img.addEventListener('load', realign, { once: true });
        }
    });

    const removeFinish = router.on('finish', (event) => {
        if (stopped || event.detail.visit.preserveScroll) {
            return;
        }

        realign();
    });

    for (const type of CANCEL_EVENTS) {
        window.addEventListener(type, onUserIntent, { capture: true, passive: true });
    }

    window.addEventListener('scroll', onScroll, { capture: true, passive: true });

    activeJump = { stop };

    return true;
}

/**
 * Follow an insight/action target: in-page card id, or an absolute app path
 * such as `/winners` or `/tracking/12`.
 */
export function followDashboardLink(target: string): void {
    const trimmed = target.trim();

    if (trimmed === '') {
        return;
    }

    if (trimmed.startsWith('/')) {
        router.visit(trimmed);

        return;
    }

    void scrollToDashboardAnchor(trimmed);
}

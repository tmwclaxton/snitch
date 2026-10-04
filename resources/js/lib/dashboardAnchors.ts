import { router } from '@inertiajs/vue3';

const WAIT_MS = 2500;
const POLL_MS = 50;
const HEADER_OFFSET_PX = 80;
const REALIGN_MS = [80, 200, 500, 1000, 2000, 3500, 5000];
const OBSERVE_MS = 6000;

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

function headingOf(section: HTMLElement): HTMLElement {
    return section.querySelector('h1, h2') ?? section;
}

function alignToAnchor(section: HTMLElement): void {
    const target = headingOf(section);
    const top = window.scrollY + target.getBoundingClientRect().top - HEADER_OFFSET_PX;
    window.scrollTo({ top: Math.max(0, top), behavior: 'auto' });
}

export async function scrollToDashboardAnchor(id: string): Promise<boolean> {
    const el = await waitForElement(id, WAIT_MS);

    if (!el) {
        return false;
    }

    activeJump?.stop();

    const realign = (): void => {
        alignToAnchor(el);
    };

    realign();

    const timers = REALIGN_MS.map((delay) => window.setTimeout(realign, delay));

    document.querySelectorAll('img').forEach((img) => {
        if (!img.complete) {
            img.addEventListener('load', realign, { once: true });
        }
    });

    const removeFinish = router.on('finish', (event) => {
        if (event.detail.visit.preserveScroll) {
            return;
        }

        realign();
    });

    let observer: ResizeObserver | null = null;

    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(() => {
            realign();
        });
        observer.observe(el);

        const canvas = document.querySelector('.snitch-app-canvas, .snitch-app-chrome');

        if (canvas instanceof HTMLElement) {
            observer.observe(canvas);
        }
    }

    const stop = (): void => {
        for (const timer of timers) {
            window.clearTimeout(timer);
        }

        removeFinish();
        observer?.disconnect();

        if (activeJump?.stop === stop) {
            activeJump = null;
        }
    };

    window.setTimeout(stop, OBSERVE_MS);
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

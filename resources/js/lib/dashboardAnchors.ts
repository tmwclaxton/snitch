import { router } from '@inertiajs/vue3';

const WAIT_MS = 2500;
const POLL_MS = 50;
const HEADER_OFFSET_PX = 80;

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

export async function scrollToDashboardAnchor(id: string): Promise<boolean> {
    const el = await waitForElement(id, WAIT_MS);

    if (!el) {
        return false;
    }

    const top = window.scrollY + el.getBoundingClientRect().top - HEADER_OFFSET_PX;
    window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });

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

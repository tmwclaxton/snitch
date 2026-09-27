import { router } from '@inertiajs/vue3';

const FLASH_CLASS = 'snitch-dash-anchor-flash';
const FLASH_MS = 1600;
const WAIT_MS = 2500;
const POLL_MS = 50;

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

    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    el.classList.add(FLASH_CLASS);
    window.setTimeout(() => {
        el.classList.remove(FLASH_CLASS);
    }, FLASH_MS);

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

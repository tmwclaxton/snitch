/**
 * Cap concurrent platform iframe loads so Instagram/TikTok do not rate-limit
 * when many contact cells enter the viewport at once.
 */
const MAX_CONCURRENT = 2;

let active = 0;
const waiting: Array<() => void> = [];

export function acquireEmbedSlot(): Promise<void> {
    return new Promise((resolve) => {
        if (active < MAX_CONCURRENT) {
            active += 1;
            resolve();

            return;
        }

        waiting.push(() => {
            active += 1;
            resolve();
        });
    });
}

export function releaseEmbedSlot(): void {
    active = Math.max(0, active - 1);
    const next = waiting.shift();

    if (next) {
        next();
    }
}

/** Test helper: reset queue state between unit checks if added later. */
export function resetEmbedLoadQueue(): void {
    active = 0;
    waiting.length = 0;
}

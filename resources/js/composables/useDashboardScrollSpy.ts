import {
    nextTick,
    onMounted,
    onUnmounted,
    ref,
    toValue,
    watch,
} from 'vue';
import type { MaybeRefOrGetter } from 'vue';

/** Shared active dashboard section hash (without #) for sidebar highlight. */
export const activeDashboardSection = ref<string | null>(null);

const DEFAULT_SECTION_IDS = [
    'what-to-post',
    'performance',
    'ads',
    'tracked-by',
    'vote',
] as const;

/** Fraction of the viewport height used as the activation marker from the top. */
export const DASHBOARD_SPY_MARKER_RATIO = 0.3;

/** Distance from the document bottom (px) that forces the last section active. */
export const DASHBOARD_SPY_BOTTOM_EPSILON_PX = 8;

type SectionGeometry = {
    id: string;
    top: number;
};

/**
 * Pure picker: last present section whose top has crossed the marker line
 * (~30% down the viewport). At the page bottom, always the last section.
 */
export function resolveActiveDashboardSection(
    sections: SectionGeometry[],
    options: {
        viewportHeight: number;
        scrollY: number;
        scrollHeight: number;
        markerRatio?: number;
        bottomEpsilon?: number;
    },
): string | null {
    if (sections.length === 0) {
        return null;
    }

    const viewportHeight = Math.max(1, options.viewportHeight);
    const marker = viewportHeight * (options.markerRatio ?? DASHBOARD_SPY_MARKER_RATIO);
    const epsilon = options.bottomEpsilon ?? DASHBOARD_SPY_BOTTOM_EPSILON_PX;
    const maxScroll = Math.max(0, options.scrollHeight - viewportHeight);

    if (options.scrollY >= maxScroll - epsilon) {
        return sections[sections.length - 1].id;
    }

    let active = sections[0].id;

    for (const section of sections) {
        if (section.top <= marker) {
            active = section.id;
        }
    }

    return active;
}

/** Click / hash pin: keep this section highlighted until the user scrolls. */
let pinnedSectionId: string | null = null;

/** Set the sidebar highlight immediately (nav click or hash landing). */
export function activateDashboardSection(id: string): void {
    activeDashboardSection.value = id;
    pinnedSectionId = id;
}

/**
 * Watch dashboard section tops and keep `activeDashboardSection` in sync.
 * Call from Dashboard.vue only. Exactly one present section is active while
 * the spy is mounted.
 */
export function useDashboardScrollSpy(
    sectionIds: MaybeRefOrGetter<readonly string[]> = DEFAULT_SECTION_IDS,
): void {
    let frame = 0;
    let listening = false;

    const presentSections = (): SectionGeometry[] => {
        const ids = toValue(sectionIds);
        const out: SectionGeometry[] = [];

        for (const id of ids) {
            const el = document.getElementById(id);

            if (! el) {
                continue;
            }

            out.push({
                id,
                top: el.getBoundingClientRect().top,
            });
        }

        return out;
    };

    const sync = (): void => {
        if (pinnedSectionId !== null) {
            activeDashboardSection.value = pinnedSectionId;

            return;
        }

        const sections = presentSections();
        const next = resolveActiveDashboardSection(sections, {
            viewportHeight: window.innerHeight,
            scrollY: window.scrollY || document.documentElement.scrollTop,
            scrollHeight: document.documentElement.scrollHeight,
        });

        if (next !== null) {
            activeDashboardSection.value = next;
        }
    };

    const releasePinAndSync = (): void => {
        if (pinnedSectionId !== null) {
            pinnedSectionId = null;
        }

        sync();
    };

    const onScrollOrResize = (): void => {
        if (frame !== 0) {
            return;
        }

        frame = window.requestAnimationFrame(() => {
            frame = 0;
            sync();
        });
    };

    const onUserScrollIntent = (): void => {
        if (pinnedSectionId === null) {
            return;
        }

        // Programmatic scrollIntoView also emits wheel-less scroll events; only
        // real pointer/keyboard intent should clear the click/hash pin.
        releasePinAndSync();
    };

    const onKeyScrollIntent = (event: KeyboardEvent): void => {
        if (['ArrowDown', 'ArrowUp', 'PageDown', 'PageUp', 'Home', 'End', ' '].includes(event.key)) {
            onUserScrollIntent();
        }
    };

    const start = (): void => {
        if (listening) {
            return;
        }

        listening = true;
        window.addEventListener('scroll', onScrollOrResize, { passive: true });
        window.addEventListener('resize', onScrollOrResize);
        window.addEventListener('wheel', onUserScrollIntent, { passive: true });
        window.addEventListener('touchmove', onUserScrollIntent, { passive: true });
        window.addEventListener('keydown', onKeyScrollIntent);
    };

    const stop = (): void => {
        if (! listening) {
            return;
        }

        listening = false;
        window.removeEventListener('scroll', onScrollOrResize);
        window.removeEventListener('resize', onScrollOrResize);
        window.removeEventListener('wheel', onUserScrollIntent);
        window.removeEventListener('touchmove', onUserScrollIntent);
        window.removeEventListener('keydown', onKeyScrollIntent);

        if (frame !== 0) {
            window.cancelAnimationFrame(frame);
            frame = 0;
        }
    };

    const applyHashPin = (): boolean => {
        const hash = window.location.hash.replace(/^#/, '');
        const ids = toValue(sectionIds);

        if (hash && ids.includes(hash)) {
            activateDashboardSection(hash);

            return true;
        }

        return false;
    };

    onMounted(() => {
        start();

        void nextTick(() => {
            if (! applyHashPin()) {
                sync();
            }

            // Deferred panels mount after first paint - re-apply hash pin or sync.
            window.setTimeout(() => {
                if (! applyHashPin()) {
                    sync();
                }
            }, 50);
            window.setTimeout(() => {
                if (pinnedSectionId === null) {
                    sync();
                }
            }, 400);
        });

        window.addEventListener('hashchange', applyHashPin);
    });

    watch(
        () => [...toValue(sectionIds)],
        () => {
            window.requestAnimationFrame(sync);
        },
    );

    onUnmounted(() => {
        stop();
        window.removeEventListener('hashchange', applyHashPin);
        pinnedSectionId = null;
        activeDashboardSection.value = null;
    });
}

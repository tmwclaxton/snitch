import { onMounted, onUnmounted, ref } from 'vue';

/** Shared active dashboard section hash (without #) for sidebar highlight. */
export const activeDashboardSection = ref<string | null>(null);

const SECTION_IDS = [
    'what-to-post',
    'performance',
    'ads',
    'tracked-by',
    'vote',
] as const;

/**
 * Watch dashboard section headings and keep `activeDashboardSection` in sync.
 * Call from Dashboard.vue only.
 */
export function useDashboardScrollSpy(sectionIds: string[] = [...SECTION_IDS]): void {
    let observer: IntersectionObserver | null = null;

    onMounted(() => {
        const hash = window.location.hash.replace(/^#/, '');

        if (hash && sectionIds.includes(hash)) {
            activeDashboardSection.value = hash;
        }

        const ratios = new Map<string, number>();

        observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    const id = entry.target.id;
                    ratios.set(id, entry.isIntersecting ? entry.intersectionRatio : 0);
                }

                let bestId: string | null = null;
                let bestRatio = 0;

                for (const id of sectionIds) {
                    const ratio = ratios.get(id) ?? 0;

                    if (ratio > bestRatio) {
                        bestRatio = ratio;
                        bestId = id;
                    }
                }

                if (bestId !== null && bestRatio > 0) {
                    activeDashboardSection.value = bestId;
                }
            },
            {
                root: null,
                rootMargin: '-20% 0px -55% 0px',
                threshold: [0, 0.15, 0.35, 0.55, 0.75],
            },
        );

        for (const id of sectionIds) {
            const el = document.getElementById(id);

            if (el) {
                observer.observe(el);
            }
        }
    });

    onUnmounted(() => {
        observer?.disconnect();
        observer = null;
    });
}

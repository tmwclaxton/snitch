import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';

export type UseBrokenImageReturn = {
    failed: Ref<boolean>;
    showImage: ComputedRef<boolean>;
    onError: () => void;
    reset: () => void;
};

/**
 * Track whether an image src failed so callers can swap to a reserved fallback
 * without leaving a broken-image icon. Resets when `src` changes.
 */
export function useBrokenImage(src: () => string | null | undefined): UseBrokenImageReturn {
    const failed = ref(false);

    const showImage = computed(() => Boolean(src()) && !failed.value);

    function reset(): void {
        failed.value = false;
    }

    function onError(): void {
        failed.value = true;
    }

    watch(src, () => {
        reset();
    });

    return { failed, showImage, onError, reset };
}

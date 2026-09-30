<script setup lang="ts">
type TrackedBy = {
    count: number;
    since: string;
};

defineProps<{
    trackedBy: TrackedBy | null;
}>();

function countLabel(count: number): string {
    if (count === 1) {
        return '1 account is watching you';
    }

    return `${count} accounts are watching you`;
}
</script>

<template>
    <section
        v-if="trackedBy"
        class="tracked-by relative overflow-hidden rounded-md border border-amber-400/40 bg-[#141311] px-4 py-5 text-[14px] text-[#F5F0E6] sm:px-5"
        data-test="tracked-by-section"
    >
        <div
            class="tracked-by-tape pointer-events-none absolute inset-0"
            aria-hidden="true"
        />
        <div
            class="tracked-by-radar pointer-events-none absolute -right-8 top-1/2 size-36 -translate-y-1/2 rounded-full border border-amber-300/30 sm:size-44"
            aria-hidden="true"
        />
        <div
            class="tracked-by-pulse pointer-events-none absolute -right-2 top-1/2 size-20 -translate-y-1/2 rounded-full bg-amber-300/20 sm:size-24"
            aria-hidden="true"
        />

        <div class="relative z-10 flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0 space-y-1.5">
                <p class="text-[12px] font-semibold uppercase tracking-[0.14em] text-amber-300">
                    Is anyone tracking you?
                </p>
                <p class="font-display text-2xl leading-tight text-[#FFF8E7] sm:text-3xl">
                    {{ countLabel(trackedBy.count) }}
                </p>
                <p class="text-[14px] text-[#E8DFD0]">
                    Since {{ trackedBy.since }}
                </p>
            </div>
            <p class="max-w-xs text-[14px] leading-snug text-[#D9CFBE]">
                Counts only. We never reveal who is watching.
            </p>
        </div>
    </section>
</template>

<style scoped>
.tracked-by-tape {
    background: repeating-linear-gradient(
        -45deg,
        #f0c400 0 14px,
        #1c1b1a 14px 28px
    );
    mix-blend-mode: soft-light;
    opacity: 0.22;
}

.tracked-by-radar {
    animation: tracked-by-scan 3.6s ease-in-out infinite;
}

.tracked-by-pulse {
    animation: tracked-by-pulse 2.4s ease-out infinite;
}

@keyframes tracked-by-scan {
    0%,
    100% {
        transform: translateY(-50%) scale(0.92);
        opacity: 0.35;
    }
    50% {
        transform: translateY(-50%) scale(1.08);
        opacity: 0.75;
    }
}

@keyframes tracked-by-pulse {
    0% {
        transform: translateY(-50%) scale(0.7);
        opacity: 0.55;
    }
    70% {
        transform: translateY(-50%) scale(1.35);
        opacity: 0;
    }
    100% {
        transform: translateY(-50%) scale(1.35);
        opacity: 0;
    }
}
</style>

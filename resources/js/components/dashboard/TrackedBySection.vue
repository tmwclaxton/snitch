<script setup lang="ts">
import '../../../css/caution-tape.css';

type TrackedBy = {
    count: number;
    since: string;
};

defineProps<{
    trackedBy: TrackedBy | null;
}>();

function countPrefix(count: number): string {
    if (count === 1) {
        return '1 ACCOUNT IS';
    }

    return `${count} ACCOUNTS ARE`;
}
</script>

<template>
    <section
        v-if="trackedBy"
        class="caution-tape relative overflow-hidden rounded-md border border-[#FCD700]/35 bg-[#0E0E10] px-4 py-5 text-[14px] text-[#EDEAE2] sm:px-5"
        data-test="tracked-by-section"
    >
        <div
            class="caution-tape-stripes pointer-events-none absolute inset-0"
            aria-hidden="true"
        />
        <div
            class="caution-tape-radar pointer-events-none absolute -right-8 top-1/2 size-36 -translate-y-1/2 rounded-full border border-[#FCD700]/30 sm:size-44"
            aria-hidden="true"
        />
        <div
            class="caution-tape-pulse pointer-events-none absolute -right-2 top-1/2 size-20 -translate-y-1/2 rounded-full bg-[#FCD700]/20 sm:size-24"
            aria-hidden="true"
        />

        <div class="relative z-10 flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="caution-tape-meta text-[12px] font-semibold uppercase tracking-[0.14em] text-[#FCD700]">
                        Is anyone tracking you?
                    </p>
                    <span
                        class="rounded-full bg-[#FF3D8B] px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-white"
                    >
                        Watched
                    </span>
                </div>
                <p class="caution-tape-headline text-2xl leading-tight sm:text-3xl">
                    {{ countPrefix(trackedBy.count) }}
                    <span class="text-[#FCD700]">WATCHING YOU</span>
                </p>
                <p class="caution-tape-body text-[14px] text-[#EDEAE2]/85">
                    Since {{ trackedBy.since }}
                </p>
            </div>
            <p class="caution-tape-body max-w-xs text-[14px] leading-snug text-[#EDEAE2]/75">
                Counts only. We never reveal who is watching.
            </p>
        </div>
    </section>
</template>

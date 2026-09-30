<script setup lang="ts">
import { computed, ref } from 'vue';

type Chip = { term: string; count: number };
type Gap = { theme?: string; peer_pi?: number | null; label?: string };

const props = defineProps<{
    hashtags?: Chip[];
    ctas?: Chip[];
    gaps?: Gap[];
}>();

const open = ref(false);

const topHashtags = computed(() => (props.hashtags ?? []).slice(0, 3));
const topCtas = computed(() => (props.ctas ?? []).slice(0, 3));
const topGaps = computed(() => (props.gaps ?? []).slice(0, 3));

const restHashtags = computed(() => (props.hashtags ?? []).slice(3, 12));
const restCtas = computed(() => (props.ctas ?? []).slice(3, 12));
const restGaps = computed(() => (props.gaps ?? []).slice(3, 8));

const hasMore = computed(
    () => restHashtags.value.length + restCtas.value.length + restGaps.value.length > 0,
);

function gapLabel(gap: Gap): string {
    const theme = gap.theme || gap.label || 'theme';
    const x = typeof gap.peer_pi === 'number' ? ` ${gap.peer_pi.toFixed(1)}× rivals` : '';

    return `${theme}${x}`;
}
</script>

<template>
    <section class="rounded border border-snitch-ink/10 bg-snitch-lift p-3">
        <p class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
            What works
        </p>

        <div class="mt-3 grid gap-3 md:grid-cols-3">
            <div>
                <p class="mb-1.5 text-sm font-medium text-snitch-ink">Hashtags</p>
                <div class="flex flex-wrap gap-1.5">
                    <span
                        v-for="row in topHashtags"
                        :key="`h-${row.term}`"
                        class="snitch-choice rounded px-2 py-1 text-sm"
                    >
                        #{{ row.term }}
                    </span>
                    <span
                        v-if="!topHashtags.length"
                        class="text-sm text-snitch-ink/45"
                    >None yet</span>
                </div>
            </div>
            <div>
                <p class="mb-1.5 text-sm font-medium text-snitch-ink">Calls to action</p>
                <div class="flex flex-wrap gap-1.5">
                    <span
                        v-for="row in topCtas"
                        :key="`c-${row.term}`"
                        class="snitch-choice rounded px-2 py-1 text-sm"
                    >
                        {{ row.term }}
                    </span>
                    <span
                        v-if="!topCtas.length"
                        class="text-sm text-snitch-ink/45"
                    >None yet</span>
                </div>
            </div>
            <div>
                <p class="mb-1.5 text-sm font-medium text-snitch-ink">Topics to try</p>
                <div class="flex flex-wrap gap-1.5">
                    <span
                        v-for="(gap, index) in topGaps"
                        :key="`g-${index}`"
                        class="rounded bg-snitch-spot/40 px-2 py-1 text-sm text-snitch-ink"
                    >
                        {{ gapLabel(gap) }}
                    </span>
                    <span
                        v-if="!topGaps.length"
                        class="text-sm text-snitch-ink/45"
                    >None yet</span>
                </div>
            </div>
        </div>

        <details
            v-if="hasMore"
            class="mt-3"
            @toggle="open = ($event.target as HTMLDetailsElement).open"
        >
            <summary class="cursor-pointer text-sm font-medium text-snitch-ink/70 hover:text-snitch-ink">
                {{ open ? 'Hide details' : 'See details' }}
            </summary>
            <div class="mt-2 grid gap-3 border-t border-snitch-ink/10 pt-2 md:grid-cols-3">
                <div class="flex flex-wrap gap-1.5">
                    <span
                        v-for="row in restHashtags"
                        :key="`rh-${row.term}`"
                        class="snitch-choice rounded px-2 py-1 text-sm text-snitch-ink/80"
                    >
                        #{{ row.term }}
                    </span>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <span
                        v-for="row in restCtas"
                        :key="`rc-${row.term}`"
                        class="snitch-choice rounded px-2 py-1 text-sm text-snitch-ink/80"
                    >
                        {{ row.term }}
                    </span>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <span
                        v-for="(gap, index) in restGaps"
                        :key="`rg-${index}`"
                        class="snitch-choice rounded px-2 py-1 text-sm text-snitch-ink/80"
                    >
                        {{ gapLabel(gap) }}
                    </span>
                </div>
            </div>
        </details>
    </section>
</template>

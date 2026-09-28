<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';

type CtaLine = {
    text: string;
    count: number;
    post_id?: number | null;
};

type CtaGroup = {
    term: string;
    count: number;
    lines?: CtaLine[];
};

const props = defineProps<{
    ctas: CtaGroup[];
}>();

const openTerm = ref<string | null>(null);

const active = ref<CtaGroup | null>(null);

watch(
    () => props.ctas,
    (ctas) => {
        active.value = ctas.find((row) => row.term === openTerm.value) ?? null;

        if (active.value == null) {
            openTerm.value = null;
        }
    },
);

function toggle(row: CtaGroup): void {
    if (openTerm.value === row.term) {
        openTerm.value = null;
        active.value = null;

        return;
    }

    openTerm.value = row.term;
    active.value = row;
}
</script>

<template>
    <div class="flex min-h-0 min-w-0 flex-col">
        <p class="snitch-ink-label mb-1 shrink-0">CTA language</p>
        <div
            v-if="ctas.length"
            class="flex min-h-0 flex-1 flex-wrap content-start gap-1"
        >
            <button
                v-for="row in ctas"
                :key="`cta-${row.term}`"
                type="button"
                class="snitch-glance-tag snitch-cta-type"
                :class="openTerm === row.term ? 'snitch-glance-tag-open' : ''"
                :aria-expanded="openTerm === row.term"
                @click="toggle(row)"
            >
                {{ row.term }}
                <span class="tabular-nums text-snitch-ink/50">{{ row.count }}</span>
            </button>
        </div>
        <p
            v-else
            class="text-[11px] text-snitch-ink/60"
        >
            No analysed CTAs yet.
        </p>
        <ul
            v-if="active?.lines?.length"
            class="mt-1.5 max-w-3xl space-y-1"
        >
            <li
                v-for="line in active.lines"
                :key="`${active.term}-${line.text}`"
                class="text-[11px] leading-snug text-snitch-ink"
            >
                <Link
                    v-if="line.post_id"
                    :href="feedShow.url(line.post_id)"
                    class="underline decoration-snitch-ink/20 underline-offset-4 transition hover:decoration-snitch-spot"
                >
                    {{ line.text }}
                </Link>
                <template v-else>
                    {{ line.text }}
                </template>
                <span class="tabular-nums text-snitch-ink/50">{{ line.count }}</span>
            </li>
        </ul>
    </div>
</template>

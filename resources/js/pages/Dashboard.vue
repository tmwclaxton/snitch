<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as competitors } from '@/actions/App/Http/Controllers/CompetitorController';
import PanelBigNumber from '@/components/dashboard/panels/PanelBigNumber.vue';
import PanelChart from '@/components/dashboard/panels/PanelChart.vue';
import PanelNote from '@/components/dashboard/panels/PanelNote.vue';
import PanelPostCard from '@/components/dashboard/panels/PanelPostCard.vue';
import PanelRankedList from '@/components/dashboard/panels/PanelRankedList.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout,
});

type DashboardPanel = {
    id: number | null;
    position: number;
    type: string;
    title: string;
    payload: Record<string, unknown>;
    source: string;
};

const props = defineProps<{
    panels: DashboardPanel[];
    panels_source?: string;
}>();

const hasPanels = computed(() => props.panels.length > 0);

function widePanel(type: string): boolean {
    return type === 'line_chart' || type === 'bar_chart' || type === 'ranked_list';
}
</script>

<template>
    <div>
        <Head title="Dashboard" />

        <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
            <header class="space-y-1">
                <p class="font-mono text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                    Dashboard
                </p>
                <h1 class="snitch-hero-display text-3xl text-snitch-ink sm:text-4xl">
                    Today
                </h1>
                <p class="text-sm text-snitch-ink/70 sm:text-base">
                    Curated panels from your stored data. Ask Chat for the rest.
                </p>
            </header>

            <div
                v-if="! hasPanels"
                class="rounded border border-snitch-ink/10 bg-snitch-lift p-6"
            >
                <h2 class="snitch-hero-display text-xl text-snitch-ink">
                    No panels yet
                </h2>
                <p class="mt-2 text-sm leading-relaxed text-snitch-ink/80">
                    Add competitors and sync posts so default tiles can fill in, or open Chat.
                </p>
                <Link
                    :href="competitors()"
                    class="snitch-btn snitch-btn-spot mt-4 inline-flex px-3 py-2 text-sm font-medium"
                >
                    Go to Competitors
                </Link>
            </div>

            <div
                v-else
                class="grid items-start gap-3 sm:grid-cols-2 xl:grid-cols-3"
            >
                <template
                    v-for="panel in panels"
                    :key="panel.id ?? `${panel.type}-${panel.position}-${panel.title}`"
                >
                    <PanelBigNumber
                        v-if="panel.type === 'big_number'"
                        :title="panel.title"
                        :payload="panel.payload"
                    />
                    <PanelPostCard
                        v-else-if="panel.type === 'post_card'"
                        :title="panel.title"
                        :payload="panel.payload"
                    />
                    <PanelRankedList
                        v-else-if="panel.type === 'ranked_list'"
                        class="sm:col-span-2 xl:col-span-3"
                        :title="panel.title"
                        :payload="panel.payload"
                    />
                    <PanelNote
                        v-else-if="panel.type === 'note'"
                        :title="panel.title"
                        :payload="panel.payload"
                    />
                    <PanelChart
                        v-else-if="panel.type === 'line_chart' || panel.type === 'bar_chart'"
                        class="sm:col-span-2 xl:col-span-2"
                        :title="panel.title"
                        :kind="panel.type === 'bar_chart' ? 'bar' : 'line'"
                        :payload="panel.payload"
                    />
                    <div
                        v-else
                        class="self-start rounded border border-snitch-ink/10 bg-snitch-lift p-4 text-sm text-snitch-ink/70"
                        :class="widePanel(panel.type) ? 'sm:col-span-2 xl:col-span-2' : ''"
                    >
                        Unknown panel type: {{ panel.type }}
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

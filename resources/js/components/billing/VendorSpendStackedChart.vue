<script setup lang="ts">
import { computed } from 'vue';
import StippleBar from '@/components/dashboard/StippleBar.vue';

export type SpendPoint = {
    date: string;
    label: string;
    apify: number;
    nanogpt: number;
    firecrawl: number;
    tikhub: number;
    total: number;
};

type VendorKey = 'apify' | 'nanogpt' | 'firecrawl' | 'tikhub';

const vendors: Array<{ key: VendorKey; label: string; fillClass: string }> = [
    { key: 'apify', label: 'Apify', fillClass: 'fill-snitch-ink/75' },
    { key: 'nanogpt', label: 'NanoGPT', fillClass: 'fill-snitch-spot' },
    { key: 'firecrawl', label: 'Firecrawl', fillClass: 'fill-snitch-teal' },
    { key: 'tikhub', label: 'TikHub', fillClass: 'fill-snitch-marker' },
];

const props = defineProps<{
    points: SpendPoint[];
    days: number;
}>();

const money = new Intl.NumberFormat('en-GB', {
    style: 'currency',
    currency: 'GBP',
    maximumFractionDigits: 2,
});

function formatPence(pence: number): string {
    return money.format(pence / 100);
}

const maxTotal = computed(() =>
    Math.max(1, ...props.points.map((point) => point.total)),
);

const seriesTotal = computed(() =>
    props.points.reduce((sum, point) => sum + point.total, 0),
);

const hasSpend = computed(() => seriesTotal.value > 0);

const leftPad = 44;
const chartHeight = 160;
const plotTop = 10;
const barGap = 4;
const plotWidth = computed(() => Math.max(320, props.points.length * 22));
const chartWidth = computed(() => leftPad + plotWidth.value);
const barWidth = computed(() => {
    const n = Math.max(props.points.length, 1);

    return (plotWidth.value - barGap * (n - 1)) / n;
});

const stippleStep = computed(() => (barWidth.value < 12 ? 3.2 : 3.8));
const stippleRadius = computed(() => (barWidth.value < 12 ? 0.95 : 1.15));

const yTicks = computed(() => {
    const max = maxTotal.value;
    const values =
        max <= 1
            ? [0, 1]
            : max <= 100
              ? [0, Math.round(max / 2), max]
              : [0, Math.round(max / 2), max];

    return values.map((value) => ({
        value,
        label: formatPence(value),
        y: plotTop + (1 - value / max) * (chartHeight - plotTop),
    }));
});

type Segment = {
    key: string;
    x: number;
    y: number;
    width: number;
    height: number;
    fillClass: string;
    title: string;
    seed: number;
    delayOffset: number;
};

const segments = computed((): Segment[] => {
    const result: Segment[] = [];

    props.points.forEach((point, index) => {
        if (point.total <= 0) {
            return;
        }

        const x = leftPad + index * (barWidth.value + barGap);
        let cursorY = chartHeight;

        vendors.forEach((vendor, vendorIndex) => {
            const pence = point[vendor.key];

            if (pence <= 0) {
                return;
            }

            const rawHeight = (pence / maxTotal.value) * (chartHeight - plotTop);
            const height = Math.max(rawHeight, 3);
            cursorY -= height;

            result.push({
                key: `${point.date}-${vendor.key}`,
                x,
                y: cursorY,
                width: barWidth.value,
                height,
                fillClass: vendor.fillClass,
                title: `${point.label}: ${vendor.label} ${formatPence(pence)}`,
                seed: index * 10 + vendorIndex + 1,
                delayOffset: index * 18 + vendorIndex * 8,
            });
        });
    });

    return result;
});

const xLabels = computed(() => {
    const every = props.days <= 14 ? 2 : props.days <= 31 ? 5 : 7;

    return props.points
        .map((point, index) => ({ ...point, index }))
        .filter(
            (point) =>
                point.index === 0 ||
                point.index === props.points.length - 1 ||
                point.index % every === 0,
        );
});
</script>

<template>
    <div class="snitch-vendor-spend-chart">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="snitch-ink-label">Spend over time</p>
                <p class="mt-1 text-sm text-snitch-ink/65">
                    Stacked daily charges by vendor · last {{ days }} days
                </p>
            </div>
            <p class="tabular-nums text-xs text-snitch-ink/55">
                {{ formatPence(seriesTotal) }} charged
            </p>
        </div>

        <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-snitch-ink/70">
            <li
                v-for="vendor in vendors"
                :key="vendor.key"
                class="inline-flex items-center gap-1.5"
            >
                <span
                    class="size-2.5 shrink-0 rounded-[1px]"
                    :class="{
                        'bg-snitch-ink/75': vendor.key === 'apify',
                        'bg-snitch-spot': vendor.key === 'nanogpt',
                        'bg-snitch-teal': vendor.key === 'firecrawl',
                        'bg-snitch-marker': vendor.key === 'tikhub',
                    }"
                    aria-hidden="true"
                />
                {{ vendor.label }}
            </li>
        </ul>

        <svg
            v-if="hasSpend"
            class="mt-3 w-full overflow-visible"
            :viewBox="`0 0 ${chartWidth} ${chartHeight + 28}`"
            role="img"
            :aria-label="`Daily usage spend by vendor, ${formatPence(seriesTotal)} over ${days} days`"
        >
            <g v-for="tick in yTicks" :key="`y-${tick.value}`">
                <line
                    :x1="leftPad"
                    :x2="chartWidth"
                    :y1="tick.y"
                    :y2="tick.y"
                    class="stroke-snitch-ink/15"
                    stroke-width="1"
                />
                <text
                    :x="leftPad - 6"
                    :y="tick.y + 3"
                    text-anchor="end"
                    class="fill-snitch-ink/45"
                    style="font-size: 9px"
                >
                    {{ tick.label }}
                </text>
            </g>

            <StippleBar
                v-for="segment in segments"
                :key="segment.key"
                :x="segment.x"
                :y="segment.y"
                :width="segment.width"
                :height="segment.height"
                variant="dots"
                grow-from="bottom"
                :delay-offset="segment.delayOffset"
                :step-ms="16"
                :seed="segment.seed"
                :step="stippleStep"
                :radius="stippleRadius"
                :fill-class="segment.fillClass"
                :title="segment.title"
            />

            <text
                v-for="point in xLabels"
                :key="`x-${point.date}`"
                :x="leftPad + point.index * (barWidth + barGap) + barWidth / 2"
                :y="chartHeight + 16"
                text-anchor="middle"
                class="fill-snitch-ink/45"
                style="font-size: 9px"
            >
                {{ point.label }}
            </text>
        </svg>

        <p v-else class="mt-4 text-sm text-snitch-ink/55">
            No vendor charges in the last {{ days }} days yet. Sync, analyse, or run discovery to see spend here.
        </p>
    </div>
</template>

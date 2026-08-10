<script setup lang="ts">
import { computed, ref } from 'vue';
import StippleBar from '@/components/dashboard/StippleBar.vue';
import { formatPenceAsGbp } from '@/lib/money';
import {
    SPEND_VENDORS,
    VENDOR_CHART_FILL,
    vendorIconSrc,
    vendorLabel,
} from '@/lib/vendors';
import type { SpendVendorKey } from '@/lib/vendors';

export type SpendGrain = 'day' | 'week' | 'month';

export type SpendPoint = {
    date: string;
    label: string;
    apify: number;
    nanogpt: number;
    firecrawl: number;
    tikhub: number;
    total: number;
};

const vendors: Array<{
    key: SpendVendorKey;
    label: string;
    fillClass: string;
}> = SPEND_VENDORS.map((key) => ({
    key,
    label: vendorLabel(key),
    fillClass: VENDOR_CHART_FILL[key],
}));

const props = withDefaults(
    defineProps<{
        points: SpendPoint[];
        days: number;
        periodCount?: number;
        grain?: SpendGrain;
    }>(),
    {
        periodCount: undefined,
        grain: 'day',
    },
);

function formatPence(pence: number): string {
    return formatPenceAsGbp(pence);
}

const bucketCount = computed(() => props.periodCount ?? props.points.length);

const grainLabel = computed(() => {
    switch (props.grain) {
        case 'week':
            return 'weekly';
        case 'month':
            return 'monthly';
        default:
            return 'daily';
    }
});

const windowLabel = computed(() => {
    switch (props.grain) {
        case 'week':
            return `last ${bucketCount.value} weeks`;
        case 'month':
            return `last ${bucketCount.value} months`;
        default:
            return `last ${props.days} days`;
    }
});

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
const plotWidth = computed(() => {
    const minWidth = props.grain === 'day' ? 320 : 280;

    return Math.max(minWidth, props.points.length * (props.grain === 'day' ? 22 : 36));
});
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
    vendorLabel: string;
    periodLabel: string;
    amountLabel: string;
    seed: number;
};

type HoverTip = {
    vendorLabel: string;
    periodLabel: string;
    amountLabel: string;
    x: number;
    y: number;
};

const chartShell = ref<HTMLElement | null>(null);
const tip = ref<HoverTip | null>(null);

const segments = computed((): Segment[] => {
    const result: Segment[] = [];
    const plotSpan = chartHeight - plotTop;

    props.points.forEach((point, index) => {
        if (point.total <= 0) {
            return;
        }

        const x = leftPad + index * (barWidth.value + barGap);
        const active = vendors.filter((vendor) => point[vendor.key] > 0);
        let cursorY = chartHeight;

        active.forEach((vendor, vendorIndex) => {
            const pence = point[vendor.key];
            const rawHeight = (pence / maxTotal.value) * plotSpan;
            // Only pad tiny solo bars; stacked mins inflate and leave hollow gaps mid-column.
            const height =
                active.length === 1 ? Math.max(rawHeight, 3) : Math.max(rawHeight, 0);

            if (height <= 0) {
                return;
            }

            cursorY -= height;

            result.push({
                key: `${point.date}-${vendor.key}`,
                x,
                y: cursorY,
                width: barWidth.value,
                height,
                fillClass: vendor.fillClass,
                vendorLabel: vendor.label,
                periodLabel: point.label,
                amountLabel: formatPence(pence),
                seed: index * 10 + vendorIndex + 1,
            });
        });
    });

    return result;
});

const xLabels = computed(() => {
    const every =
        props.grain === 'day'
            ? props.days <= 14
                ? 2
                : props.days <= 31
                  ? 5
                  : 7
            : props.grain === 'week'
              ? 2
              : 1;

    return props.points
        .map((point, index) => ({ ...point, index }))
        .filter(
            (point) =>
                point.index === 0 ||
                point.index === props.points.length - 1 ||
                point.index % every === 0,
        );
});

function tipPosition(event: PointerEvent): { x: number; y: number } {
    const shell = chartShell.value;

    if (!shell) {
        return { x: 0, y: 0 };
    }

    const bounds = shell.getBoundingClientRect();

    return {
        x: event.clientX - bounds.left,
        y: event.clientY - bounds.top,
    };
}

function showTip(segment: Segment, event: PointerEvent): void {
    const { x, y } = tipPosition(event);

    tip.value = {
        vendorLabel: segment.vendorLabel,
        periodLabel: segment.periodLabel,
        amountLabel: segment.amountLabel,
        x,
        y,
    };
}

function moveTip(event: PointerEvent): void {
    if (!tip.value) {
        return;
    }

    const { x, y } = tipPosition(event);
    tip.value = { ...tip.value, x, y };
}

function hideTip(): void {
    tip.value = null;
}
</script>

<template>
    <div ref="chartShell" class="snitch-vendor-spend-chart relative">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="snitch-ink-label">Spend over time</p>
                <p class="mt-1 text-sm text-snitch-ink/65">
                    Stacked {{ grainLabel }} charges by vendor · {{ windowLabel }}
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
                    class="snitch-vendor-legend-mark inline-flex size-4 shrink-0 items-center justify-center"
                    aria-hidden="true"
                >
                    <img
                        :src="vendorIconSrc(vendor.key)"
                        alt=""
                        class="snitch-platform-logo size-3 object-contain"
                        width="12"
                        height="12"
                    >
                </span>
                {{ vendor.label }}
            </li>
        </ul>

        <svg
            v-if="hasSpend"
            class="mt-3 w-full overflow-visible"
            :viewBox="`0 0 ${chartWidth} ${chartHeight + 28}`"
            role="img"
            :aria-label="`${grainLabel} usage spend by vendor, ${formatPence(seriesTotal)} over ${windowLabel}`"
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
                :animate="false"
                :seed="segment.seed"
                :step="stippleStep"
                :radius="stippleRadius"
                :fill-class="segment.fillClass"
            />

            <!-- Transparent hit targets so each vendor band gets a paper tip on hover. -->
            <rect
                v-for="segment in segments"
                :key="`hit-${segment.key}`"
                class="snitch-vendor-spend-hit"
                :x="segment.x"
                :y="segment.y"
                :width="segment.width"
                :height="Math.max(segment.height, 3)"
                fill="transparent"
                @pointerenter="showTip(segment, $event)"
                @pointermove="moveTip($event)"
                @pointerleave="hideTip"
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

        <div
            v-if="tip"
            class="snitch-vendor-spend-tip"
            role="tooltip"
            :style="{ left: `${tip.x}px`, top: `${tip.y}px` }"
        >
            <p class="snitch-vendor-spend-tip-vendor">{{ tip.vendorLabel }}</p>
            <p class="snitch-vendor-spend-tip-amount tabular-nums">{{ tip.amountLabel }}</p>
            <p class="snitch-vendor-spend-tip-period">{{ tip.periodLabel }}</p>
        </div>

        <p v-else-if="!hasSpend" class="mt-4 text-sm text-snitch-ink/55">
            No vendor charges in the {{ windowLabel }} yet. Sync, analyse, or run discovery to see spend here.
        </p>
    </div>
</template>

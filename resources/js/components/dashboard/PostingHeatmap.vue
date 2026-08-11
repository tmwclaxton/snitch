<script setup lang="ts">
import { computed } from 'vue';

export type HeatmapDay = {
    date: string;
    count: number;
};

const props = defineProps<{
    days: HeatmapDay[];
}>();

const WEEKDAY_LABELS = ['', 'M', '', 'W', '', 'F', ''] as const;

const maxCount = computed(() =>
    props.days.reduce((max, day) => Math.max(max, day.count), 0),
);

const weeks = computed(() => {
    const columns: HeatmapDay[][] = [];

    for (let i = 0; i < props.days.length; i += 7) {
        columns.push(props.days.slice(i, i + 7));
    }

    return columns;
});

const monthLabels = computed(() => {
    const labels: Array<{ weekIndex: number; label: string }> = [];
    let lastMonth = '';

    weeks.value.forEach((week, weekIndex) => {
        const first = week[0];

        if (!first) {
            return;
        }

        const date = new Date(`${first.date}T12:00:00`);
        const label = date.toLocaleDateString(undefined, { month: 'short' });

        if (label !== lastMonth) {
            labels.push({ weekIndex, label });
            lastMonth = label;
        }
    });

    return labels;
});

const todayKey = computed(() => {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const d = String(now.getDate()).padStart(2, '0');

    return `${y}-${m}-${d}`;
});

function levelFor(count: number): number {
    if (count <= 0 || maxCount.value <= 0) {
        return 0;
    }

    const ratio = count / maxCount.value;

    if (ratio <= 0.25) {
        return 1;
    }

    if (ratio <= 0.5) {
        return 2;
    }

    if (ratio <= 0.75) {
        return 3;
    }

    return 4;
}

function dayTitle(day: HeatmapDay): string {
    const date = new Date(`${day.date}T12:00:00`);
    const label = date.toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });

    if (day.count === 0) {
        return `${label}: no posts`;
    }

    if (day.count === 1) {
        return `${label}: 1 post`;
    }

    return `${label}: ${day.count} posts`;
}

function isFuture(day: HeatmapDay): boolean {
    return day.date > todayKey.value;
}
</script>

<template>
    <div class="snitch-heatmap">
        <div class="snitch-heatmap-months" aria-hidden="true">
            <span
                v-for="month in monthLabels"
                :key="`${month.weekIndex}-${month.label}`"
                class="snitch-heatmap-month"
                :style="{ gridColumn: month.weekIndex + 2 }"
            >
                {{ month.label }}
            </span>
        </div>

        <div class="snitch-heatmap-body">
            <div class="snitch-heatmap-weekdays" aria-hidden="true">
                <span
                    v-for="(label, index) in WEEKDAY_LABELS"
                    :key="`wd-${index}`"
                    class="snitch-heatmap-weekday"
                >
                    {{ label }}
                </span>
            </div>

            <div
                class="snitch-heatmap-grid"
                role="img"
                :aria-label="`Snitch posting calendar, ${days.length} days`"
            >
                <div
                    v-for="(week, weekIndex) in weeks"
                    :key="`week-${weekIndex}`"
                    class="snitch-heatmap-week"
                >
                    <div
                        v-for="day in week"
                        :key="day.date"
                        class="snitch-heatmap-cell"
                        :class="{
                            'is-future': isFuture(day),
                            [`level-${levelFor(day.count)}`]: true,
                        }"
                        :title="dayTitle(day)"
                    />
                </div>
            </div>
        </div>

        <div class="snitch-heatmap-legend" aria-hidden="true">
            <span class="text-[10px] uppercase tracking-wide text-snitch-ink/45">Less</span>
            <span class="snitch-heatmap-cell level-0" />
            <span class="snitch-heatmap-cell level-1" />
            <span class="snitch-heatmap-cell level-2" />
            <span class="snitch-heatmap-cell level-3" />
            <span class="snitch-heatmap-cell level-4" />
            <span class="text-[10px] uppercase tracking-wide text-snitch-ink/45">More</span>
        </div>
    </div>
</template>

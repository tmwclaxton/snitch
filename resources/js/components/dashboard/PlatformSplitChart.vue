<script setup lang="ts">
import { computed } from 'vue';
import { platformIconSrc, platformLabel } from '@/lib/platforms';

export type PlatformBucket = {
    platform: string;
    count: number;
};

const props = defineProps<{
    platforms: PlatformBucket[];
}>();

const maxCount = computed(() =>
    Math.max(1, ...props.platforms.map((row) => row.count)),
);

const total = computed(() =>
    props.platforms.reduce((sum, row) => sum + row.count, 0),
);
</script>

<template>
    <div class="snitch-platform-split">
        <div class="flex items-baseline justify-between gap-3">
            <p class="snitch-ink-label">By platform</p>
            <p class="tabular-nums text-xs text-snitch-ink/55">
                {{ total }} · 12 wks
            </p>
        </div>

        <ul v-if="platforms.length" class="mt-3 space-y-2.5">
            <li
                v-for="row in platforms"
                :key="row.platform"
                class="grid grid-cols-[7.25rem_minmax(0,1fr)_2rem] items-center gap-2"
            >
                <span class="flex min-w-0 items-center gap-1.5 text-xs text-snitch-ink/70">
                    <img
                        :src="platformIconSrc(row.platform)"
                        alt=""
                        class="snitch-platform-logo size-3.5 shrink-0"
                        width="14"
                        height="14"
                    >
                    <span class="truncate">{{ platformLabel(row.platform) }}</span>
                </span>
                <div class="snitch-platform-bar-track" aria-hidden="true">
                    <div
                        class="snitch-platform-bar-fill"
                        :style="{ width: `${(row.count / maxCount) * 100}%` }"
                    />
                </div>
                <span class="text-right text-xs tabular-nums text-snitch-ink/70">
                    {{ row.count }}
                </span>
            </li>
        </ul>
        <p v-else class="mt-3 text-sm text-snitch-ink/55">
            No competitor posts in the last 12 weeks.
        </p>
    </div>
</template>

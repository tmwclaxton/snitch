<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { analysisTermIcon } from '@/lib/analysisTerms';
import type { AnalysisTermDimension } from '@/lib/analysisTerms';

const props = withDefaults(
    defineProps<{
        label: string;
        dimension?: AnalysisTermDimension | null;
        section?: string | null;
        slug?: string | null;
        count?: number | null;
        variant?: 'glance' | 'chip' | 'picker';
        selected?: boolean;
        href?: string | null;
    }>(),
    {
        dimension: null,
        section: null,
        slug: null,
        count: null,
        variant: 'chip',
        selected: false,
        href: null,
    },
);

const icon = computed(() =>
    analysisTermIcon({
        dimension: props.dimension,
        section: props.section,
        slug: props.slug,
        label: props.label,
    }),
);

const isLink = computed(() => props.href != null && props.href !== '');

const rootClass = computed(() => {
    const linkClass = isLink.value
        ? 'cursor-pointer transition hover:brightness-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-snitch-ink/30'
        : '';

    if (props.variant === 'glance') {
        return [
            'snitch-glance-tag w-max max-w-full min-w-0 overflow-hidden',
            linkClass,
        ];
    }

    if (props.variant === 'picker') {
        return [
            'inline-flex items-center gap-1.5 border px-2.5 py-1.5 text-left text-sm transition',
            props.selected
                ? 'border-snitch-ink/25 bg-snitch-spot text-snitch-on-spot shadow-[2px_2px_0_color-mix(in_oklab,var(--snitch-press)_35%,transparent)]'
                : 'border-snitch-ink/15 bg-[color-mix(in_oklab,var(--snitch-lift)_55%,var(--snitch-paper))] text-snitch-ink shadow-[1px_1px_0_color-mix(in_oklab,var(--snitch-spot)_18%,transparent)] hover:border-snitch-ink/30',
            linkClass,
        ];
    }

    return ['snitch-topic-chip', linkClass];
});

const iconClass = computed(() => {
    if (props.variant === 'glance') {
        return 'size-2.5 shrink-0 opacity-80';
    }

    if (props.variant === 'picker') {
        return [
            'size-3.5 shrink-0',
            props.selected ? 'opacity-90' : 'opacity-70',
        ];
    }

    return 'size-3 shrink-0 opacity-80';
});
</script>

<template>
    <Link
        v-if="isLink"
        :href="href!"
        :class="rootClass"
        :title="`Explore ${label}`"
        prefetch
    >
        <component
            :is="icon"
            :class="iconClass"
            aria-hidden="true"
        />
        <span class="min-w-0 truncate">{{ label }}</span>
        <span
            v-if="count != null && count > 0"
            :class="
                variant === 'picker' && selected
                    ? 'text-snitch-on-spot/70'
                    : 'opacity-60'
            "
        >
            · {{ count }}
        </span>
    </Link>
    <span
        v-else
        :class="rootClass"
    >
        <component
            :is="icon"
            :class="iconClass"
            aria-hidden="true"
        />
        <span class="min-w-0 truncate">{{ label }}</span>
        <span
            v-if="count != null && count > 0"
            :class="
                variant === 'picker' && selected
                    ? 'text-snitch-on-spot/70'
                    : 'opacity-60'
            "
        >
            · {{ count }}
        </span>
    </span>
</template>

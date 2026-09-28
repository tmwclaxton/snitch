<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle, Save } from '@lucide/vue';
import { update } from '@/actions/App/Http/Controllers/Settings/WinnerRuleController';

export type WinnerRuleFormData = {
    preset: string;
    min_multiplier: number;
    min_engagement_rate: number;
    min_views: number;
    min_likes: number;
    recency_days: number;
    weights: Record<string, number>;
    advanced: Record<string, unknown>;
};

export type WinnerRulePreset = {
    min_multiplier: number;
    min_engagement_rate: number;
    min_views: number;
    min_likes: number;
    recency_days: number;
    weights: Record<string, number>;
};

const PRESET_LABELS: Record<string, string> = {
    gentle: '1.5×',
    balanced: '2×',
    strict: '3×',
};

const props = defineProps<{
    rule: WinnerRuleFormData;
    presets: Record<string, WinnerRulePreset>;
}>();

const emit = defineEmits<{
    saved: [];
}>();

const form = useForm({
    preset: props.rule.preset,
    min_multiplier: Number(props.rule.min_multiplier ?? 2),
    min_engagement_rate: props.rule.min_engagement_rate,
    min_views: props.rule.min_views,
    min_likes: props.rule.min_likes,
    recency_days: props.rule.recency_days,
    weights: { ...props.rule.weights },
    advanced: {
        require_hook: Boolean(props.rule.advanced?.require_hook ?? true),
        require_sfx: Boolean(props.rule.advanced?.require_sfx ?? false),
        min_score: Number(props.rule.advanced?.min_score ?? 0),
    },
});

function presetLabel(name: string): string {
    return PRESET_LABELS[name] ?? name;
}

function applyPreset(name: string): void {
    const preset = props.presets[name];

    if (!preset) {
        form.preset = 'custom';

        return;
    }

    form.preset = name;
    form.min_multiplier = preset.min_multiplier;
    form.min_engagement_rate = preset.min_engagement_rate;
    form.min_views = preset.min_views;
    form.min_likes = preset.min_likes;
    form.recency_days = preset.recency_days;
    form.weights = { ...preset.weights };
}

function submit(): void {
    form.put(update.url(), {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
}
</script>

<template>
    <div>
        <div class="snitch-seg" role="group" aria-label="Winner multiplier presets">
            <button
                v-for="name in Object.keys(presets)"
                :key="name"
                type="button"
                class="snitch-seg-item"
                :class="form.preset === name ? 'snitch-seg-item-active' : ''"
                @click="applyPreset(name)"
            >
                {{ presetLabel(name) }}
            </button>
        </div>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <label class="block text-sm font-medium text-snitch-ink">
                Min × usual
                <input
                    v-model.number="form.min_multiplier"
                    type="number"
                    step="0.1"
                    min="1"
                    class="snitch-field mt-1"
                    @input="form.preset = 'custom'"
                />
                <span class="mt-1 block text-xs font-normal text-snitch-ink/55">
                    Posts must beat this account's usual interactions by at least this multiple.
                </span>
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm font-medium text-snitch-ink">
                    Recency days
                    <input
                        v-model.number="form.recency_days"
                        type="number"
                        class="snitch-field mt-1"
                        @input="form.preset = 'custom'"
                    />
                </label>
            </div>

            <label class="flex items-center gap-2 text-sm text-snitch-ink">
                <input v-model="form.advanced.require_hook" type="checkbox" class="accent-snitch-spot" />
                Require completed hook analysis
            </label>
            <label class="flex items-center gap-2 text-sm text-snitch-ink">
                <input v-model="form.advanced.require_sfx" type="checkbox" class="accent-snitch-spot" />
                Require SFX tags
            </label>

            <button
                type="submit"
                class="snitch-btn snitch-btn-spot"
                :disabled="form.processing"
            >
                <span class="relative z-10 inline-flex items-center gap-2">
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-4 animate-spin"
                        aria-hidden="true"
                    />
                    <Save
                        v-else
                        class="size-4"
                        aria-hidden="true"
                    />
                    Save rules
                </span>
            </button>
        </form>
    </div>
</template>

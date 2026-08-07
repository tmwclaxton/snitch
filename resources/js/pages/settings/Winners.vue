<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { update } from '@/actions/App/Http/Controllers/Settings/WinnerRuleController';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const props = defineProps<{
    rule: {
        preset: string;
        min_engagement_rate: number;
        min_views: number;
        min_likes: number;
        recency_days: number;
        weights: Record<string, number>;
        advanced: Record<string, unknown>;
    };
    presets: Record<
        string,
        {
            min_engagement_rate: number;
            min_views: number;
            min_likes: number;
            recency_days: number;
            weights: Record<string, number>;
        }
    >;
}>();

defineOptions({
    layout: [AppLayout, SettingsLayout],
});

const form = useForm({
    preset: props.rule.preset,
    min_engagement_rate: props.rule.min_engagement_rate,
    min_views: props.rule.min_views,
    min_likes: props.rule.min_likes,
    recency_days: props.rule.recency_days,
    weights: { ...props.rule.weights },
    advanced: {
        require_hook: Boolean(props.rule.advanced?.require_hook ?? true),
        require_sfx: Boolean(props.rule.advanced?.require_sfx ?? false),
        min_score: Number(props.rule.advanced?.min_score ?? 40),
    },
});

function applyPreset(name: string): void {
    const preset = props.presets[name];

    if (!preset) {
        form.preset = 'custom';

        return;
    }

    form.preset = name;
    form.min_engagement_rate = preset.min_engagement_rate;
    form.min_views = preset.min_views;
    form.min_likes = preset.min_likes;
    form.recency_days = preset.recency_days;
    form.weights = { ...preset.weights };
}
</script>

<template>
    <div class="max-w-2xl p-1">
        <Head title="Winner rules" />

        <div class="snitch-doc relative p-6">
            <h1 class="snitch-display relative z-10 text-2xl text-snitch-ink">
                Winner rules
            </h1>
            <p class="relative z-10 mt-2 text-sm text-snitch-ink/65">
                Quieter paper panel - same grade, less collage.
            </p>

            <div class="relative z-10 mt-5 flex flex-wrap gap-2">
                <button
                    v-for="name in Object.keys(presets)"
                    :key="name"
                    type="button"
                    class="snitch-stamp cursor-pointer capitalize"
                    :class="form.preset === name ? 'snitch-stamp-active' : ''"
                    @click="applyPreset(name)"
                >
                    {{ name }}
                </button>
            </div>

            <form
                class="relative z-10 mt-6 space-y-4"
                @submit.prevent="form.put(update.url())"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm">
                        Min views
                        <input
                            v-model.number="form.min_views"
                            type="number"
                            class="snitch-field mt-1"
                            @input="form.preset = 'custom'"
                        />
                    </label>
                    <label class="block text-sm">
                        Min likes
                        <input
                            v-model.number="form.min_likes"
                            type="number"
                            class="snitch-field mt-1"
                            @input="form.preset = 'custom'"
                        />
                    </label>
                    <label class="block text-sm">
                        Min engagement %
                        <input
                            v-model.number="form.min_engagement_rate"
                            type="number"
                            class="snitch-field mt-1"
                            @input="form.preset = 'custom'"
                        />
                    </label>
                    <label class="block text-sm">
                        Recency days
                        <input
                            v-model.number="form.recency_days"
                            type="number"
                            class="snitch-field mt-1"
                            @input="form.preset = 'custom'"
                        />
                    </label>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.advanced.require_hook" type="checkbox" />
                    Require completed hook analysis
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.advanced.require_sfx" type="checkbox" />
                    Require SFX tags
                </label>
                <label class="block text-sm">
                    Min score
                    <input
                        v-model.number="form.advanced.min_score"
                        type="number"
                        class="snitch-field mt-1"
                    />
                </label>

                <button type="submit" class="snitch-btn" :disabled="form.processing">
                    Save rules
                </button>
            </form>
        </div>
    </div>
</template>

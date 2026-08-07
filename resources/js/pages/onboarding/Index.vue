<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { store, suggest, confirm } from '@/actions/App/Http/Controllers/OnboardingController';
import AppLayout from '@/layouts/AppLayout.vue';

type Suggestion = {
    platform: string;
    handle: string;
    url: string;
    display_name: string;
    avatar: string | null;
};

const props = defineProps<{
    brand: {
        name?: string;
        website?: string | null;
        description?: string;
        own_handles?: Record<string, string | null>;
    } | null;
    suggestions: Suggestion[];
    platforms: string[];
}>();

defineOptions({
    layout: AppLayout,
});

const selected = ref<Record<string, boolean>>({});

const form = useForm({
    name: props.brand?.name ?? '',
    website: props.brand?.website ?? '',
    description: props.brand?.description ?? '',
    own_handles: {
        instagram: props.brand?.own_handles?.instagram ?? '',
        tiktok: props.brand?.own_handles?.tiktok ?? '',
        facebook: props.brand?.own_handles?.facebook ?? '',
        linkedin: props.brand?.own_handles?.linkedin ?? '',
        pinterest: props.brand?.own_handles?.pinterest ?? '',
    },
});

const selectedSuggestions = computed(() =>
    props.suggestions.filter((item) => selected.value[`${item.platform}:${item.handle}`]),
);

function toggle(item: Suggestion): void {
    const key = `${item.platform}:${item.handle}`;
    selected.value[key] = !selected.value[key];
}

function submitConfirm(): void {
    form
        .transform((data) => ({
            ...data,
            suggestions: selectedSuggestions.value,
        }))
        .post(confirm.url(), { preserveScroll: true });
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full p-6">
        <Head title="Onboarding" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-3xl">
            <h1 class="snitch-display text-4xl text-snitch-ink">
                Tell Snitch about your brand
            </h1>
            <p class="mt-2 max-w-xl text-snitch-ink/65">
                A scrapbook of context so we can suggest competitors worth
                watching.
            </p>

            <form
                class="snitch-scrap relative mt-8 space-y-5 p-6 pt-8"
                @submit.prevent="form.post(store.url())"
            >
                <span class="snitch-tape left-6 -top-2" aria-hidden="true" />

                <label class="block">
                    <span class="text-sm font-medium text-snitch-ink">Brand name</span>
                    <input v-model="form.name" class="snitch-field mt-1" required />
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-snitch-ink">Website</span>
                    <input
                        v-model="form.website"
                        type="url"
                        class="snitch-field mt-1"
                        placeholder="https://"
                    />
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-snitch-ink">Short description</span>
                    <textarea
                        v-model="form.description"
                        class="snitch-field mt-1 min-h-28"
                        required
                    />
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label
                        v-for="platform in platforms"
                        :key="platform"
                        class="block"
                    >
                        <span class="text-xs uppercase tracking-wide text-snitch-ink/55">
                            Your {{ platform }}
                        </span>
                        <input
                            v-model="form.own_handles[platform as keyof typeof form.own_handles]"
                            class="snitch-field mt-1"
                            :placeholder="`@${platform}`"
                        />
                    </label>
                </div>

                <div class="flex flex-wrap gap-3 pt-2">
                    <button type="submit" class="snitch-btn" :disabled="form.processing">
                        Save and continue
                    </button>
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-ghost"
                        :disabled="form.processing"
                        @click="form.post(suggest.url())"
                    >
                        Suggest competitors
                    </button>
                </div>
            </form>

            <section v-if="suggestions.length" class="mt-10">
                <h2 class="snitch-display text-2xl text-snitch-ink">
                    Polaroid picks
                </h2>
                <p class="mt-1 text-sm text-snitch-ink/65">
                    Select competitors to track, then confirm.
                </p>

                <div class="snitch-contact-reveal mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="(item, index) in suggestions"
                        :key="`${item.platform}:${item.handle}`"
                        type="button"
                        class="snitch-polaroid text-left transition"
                        :style="{
                            '--snitch-tilt': index % 2 === 0 ? '-1.5deg' : '1.2deg',
                        }"
                        :class="
                            selected[`${item.platform}:${item.handle}`]
                                ? 'ring-2 ring-snitch-accent ring-offset-2 ring-offset-transparent'
                                : ''
                        "
                        @click="toggle(item)"
                    >
                        <span
                            class="snitch-tape -top-2"
                            :class="index % 2 === 0 ? 'left-3' : 'right-3'"
                            aria-hidden="true"
                        />
                        <div class="snitch-polaroid-frame !aspect-square">
                            <img
                                v-if="item.avatar"
                                :src="item.avatar"
                                alt=""
                            />
                            <div
                                v-else
                                class="flex h-full items-center justify-center bg-snitch-teal/25 text-2xl font-semibold text-snitch-ink/60"
                            >
                                {{ item.display_name.slice(0, 1) }}
                            </div>
                        </div>
                        <p class="snitch-polaroid-caption">
                            @{{ item.handle }}
                        </p>
                        <div class="mt-1 px-0.5">
                            <span class="snitch-stamp">{{ item.platform }}</span>
                            <p class="snitch-display mt-2 text-lg">
                                {{ item.display_name }}
                            </p>
                        </div>
                    </button>
                </div>

                <button
                    type="button"
                    class="snitch-btn mt-6"
                    :disabled="selectedSuggestions.length === 0 || form.processing"
                    @click="submitConfirm"
                >
                    Confirm {{ selectedSuggestions.length }} competitors
                </button>
            </section>
        </div>
    </div>
</template>

<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    store as storeSuggestion,
    vote as voteSuggestion,
} from '@/actions/App/Http/Controllers/FeatureSuggestionController';

export type FeatureSuggestionRow = {
    id: number;
    title: string;
    body: string;
    status: string;
    votes_count: number;
    voted: boolean;
};

defineProps<{
    suggestions: FeatureSuggestionRow[];
}>();

const form = useForm({
    title: '',
    body: '',
});

const statusLabel: Record<string, string> = {
    open: 'Open',
    planned: 'Planned',
    building: 'Building',
    shipped: 'Shipped',
};

function statusText(status: string): string {
    return statusLabel[status] ?? status;
}

function toggleVote(id: number): void {
    router.post(voteSuggestion.url(id), {}, { preserveScroll: true });
}

function submitIdea(): void {
    form.post(storeSuggestion.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <section
        class="rounded-md border border-snitch-ink/10 bg-snitch-lift px-3 py-3 text-[14px] text-snitch-ink"
        data-test="vote-section"
    >
        <ul
            v-if="suggestions.length"
            class="space-y-2"
        >
            <li
                v-for="row in suggestions"
                :key="row.id"
                class="flex gap-3 rounded border border-snitch-ink/10 bg-snitch-fog/50 px-2.5 py-2"
                data-test="vote-row"
            >
                <button
                    type="button"
                    class="flex min-w-12 shrink-0 flex-col items-center justify-center rounded border px-1.5 py-1 text-[14px] font-semibold transition"
                    :class="
                        row.voted
                            ? 'border-amber-400 bg-amber-300 text-snitch-ink'
                            : 'border-snitch-ink/10 bg-snitch-lift text-snitch-ink/80 hover:border-snitch-ink/20'
                    "
                    :aria-pressed="row.voted"
                    :aria-label="row.voted ? 'Remove upvote' : 'Upvote'"
                    data-test="vote-toggle"
                    @click="toggleVote(row.id)"
                >
                    <span aria-hidden="true">▲</span>
                    <span>{{ row.votes_count }}</span>
                </button>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium text-snitch-ink">
                            {{ row.title }}
                        </p>
                        <span
                            class="rounded px-1.5 py-0.5 text-[12px] font-semibold uppercase tracking-wide"
                            :class="{
                                'bg-snitch-fog text-snitch-ink/80': row.status === 'open',
                                'bg-sky-100 text-sky-800': row.status === 'planned',
                                'bg-amber-100 text-amber-900': row.status === 'building',
                                'bg-emerald-100 text-emerald-800': row.status === 'shipped',
                            }"
                        >
                            {{ statusText(row.status) }}
                        </span>
                    </div>
                    <p class="mt-1 text-[14px] leading-snug text-snitch-ink/70">
                        {{ row.body }}
                    </p>
                </div>
            </li>
        </ul>

        <form
            class="mt-3 space-y-2 rounded border border-dashed border-snitch-ink/10 bg-snitch-lift px-2.5 py-2.5"
            data-test="vote-form"
            @submit.prevent="submitIdea"
        >
            <p class="text-[12px] font-semibold uppercase tracking-wide text-snitch-ink/55">
                Suggest an idea
            </p>
            <input
                v-model="form.title"
                type="text"
                maxlength="120"
                required
                placeholder="Short title"
                class="w-full rounded border border-snitch-ink/10 px-2.5 py-2 text-[14px] outline-none focus:border-snitch-ink/30"
            >
            <textarea
                v-model="form.body"
                rows="3"
                maxlength="2000"
                required
                placeholder="What should Snitch do?"
                class="w-full rounded border border-snitch-ink/10 px-2.5 py-2 text-[14px] outline-none focus:border-snitch-ink/30"
            />
            <p
                v-if="form.errors.title || form.errors.body"
                class="text-[14px] text-red-700"
            >
                {{ form.errors.title || form.errors.body }}
            </p>
            <button
                type="submit"
                class="snitch-btn px-3 py-1.5 text-[14px] disabled:opacity-60"
                :disabled="form.processing"
            >
                Submit idea
            </button>
        </form>
    </section>
</template>

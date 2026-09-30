<script setup lang="ts">
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
</script>

<template>
    <section
        v-if="suggestions.length"
        class="rounded-md border border-slate-200 bg-white px-3 py-3 text-[14px]"
        data-test="vote-section"
    >
        <p class="text-[12px] font-semibold uppercase tracking-wide text-slate-500">
            Cast your vote
        </p>
        <ul class="mt-2 space-y-2">
            <li
                v-for="row in suggestions"
                :key="row.id"
                class="rounded border border-slate-100 px-2 py-1.5"
            >
                <p class="font-medium text-slate-900">{{ row.title }}</p>
                <p class="mt-0.5 text-slate-600">{{ row.body }}</p>
            </li>
        </ul>
    </section>
</template>

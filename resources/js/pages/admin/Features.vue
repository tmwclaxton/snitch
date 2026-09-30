<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { update as updateFeature } from '@/actions/App/Http/Controllers/Admin/FeatureSuggestionController';
import AppLayout from '@/layouts/AppLayout.vue';
import { activity as adminActivity, overview as adminOverview } from '@/routes/admin';
import { index as adminFeaturesIndex } from '@/routes/admin/features';
import { index as adminUsersIndex } from '@/routes/admin/users';

type SuggestionRow = {
    id: number;
    title: string;
    body: string;
    status: string;
    votes_count: number;
    author: string | null;
    created_at: string | null;
};

const props = defineProps<{
    suggestions: SuggestionRow[];
    statuses: string[];
}>();

defineOptions({
    layout: AppLayout,
});

const statusLabel: Record<string, string> = {
    open: 'Open',
    planned: 'Planned',
    building: 'Building',
    shipped: 'Shipped',
};

function setStatus(id: number, status: string): void {
    router.patch(
        updateFeature.url(id),
        { status },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="snitch-doc px-4 py-5 sm:px-6">
        <Head title="Admin features" />

        <div class="mb-4 flex flex-wrap gap-3 text-[14px]">
            <Link
                :href="adminOverview()"
                class="text-snitch-ink/70 underline-offset-2 hover:underline"
            >
                Overview
            </Link>
            <Link
                :href="adminActivity()"
                class="text-snitch-ink/70 underline-offset-2 hover:underline"
            >
                Activity
            </Link>
            <Link
                :href="adminUsersIndex()"
                class="text-snitch-ink/70 underline-offset-2 hover:underline"
            >
                Users
            </Link>
            <Link
                :href="adminFeaturesIndex()"
                class="font-semibold text-snitch-ink"
            >
                Features
            </Link>
        </div>

        <h1 class="font-display text-3xl text-snitch-ink">
            Feature votes
        </h1>
        <p class="mt-2 max-w-2xl text-[14px] text-snitch-ink/75">
            Set status so the dashboard shows Planned, Building, or Shipped.
        </p>

        <ul class="mt-5 space-y-3">
            <li
                v-for="row in props.suggestions"
                :key="row.id"
                class="rounded border border-snitch-ink/10 bg-snitch-lift px-3 py-3 text-[14px]"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-snitch-ink">
                            {{ row.title }}
                        </p>
                        <p class="mt-1 text-snitch-ink/75">
                            {{ row.body }}
                        </p>
                        <p class="mt-2 text-[12px] uppercase tracking-wide text-snitch-ink/50">
                            {{ row.votes_count }} votes
                            <span v-if="row.author"> · {{ row.author }}</span>
                        </p>
                    </div>
                    <label class="block text-[12px] font-semibold uppercase tracking-wide text-snitch-ink/60">
                        Status
                        <select
                            class="mt-1 block min-w-36 rounded border border-snitch-ink/15 bg-snitch-lift px-2 py-1.5 text-[14px] font-normal normal-case tracking-normal text-snitch-ink"
                            :value="row.status"
                            @change="setStatus(row.id, ($event.target as HTMLSelectElement).value)"
                        >
                            <option
                                v-for="status in props.statuses"
                                :key="status"
                                :value="status"
                            >
                                {{ statusLabel[status] ?? status }}
                            </option>
                        </select>
                    </label>
                </div>
            </li>
        </ul>
    </div>
</template>

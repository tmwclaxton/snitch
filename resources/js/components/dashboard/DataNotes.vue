<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';

type AccountNote = {
    handle: string;
    tracked_account_id?: number | null;
    is_own_account: boolean;
    posts: number;
};

defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    accounts?: AccountNote[];
    range?: string | null;
    lastRefreshedAt?: string | null;
    excludedHiddenLikes?: number;
    note?: string | null;
    formatUk?: (iso: string | null) => string;
}>();
</script>

<template>
    <p class="text-[11px] leading-snug break-words text-slate-500">
        Analysed
        <span
            v-for="(account, index) in accounts || []"
            :key="account.handle"
        >
            <template v-if="index > 0"> · </template>
            <Link
                v-if="account.tracked_account_id"
                :href="competitorShow.url(account.tracked_account_id)"
                class="hover:underline"
            >
                {{ account.is_own_account ? 'You' : `@${account.handle}` }}
            </Link>
            <template v-else>
                {{ account.is_own_account ? 'You' : `@${account.handle}` }}
            </template>
            {{ account.posts }}
        </span>
        <template v-if="range"> · {{ range }}</template>
        <template v-if="lastRefreshedAt && formatUk"> · refreshed {{ formatUk(lastRefreshedAt) }}</template>
        <template v-if="(excludedHiddenLikes ?? 0) > 0">
            · excluded {{ excludedHiddenLikes }} hidden-likes from averages
        </template>
        <template v-if="note"> · {{ note }}</template>
    </p>
</template>

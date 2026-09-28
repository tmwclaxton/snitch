<script setup lang="ts">
type AccountNote = { handle: string; is_own_account: boolean; posts: number };

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
            {{ account.is_own_account ? 'You' : `@${account.handle}` }}
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

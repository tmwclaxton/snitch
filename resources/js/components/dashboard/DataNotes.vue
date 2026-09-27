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
    <div class="text-[11px] leading-relaxed text-slate-500">
        <p>
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
        </p>
        <p v-if="(excludedHiddenLikes ?? 0) > 0" class="mt-0.5">
            Excluded {{ excludedHiddenLikes }} post{{ excludedHiddenLikes === 1 ? '' : 's' }} with hidden likes from engagement averages.
        </p>
        <p v-if="note" class="mt-0.5">{{ note }}</p>
    </div>
</template>

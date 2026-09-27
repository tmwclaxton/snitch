<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Trash2, User } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    destroy,
    markOwn,
    show as competitorShow,
    store,
    unmarkOwn,
} from '@/actions/App/Http/Controllers/CompetitorController';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAppDate } from '@/lib/dates';
import { formatFollowers } from '@/lib/metrics';

defineOptions({
    layout: AppLayout,
});

type Account = {
    id: number;
    handle: string;
    display_name: string | null;
    avatar: string | null;
    followers?: number | null;
    posts_count?: number;
    last_synced_at: string | null;
    is_own_account?: boolean;
};

defineProps<{
    accounts?: Account[];
    platforms?: string[];
    suggestPlatforms?: string[];
    competitorBrief?: string;
    suggestions?: unknown[];
    suggestRun?: unknown;
    suggestError?: string | null;
    competitorCap?: unknown;
    syncDefaults?: unknown;
}>();

const handle = ref('');
const handleReady = computed(() => handle.value.trim().length > 0);

function confirmRemove(event: Event, name: string): void {
    if (!window.confirm(`Remove @${name}?`)) {
        event.preventDefault();
    }
}

function refreshLabel(value: string | null): string {
    return formatAppDate(value) ?? 'Never';
}
</script>

<template>
    <div class="px-4 py-6 sm:px-8">
        <Head title="Competitors" />
        <div class="mb-6 border-b border-neutral-200 pb-4">
            <h1 class="text-2xl font-semibold tracking-tight">Competitors</h1>
            <p class="mt-1 text-sm text-neutral-500">
                Track public Instagram accounts. Posts and follower counts refresh weekly.
            </p>
        </div>

        <div class="mb-8 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-stretch">
            <Form
                v-bind="store.form()"
                class="flex min-w-0 flex-col gap-2 sm:flex-1 sm:flex-row"
                @success="handle = ''"
            >
                <input type="hidden" name="platform" value="instagram" />
                <div class="flex min-w-0 flex-1 items-center border border-neutral-200 bg-white">
                    <span class="px-3 text-neutral-400">@</span>
                    <input
                        v-model="handle"
                        name="handle"
                        placeholder="instagram_handle"
                        class="min-w-0 flex-1 bg-transparent py-2 pr-3 text-sm outline-none"
                        maxlength="30"
                        aria-label="Instagram handle"
                    />
                </div>
                <button
                    type="submit"
                    class="inline-flex items-center justify-center gap-2 bg-neutral-950 px-4 py-2 text-sm font-medium text-white hover:opacity-90"
                >
                    Track account
                </button>
            </Form>
            <Form v-bind="store.form()" class="sm:shrink-0" @success="handle = ''">
                <input type="hidden" name="platform" value="instagram" />
                <input type="hidden" name="handle" :value="handle" />
                <input type="hidden" name="is_own_account" value="1" />
                <button
                    type="submit"
                    :disabled="!handleReady"
                    class="inline-flex w-full items-center justify-center gap-2 border border-neutral-200 px-4 py-2 text-sm hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                    :title="handleReady ? 'Mark this handle as your own Instagram account' : 'Enter a handle above first'"
                >
                    <User class="h-4 w-4" />
                    Add your account
                </button>
            </Form>
            <p class="w-full text-xs text-neutral-500 sm:basis-full">
                Tip: type a handle, then use Track account for a rival or Add your account for your own profile (needed for gap analysis).
            </p>
        </div>

        <div v-if="accounts === undefined" class="text-sm text-neutral-500">Loading…</div>
        <div
            v-else-if="accounts.length === 0"
            class="border border-dashed border-neutral-200 bg-white p-12 text-center text-sm text-neutral-500"
        >
            No competitors tracked yet. Add a handle above.
        </div>
        <template v-else>
            <div class="hidden overflow-hidden border border-neutral-200 bg-white md:block">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase tracking-wider text-neutral-500">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">Account</th>
                            <th class="px-4 py-2 text-right font-medium">Followers</th>
                            <th class="px-4 py-2 text-right font-medium">Posts</th>
                            <th class="px-4 py-2 text-right font-medium">Last refresh</th>
                            <th class="px-4 py-2 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                        <tr v-for="account in accounts" :key="account.id" class="hover:bg-neutral-50">
                            <td class="px-4 py-3">
                                <Link :href="competitorShow.url(account.id)" class="flex items-center gap-3">
                                    <SnitchAvatar
                                        :src="account.avatar"
                                        :handle="account.handle"
                                        size="sm"
                                        :alt="account.display_name || account.handle"
                                    />
                                    <div>
                                        <div class="flex items-center gap-2 font-medium">
                                            @{{ account.handle }}
                                            <span
                                                v-if="account.is_own_account"
                                                class="border border-neutral-200 bg-neutral-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-neutral-500"
                                            >
                                                you
                                            </span>
                                        </div>
                                        <div v-if="account.display_name" class="text-xs text-neutral-500">
                                            {{ account.display_name }}
                                        </div>
                                    </div>
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatFollowers(account.followers) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ (account.posts_count ?? 0).toLocaleString('en-GB') }}
                            </td>
                            <td class="px-4 py-3 text-right text-xs text-neutral-500">
                                {{ refreshLabel(account.last_synced_at) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex gap-1">
                                    <Form
                                        v-if="account.is_own_account"
                                        v-bind="unmarkOwn.form(account.id)"
                                    >
                                        <button type="submit" class="border border-neutral-200 px-2 py-1 text-xs hover:bg-neutral-50">
                                            Not my account
                                        </button>
                                    </Form>
                                    <Form v-else v-bind="markOwn.form(account.id)">
                                        <button type="submit" class="border border-neutral-200 px-2 py-1 text-xs hover:bg-neutral-50">
                                            This is my account
                                        </button>
                                    </Form>
                                    <Form
                                        v-bind="destroy.form(account.id)"
                                        @submit="confirmRemove($event, account.handle)"
                                    >
                                        <button type="submit" :aria-label="`Remove @${account.handle}`" class="border border-neutral-200 px-2 py-1 text-xs hover:bg-neutral-50">
                                            <Trash2 class="h-3 w-3" />
                                        </button>
                                    </Form>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ul class="space-y-3 md:hidden">
                <li
                    v-for="account in accounts"
                    :key="`m-${account.id}`"
                    class="border border-neutral-200 bg-white p-4"
                >
                    <Link :href="competitorShow.url(account.id)" class="flex items-center gap-3">
                        <SnitchAvatar
                            :src="account.avatar"
                            :handle="account.handle"
                            size="sm"
                            :alt="account.display_name || account.handle"
                        />
                        <div class="min-w-0">
                            <div class="truncate font-medium">@{{ account.handle }}</div>
                            <div class="text-xs text-neutral-500">
                                {{ formatFollowers(account.followers) }} followers ·
                                {{ account.posts_count ?? 0 }} posts ·
                                {{ refreshLabel(account.last_synced_at) }}
                            </div>
                        </div>
                    </Link>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Form
                            v-if="account.is_own_account"
                            v-bind="unmarkOwn.form(account.id)"
                        >
                            <button type="submit" class="border border-neutral-200 px-2 py-1 text-xs hover:bg-neutral-50">
                                Not my account
                            </button>
                        </Form>
                        <Form v-else v-bind="markOwn.form(account.id)">
                            <button type="submit" class="border border-neutral-200 px-2 py-1 text-xs hover:bg-neutral-50">
                                This is my account
                            </button>
                        </Form>
                        <Form
                            v-bind="destroy.form(account.id)"
                            @submit="confirmRemove($event, account.handle)"
                        >
                            <button type="submit" class="border border-neutral-200 px-2 py-1 text-xs hover:bg-neutral-50">
                                Remove
                            </button>
                        </Form>
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>

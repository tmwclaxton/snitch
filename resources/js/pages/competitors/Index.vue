<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { MoreHorizontal, User } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    destroy,
    markOwn,
    show as competitorShow,
    store,
    unmarkOwn,
} from '@/actions/App/Http/Controllers/CompetitorController';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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

const props = defineProps<{
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
const hasOwnAccount = computed(
    () => (props.accounts ?? []).some((account) => account.is_own_account),
);

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
        <div class="mb-6 border-b border-snitch-ink/10 pb-4">
            <h1 class="text-2xl font-semibold tracking-tight">Competitors</h1>
            <p class="mt-1 text-sm text-snitch-ink/55">
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
                <div class="flex min-w-0 flex-1 items-center border border-snitch-ink/10 bg-snitch-lift">
                    <span class="px-3 text-snitch-ink/45">@</span>
                    <input
                        v-model="handle"
                        name="handle"
                        placeholder="instagram_handle"
                        class="min-w-0 flex-1 bg-transparent py-2 pr-3 text-sm text-snitch-ink outline-none placeholder:text-snitch-ink/40"
                        maxlength="30"
                        aria-label="Instagram handle"
                    />
                </div>
                <button
                    type="submit"
                    class="snitch-btn snitch-btn-spot inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium"
                >
                    Track account
                </button>
            </Form>
            <Form
                v-if="!hasOwnAccount"
                v-bind="store.form()"
                class="sm:shrink-0"
                @success="handle = ''"
            >
                <input type="hidden" name="platform" value="instagram" />
                <input type="hidden" name="handle" :value="handle" />
                <input type="hidden" name="is_own_account" value="1" />
                <button
                    type="submit"
                    :disabled="!handleReady"
                    class="inline-flex w-full items-center justify-center gap-2 border border-snitch-ink/10 px-4 py-2 text-sm hover:bg-snitch-fog disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                    :title="handleReady ? 'Mark this handle as your own Instagram account' : 'Enter a handle above first'"
                >
                    <User class="h-4 w-4" />
                    Add your account
                </button>
            </Form>
            <p class="w-full text-xs text-snitch-ink/55 sm:basis-full">
                <template v-if="!hasOwnAccount">
                    Tip: type a handle, then use Track account for a rival or Add your account for your own profile (needed for gap analysis).
                </template>
                <template v-else>
                    Tip: type a handle, then use Track account for a rival. Your own account is marked with a You badge.
                </template>
            </p>
        </div>

        <div v-if="accounts === undefined" class="text-sm text-snitch-ink/55">Loading…</div>
        <div
            v-else-if="accounts.length === 0"
            class="border border-dashed border-snitch-ink/10 bg-snitch-lift p-12 text-center text-sm text-snitch-ink/55"
        >
            No competitors tracked yet. Add a handle above.
        </div>
        <template v-else>
            <div class="hidden overflow-hidden border border-snitch-ink/10 bg-snitch-lift md:block">
                <table class="w-full text-sm">
                    <thead class="bg-snitch-fog text-xs uppercase tracking-wider text-snitch-ink/55">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">Account</th>
                            <th class="px-4 py-2 text-right font-medium">Followers</th>
                            <th class="px-4 py-2 text-right font-medium">Posts</th>
                            <th class="px-4 py-2 text-right font-medium">Last refresh</th>
                            <th class="px-4 py-2 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                        <tr v-for="account in accounts" :key="account.id" class="hover:bg-snitch-fog">
                            <td class="px-4 py-3">
                                <Link :href="competitorShow.url(account.id)" class="flex items-center gap-3">
                                    <SnitchAvatar
                                        :src="account.avatar"
                                        :name="account.display_name"
                                        :handle="account.handle"
                                        size="sm"
                                        :alt="account.display_name || account.handle"
                                    />
                                    <div>
                                        <div class="flex items-center gap-2 font-medium">
                                            @{{ account.handle }}
                                            <span
                                                v-if="account.is_own_account"
                                                class="snitch-highlight rounded-sm px-1.5 py-0.5 text-sm font-semibold uppercase tracking-wider"
                                            >
                                                You
                                            </span>
                                        </div>
                                        <div v-if="account.display_name" class="text-xs text-snitch-ink/55">
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
                            <td class="px-4 py-3 text-right text-xs text-snitch-ink/55">
                                {{ refreshLabel(account.last_synced_at) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        class="inline-flex size-8 items-center justify-center border border-snitch-ink/10 text-snitch-ink/70 hover:bg-snitch-fog"
                                        :aria-label="`Actions for @${account.handle}`"
                                    >
                                        <MoreHorizontal class="size-4" />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" class="w-48">
                                        <Form
                                            v-if="account.is_own_account"
                                            v-bind="unmarkOwn.form(account.id)"
                                        >
                                            <DropdownMenuItem as-child>
                                                <button type="submit" class="w-full cursor-pointer">
                                                    Unmark as my account
                                                </button>
                                            </DropdownMenuItem>
                                        </Form>
                                        <Form v-else v-bind="markOwn.form(account.id)">
                                            <DropdownMenuItem as-child>
                                                <button type="submit" class="w-full cursor-pointer">
                                                    Mark as my account
                                                </button>
                                            </DropdownMenuItem>
                                        </Form>
                                        <DropdownMenuSeparator />
                                        <Form
                                            v-bind="destroy.form(account.id)"
                                            @submit="confirmRemove($event, account.handle)"
                                        >
                                            <DropdownMenuItem as-child variant="destructive">
                                                <button type="submit" class="w-full cursor-pointer">
                                                    Remove
                                                </button>
                                            </DropdownMenuItem>
                                        </Form>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ul class="space-y-3 md:hidden">
                <li
                    v-for="account in accounts"
                    :key="`m-${account.id}`"
                    class="border border-snitch-ink/10 bg-snitch-lift p-4"
                >
                    <div class="flex items-start justify-between gap-2">
                        <Link :href="competitorShow.url(account.id)" class="flex min-w-0 flex-1 items-center gap-3">
                            <SnitchAvatar
                                :src="account.avatar"
                                :name="account.display_name"
                                :handle="account.handle"
                                size="sm"
                                :alt="account.display_name || account.handle"
                            />
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2 font-medium">
                                    <span class="truncate">@{{ account.handle }}</span>
                                    <span
                                        v-if="account.is_own_account"
                                        class="snitch-highlight rounded-sm px-1.5 py-0.5 text-sm font-semibold uppercase tracking-wider"
                                    >
                                        You
                                    </span>
                                </div>
                                <div class="text-xs text-snitch-ink/55">
                                    {{ formatFollowers(account.followers) }} followers ·
                                    {{ account.posts_count ?? 0 }} posts ·
                                    {{ refreshLabel(account.last_synced_at) }}
                                </div>
                            </div>
                        </Link>
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                class="inline-flex size-8 shrink-0 items-center justify-center border border-snitch-ink/10 text-snitch-ink/70 hover:bg-snitch-fog"
                                :aria-label="`Actions for @${account.handle}`"
                            >
                                <MoreHorizontal class="size-4" />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-48">
                                <Form
                                    v-if="account.is_own_account"
                                    v-bind="unmarkOwn.form(account.id)"
                                >
                                    <DropdownMenuItem as-child>
                                        <button type="submit" class="w-full cursor-pointer">
                                            Unmark as my account
                                        </button>
                                    </DropdownMenuItem>
                                </Form>
                                <Form v-else v-bind="markOwn.form(account.id)">
                                    <DropdownMenuItem as-child>
                                        <button type="submit" class="w-full cursor-pointer">
                                            Mark as my account
                                        </button>
                                    </DropdownMenuItem>
                                </Form>
                                <DropdownMenuSeparator />
                                <Form
                                    v-bind="destroy.form(account.id)"
                                    @submit="confirmRemove($event, account.handle)"
                                >
                                    <DropdownMenuItem as-child variant="destructive">
                                        <button type="submit" class="w-full cursor-pointer">
                                            Remove
                                        </button>
                                    </DropdownMenuItem>
                                </Form>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>

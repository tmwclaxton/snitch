<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPenceAsGbp } from '@/lib/money';
import { activity as adminActivity, overview as adminOverview } from '@/routes/admin';
import { index as adminUsersIndex, show as adminUserShow } from '@/routes/admin/users';

type UserRow = {
    id: number;
    name: string | null;
    email: string;
    created_at: string | null;
    plan_status: string;
    balance_pence: number;
    referral_code: string | null;
    referral_name: string | null;
    last_activity_at: string | null;
    snitch_count: number;
};

type Paginator = {
    data: UserRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

const props = defineProps<{
    users: Paginator;
    filters: {
        search: string;
        sort: string;
        direction: string;
        plan: string;
    };
}>();

defineOptions({
    layout: AppLayout,
});

const searchInput = ref(props.filters.search);

watch(
    () => props.filters.search,
    (value) => {
        searchInput.value = value;
    },
);

function applyFilters(overrides: Record<string, string | number | undefined> = {}): void {
    router.get(
        adminUsersIndex.url({
            query: {
                search: searchInput.value || undefined,
                sort: props.filters.sort,
                direction: props.filters.direction,
                plan: props.filters.plan || undefined,
                ...overrides,
            },
        }),
        {},
        { preserveState: true, replace: true },
    );
}

function toggleSort(column: string): void {
    const direction =
        props.filters.sort === column && props.filters.direction === 'desc'
            ? 'asc'
            : 'desc';

    applyFilters({ sort: column, direction, page: 1 });
}

function formatWhen(iso: string | null): string {
    if (!iso) {
        return '-';
    }

    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}

const sortIndicator = computed(() => (column: string) => {
    if (props.filters.sort !== column) {
        return '';
    }

    return props.filters.direction === 'asc' ? ' ↑' : ' ↓';
});
</script>

<template>
    <div class="snitch-doc mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6">
        <Head title="Admin users" />

        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="snitch-ink-label">Internal</p>
                <h1 class="font-display text-3xl text-snitch-ink">Users</h1>
                <p class="mt-1 text-sm text-snitch-ink/65">
                    {{ users.total }} accounts · search, filter, and open a profile for activity detail.
                </p>
                <nav class="mt-3 flex flex-wrap gap-3 text-sm">
                    <Link :href="adminOverview()" class="underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink">
                        Overview
                    </Link>
                    <Link :href="adminActivity()" class="underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink">
                        Activity
                    </Link>
                </nav>
            </div>
        </header>

        <section class="snitch-scrap space-y-4 p-4">
            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="applyFilters({ page: 1 })"
            >
                <label class="flex min-w-[14rem] flex-1 flex-col gap-1 text-sm">
                    <span class="snitch-ink-label">Search</span>
                    <input
                        v-model="searchInput"
                        type="search"
                        name="search"
                        placeholder="Email or name"
                        class="border border-snitch-ink/15 bg-snitch-paper px-3 py-2 text-snitch-ink"
                    />
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="snitch-ink-label">Plan</span>
                    <select
                        :value="filters.plan"
                        class="border border-snitch-ink/15 bg-snitch-paper px-3 py-2 text-snitch-ink"
                        @change="applyFilters({ plan: ($event.target as HTMLSelectElement).value, page: 1 })"
                    >
                        <option value="">All</option>
                        <option value="subscribed">Subscribed</option>
                        <option value="none">Not subscribed</option>
                    </select>
                </label>
                <button type="submit" class="snitch-btn px-4 py-2 text-sm">Search</button>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-left text-sm">
                    <thead>
                        <tr class="snitch-ink-label border-b border-snitch-ink/10">
                            <th class="py-2 pr-3 font-normal">
                                <button type="button" class="hover:text-snitch-ink" @click="toggleSort('email')">
                                    Email{{ sortIndicator('email') }}
                                </button>
                            </th>
                            <th class="py-2 pr-3 font-normal">
                                <button type="button" class="hover:text-snitch-ink" @click="toggleSort('name')">
                                    Name{{ sortIndicator('name') }}
                                </button>
                            </th>
                            <th class="py-2 pr-3 font-normal">
                                <button type="button" class="hover:text-snitch-ink" @click="toggleSort('created_at')">
                                    Joined{{ sortIndicator('created_at') }}
                                </button>
                            </th>
                            <th class="py-2 pr-3 font-normal">Plan</th>
                            <th class="py-2 pr-3 font-normal">
                                <button type="button" class="hover:text-snitch-ink" @click="toggleSort('balance')">
                                    Balance{{ sortIndicator('balance') }}
                                </button>
                            </th>
                            <th class="py-2 pr-3 font-normal">Referral</th>
                            <th class="py-2 font-normal">Last activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in users.data"
                            :key="row.id"
                            class="border-b border-snitch-ink/8 align-top hover:bg-snitch-ink/3"
                        >
                            <td class="py-2 pr-3">
                                <Link
                                    :href="adminUserShow(row.id)"
                                    class="font-medium underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink"
                                >
                                    {{ row.email }}
                                </Link>
                            </td>
                            <td class="py-2 pr-3 text-snitch-ink/80">{{ row.name || '-' }}</td>
                            <td class="py-2 pr-3 whitespace-nowrap text-snitch-ink/70">{{ formatWhen(row.created_at) }}</td>
                            <td class="py-2 pr-3">
                                <span class="snitch-ink-label">{{ row.plan_status }}</span>
                            </td>
                            <td class="py-2 pr-3 tabular-nums">{{ formatPenceAsGbp(row.balance_pence) }}</td>
                            <td class="py-2 pr-3 text-snitch-ink/70">
                                <span v-if="row.referral_code">{{ row.referral_code }}</span>
                                <span v-else>-</span>
                            </td>
                            <td class="py-2 whitespace-nowrap text-snitch-ink/70">{{ formatWhen(row.last_activity_at) }}</td>
                        </tr>
                        <tr v-if="!users.data.length">
                            <td colspan="7" class="py-3 text-snitch-ink/55">No users match.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="users.last_page > 1" class="flex flex-wrap gap-2 pt-2">
                <template v-for="link in users.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="border border-snitch-ink/15 px-2 py-1 text-xs"
                        :class="link.active ? 'bg-snitch-spot/30 text-snitch-ink' : 'text-snitch-ink/70'"
                        preserve-state
                    >
                        <span v-html="link.label" />
                    </Link>
                    <span
                        v-else
                        class="border border-snitch-ink/15 px-2 py-1 text-xs text-snitch-ink/45"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </section>
    </div>
</template>

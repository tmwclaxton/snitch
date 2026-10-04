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

function whenLines(iso: string | null): string[] {
    if (!iso) {
        return ['-'];
    }

    const value = new Date(iso);

    return [
        new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(value),
        new Intl.DateTimeFormat('en-GB', { timeStyle: 'short' }).format(value),
    ];
}

function emailLocal(email: string): string {
    const at = email.lastIndexOf('@');

    return at > 0 ? email.slice(0, at) : email;
}

function emailDomain(email: string): string | null {
    const at = email.lastIndexOf('@');

    return at > 0 ? email.slice(at + 1) : null;
}

const sortIndicator = computed(() => (column: string) => {
    if (props.filters.sort !== column) {
        return '';
    }

    return props.filters.direction === 'asc' ? ' ↑' : ' ↓';
});

const userRowClass =
    'snitch-admin-users-row grid w-full min-w-0 grid-cols-[minmax(0,1.5fr)_minmax(0,0.9fr)_minmax(4.25rem,0.7fr)_minmax(3.25rem,0.45fr)_minmax(3.75rem,0.5fr)_minmax(2.75rem,0.35fr)_minmax(4.25rem,0.7fr)] items-start gap-x-3';

function planLabel(status: string): string {
    const labels: Record<string, string> = {
        none: 'None',
        active: 'Active',
        trialing: 'Trial',
        past_due: 'Past due',
        cancelled: 'Cancelled',
        canceled: 'Cancelled',
    };

    return labels[status] ?? status.replaceAll('_', ' ');
}
</script>

<template>
    <div class="snitch-doc mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6">
        <Head title="Admin users" />

        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="snitch-ink-label">Internal</p>
                <h1 class="snitch-hero-display text-3xl text-snitch-ink">Users</h1>
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

            <div class="min-w-0">
                <div
                    class="w-full min-w-0"
                    role="table"
                    aria-label="Users"
                >
                    <div
                        :class="[userRowClass, 'border-b border-snitch-ink/10']"
                        role="row"
                    >
                        <div
                            class="min-w-0 px-2 py-2 text-left text-sm font-medium text-snitch-ink/70"
                            role="columnheader"
                        >
                            <button
                                type="button"
                                class="w-full text-left hover:text-snitch-ink"
                                @click="toggleSort('email')"
                            >
                                Email{{ sortIndicator('email') }}
                            </button>
                        </div>
                        <div
                            class="min-w-0 px-2 py-2 text-left text-sm font-medium text-snitch-ink/70"
                            role="columnheader"
                        >
                            <button
                                type="button"
                                class="w-full text-left hover:text-snitch-ink"
                                @click="toggleSort('name')"
                            >
                                Name{{ sortIndicator('name') }}
                            </button>
                        </div>
                        <div
                            class="min-w-0 px-2 py-2 text-left text-sm font-medium text-snitch-ink/70"
                            role="columnheader"
                        >
                            <button
                                type="button"
                                class="w-full text-left hover:text-snitch-ink"
                                @click="toggleSort('created_at')"
                            >
                                Joined{{ sortIndicator('created_at') }}
                            </button>
                        </div>
                        <div
                            class="min-w-0 px-2 py-2 text-left text-sm font-medium text-snitch-ink/70"
                            role="columnheader"
                        >
                            Plan
                        </div>
                        <div
                            class="min-w-0 px-2 py-2 text-right text-sm font-medium text-snitch-ink/70"
                            role="columnheader"
                        >
                            <button
                                type="button"
                                class="w-full text-right hover:text-snitch-ink"
                                @click="toggleSort('balance')"
                            >
                                Balance{{ sortIndicator('balance') }}
                            </button>
                        </div>
                        <div
                            class="min-w-0 px-2 py-2 text-left text-sm font-medium text-snitch-ink/70"
                            role="columnheader"
                        >
                            Referral
                        </div>
                        <div
                            class="min-w-0 px-2 py-2 text-left text-sm font-medium text-snitch-ink/70"
                            role="columnheader"
                        >
                            Last activity
                        </div>
                    </div>
                    <div
                        v-for="row in users.data"
                        :key="row.id"
                        :class="[userRowClass, 'border-b border-snitch-ink/8 hover:bg-snitch-ink/3']"
                        role="row"
                    >
                        <div
                            class="min-w-0 px-2 py-2 text-left text-sm break-words"
                            role="cell"
                        >
                            <Link
                                :href="adminUserShow(row.id)"
                                class="font-medium underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink"
                            >
                                <template v-if="emailDomain(row.email)">
                                    {{ emailLocal(row.email) }}<wbr />@{{ emailDomain(row.email) }}
                                </template>
                                <template v-else>{{ row.email }}</template>
                            </Link>
                        </div>
                        <div
                            class="min-w-0 px-2 py-2 text-left text-sm text-snitch-ink/80"
                            role="cell"
                        >
                            {{ row.name || '-' }}
                        </div>
                        <div
                            class="min-w-0 px-2 py-2 text-left text-sm text-snitch-ink/70"
                            role="cell"
                        >
                            <span
                                v-for="(line, index) in whenLines(row.created_at)"
                                :key="`${row.id}-joined-${index}`"
                                class="block"
                            >{{ line }}</span>
                        </div>
                        <div
                            class="min-w-0 px-2 py-2 text-left text-sm"
                            role="cell"
                        >
                            {{ planLabel(row.plan_status) }}
                        </div>
                        <div
                            class="min-w-0 px-2 py-2 text-right text-sm tabular-nums"
                            role="cell"
                        >
                            {{ formatPenceAsGbp(row.balance_pence, { decimals: 'always2' }) }}
                        </div>
                        <div
                            class="min-w-0 px-2 py-2 text-left text-sm text-snitch-ink/70"
                            role="cell"
                        >
                            <span v-if="row.referral_code">{{ row.referral_code }}</span>
                            <span v-else>-</span>
                        </div>
                        <div
                            class="min-w-0 px-2 py-2 text-left text-sm text-snitch-ink/70"
                            role="cell"
                        >
                            <span
                                v-for="(line, index) in whenLines(row.last_activity_at)"
                                :key="`${row.id}-activity-${index}`"
                                class="block"
                            >{{ line }}</span>
                        </div>
                    </div>
                    <div
                        v-if="!users.data.length"
                        class="px-2 py-3 text-sm text-snitch-ink/55"
                        role="row"
                    >
                        No users match.
                    </div>
                </div>
            </div>

            <nav v-if="users.last_page > 1" class="flex flex-wrap gap-2 pt-2">
                <template v-for="link in users.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="border border-snitch-ink/15 px-2 py-1 text-sm"
                        :class="link.active ? 'snitch-choice-active' : 'text-snitch-ink/70'"
                        preserve-state
                    >
                        <span v-html="link.label" />
                    </Link>
                    <span
                        v-else
                        class="border border-snitch-ink/15 px-2 py-1 text-sm text-snitch-ink/45"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </section>
    </div>
</template>

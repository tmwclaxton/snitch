<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Copy, LoaderCircle, Plus } from '@lucide/vue';
import { ref } from 'vue';
import AdminReferralController from '@/actions/App/Http/Controllers/Admin/AdminReferralController';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPenceAsGbp } from '@/lib/money';
import { overview as adminOverview } from '@/routes/admin';
import { show as referralShow } from '@/routes/admin/referrals';

type ReferralRow = {
    id: number;
    code: string;
    name: string;
    notes: string | null;
    is_active: boolean;
    public_url: string;
    signups: number;
    converted: number;
    clicks: number;
    lifetime_usage_pence: number;
    lifetime_payments_pence: number;
    created_at: string | null;
};

defineProps<{
    codes: ReferralRow[];
}>();

defineOptions({
    layout: AppLayout,
});

const form = useForm({
    code: '',
    name: '',
    notes: '',
    is_active: true,
});

const copiedId = ref<number | null>(null);

function submit(): void {
    form.post(AdminReferralController.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.is_active = true;
        },
    });
}

async function copyUrl(row: ReferralRow): Promise<void> {
    try {
        await navigator.clipboard.writeText(row.public_url);
        copiedId.value = row.id;
        window.setTimeout(() => {
            if (copiedId.value === row.id) {
                copiedId.value = null;
            }
        }, 2000);
    } catch {
        copiedId.value = null;
    }
}
</script>

<template>
    <div class="snitch-doc mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6">
        <Head title="Admin referrals" />

        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="snitch-ink-label">Internal</p>
                <h1 class="font-display text-3xl text-snitch-ink">Referral links</h1>
                <p class="mt-1 max-w-xl text-sm text-snitch-ink/65">
                    Partner codes, first-touch cookies, and referred-user analytics.
                </p>
            </div>
            <Link
                :href="adminOverview.url()"
                class="text-sm text-snitch-ink/70 underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink"
            >
                Admin overview
            </Link>
        </header>

        <section class="snitch-scrap space-y-4 p-4">
            <h2 class="font-display text-xl text-snitch-ink">Create referral code</h2>
            <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="submit">
                <label class="block text-sm font-medium text-snitch-ink">
                    Code slug
                    <input
                        v-model="form.code"
                        class="snitch-field mt-1 font-mono text-sm"
                        placeholder="farmbabe"
                        required
                        autocomplete="off"
                    />
                    <p v-if="form.errors.code" class="mt-1 text-xs text-red-700">{{ form.errors.code }}</p>
                </label>

                <label class="block text-sm font-medium text-snitch-ink">
                    Partner name
                    <input
                        v-model="form.name"
                        class="snitch-field mt-1"
                        placeholder="Creator name"
                        required
                    />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-700">{{ form.errors.name }}</p>
                </label>

                <label class="block text-sm font-medium text-snitch-ink sm:col-span-2">
                    Notes
                    <input
                        v-model="form.notes"
                        class="snitch-field mt-1"
                        placeholder="Optional internal notes"
                    />
                </label>

                <label class="inline-flex items-center gap-2 text-sm text-snitch-ink">
                    <input v-model="form.is_active" type="checkbox" class="rounded border-snitch-ink/30" />
                    Active on create
                </label>

                <div class="sm:col-span-2 lg:col-span-3">
                    <button
                        type="submit"
                        class="snitch-btn inline-flex items-center gap-2 px-4 py-2 text-sm"
                        :disabled="form.processing"
                    >
                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                        <Plus v-else class="size-4" />
                        Create link
                    </button>
                </div>
            </form>
        </section>

        <section class="snitch-scrap space-y-3 p-4">
            <h2 class="font-display text-xl text-snitch-ink">All codes</h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-left text-sm">
                    <thead>
                        <tr class="snitch-ink-label border-b border-snitch-ink/10">
                            <th class="py-2 pr-3 font-normal">Partner</th>
                            <th class="py-2 pr-3 font-normal">Code</th>
                            <th class="py-2 pr-3 font-normal">Status</th>
                            <th class="py-2 pr-3 font-normal">Clicks</th>
                            <th class="py-2 pr-3 font-normal">Signups</th>
                            <th class="py-2 pr-3 font-normal">Converted</th>
                            <th class="py-2 pr-3 font-normal">Usage</th>
                            <th class="py-2 pr-3 font-normal">Payments</th>
                            <th class="py-2 font-normal">Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in codes"
                            :key="row.id"
                            class="border-b border-snitch-ink/8 align-top"
                        >
                            <td class="py-2 pr-3">
                                <Link
                                    :href="referralShow.url(row.id)"
                                    class="font-medium text-snitch-ink underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink"
                                >
                                    {{ row.name }}
                                </Link>
                            </td>
                            <td class="py-2 pr-3 font-mono text-xs">{{ row.code }}</td>
                            <td class="py-2 pr-3">
                                <span
                                    class="snitch-ink-label"
                                    :class="row.is_active ? 'text-snitch-ink' : 'text-snitch-ink/45'"
                                >
                                    {{ row.is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                            <td class="py-2 pr-3 tabular-nums">{{ row.clicks }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ row.signups }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ row.converted }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ formatPenceAsGbp(row.lifetime_usage_pence) }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ formatPenceAsGbp(row.lifetime_payments_pence) }}</td>
                            <td class="py-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-xs text-snitch-ink/70 hover:text-snitch-ink"
                                    @click="copyUrl(row)"
                                >
                                    <Copy class="size-3.5" />
                                    {{ copiedId === row.id ? 'Copied' : 'Copy URL' }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!codes.length">
                            <td colspan="9" class="py-4 text-snitch-ink/55">No referral codes yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

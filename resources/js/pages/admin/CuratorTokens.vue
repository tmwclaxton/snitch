<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import CuratorTokenController from '@/actions/App/Http/Controllers/Admin/CuratorTokenController';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout,
});

defineProps<{
    tokens: Array<{
        id: number;
        user_id: number | null;
        user_email: string | null;
        user_name: string | null;
        last_used_at: string | null;
        created_at: string | null;
    }>;
    audits: Array<{
        id: number;
        tool: string;
        actor_user_id: number | null;
        subject_user_id: number | null;
        ok: boolean;
        error_code: string | null;
        duration_ms: number | null;
        created_at: string | null;
    }>;
}>();
</script>

<template>
    <div>
        <Head title="Curator tokens" />

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <header class="space-y-1">
                <p class="font-mono text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                    Admin
                </p>
                <h1 class="snitch-hero-display text-3xl text-snitch-ink sm:text-4xl">
                    Curator tokens
                </h1>
                <p class="text-sm text-snitch-ink/70 sm:text-base">
                    Revoke assistant tokens that can read any user and set dashboard panels. Create tokens with
                    <span class="font-mono">php artisan snitch:curator-token create</span>.
                </p>
            </header>

            <section class="rounded border border-snitch-ink/10 bg-snitch-lift p-4">
                <h2 class="flex items-center gap-2 text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                    <KeyRound
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    Active tokens
                </h2>
                <div
                    v-if="tokens.length === 0"
                    class="mt-3 text-sm text-snitch-ink/70"
                >
                    No curator tokens.
                </div>
                <ul
                    v-else
                    class="mt-3 space-y-3"
                >
                    <li
                        v-for="token in tokens"
                        :key="token.id"
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-snitch-ink/10 pb-3 text-sm last:border-b-0 last:pb-0"
                    >
                        <div class="space-y-1">
                            <p class="font-medium text-snitch-ink">
                                Token {{ token.id }}
                                <span class="text-snitch-ink/60">for</span>
                                {{ token.user_name || 'User' }}
                                <span class="font-mono text-snitch-ink/70">{{ token.user_email }}</span>
                            </p>
                            <p class="font-mono text-snitch-ink/60">
                                Created {{ token.created_at || 'unknown' }}
                                <span class="mx-1">·</span>
                                Last used {{ token.last_used_at || 'never' }}
                            </p>
                        </div>
                        <Form v-bind="CuratorTokenController.destroy.form({ token: token.id })">
                            <button
                                type="submit"
                                class="snitch-btn px-3 py-1.5 text-sm"
                            >
                                Revoke
                            </button>
                        </Form>
                    </li>
                </ul>
            </section>

            <section class="rounded border border-snitch-ink/10 bg-snitch-lift p-4">
                <h2 class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                    Recent curator calls
                </h2>
                <div
                    v-if="audits.length === 0"
                    class="mt-3 text-sm text-snitch-ink/70"
                >
                    No curator calls logged yet.
                </div>
                <ul
                    v-else
                    class="mt-3 space-y-2"
                >
                    <li
                        v-for="row in audits"
                        :key="row.id"
                        class="flex flex-wrap items-baseline justify-between gap-2 text-sm"
                    >
                        <span class="font-mono text-snitch-ink">{{ row.tool }}</span>
                        <span class="text-snitch-ink/70">
                            Actor {{ row.actor_user_id ?? '-' }}
                            <span class="mx-1">·</span>
                            Subject {{ row.subject_user_id ?? '-' }}
                            <span class="mx-1">·</span>
                            {{ row.ok ? 'ok' : (row.error_code || 'error') }}
                            <span class="mx-1">·</span>
                            {{ row.created_at || '-' }}
                        </span>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>

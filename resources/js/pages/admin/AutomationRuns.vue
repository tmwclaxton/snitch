<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Bot } from '@lucide/vue';
import AutomationRunController from '@/actions/App/Http/Controllers/Admin/AutomationRunController';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout,
});

defineProps<{
    runs: Array<{
        id: number;
        uuid: string;
        user_id: number;
        user_email: string | null;
        user_name: string | null;
        type: string;
        status: string;
        cursor_run_id: string | null;
        cursor_background_composer_id: string | null;
        cost_notes: string | null;
        error: string | null;
        has_token: boolean;
        started_at: string | null;
        finished_at: string | null;
        expires_at: string | null;
        created_at: string | null;
    }>;
    brief_configured: boolean;
    chat_configured: boolean;
}>();
</script>

<template>
    <div>
        <Head title="Automation runs" />

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <header class="space-y-1">
                <p class="font-mono text-sm font-medium uppercase tracking-wide text-snitch-ink/55">Admin</p>
                <h1 class="snitch-hero-display text-3xl text-snitch-ink sm:text-4xl">Automation runs</h1>
                <p class="text-sm text-snitch-ink/70 sm:text-base">
                    Cursor Automations for Executive Brief and Chat. Each run gets a short-lived token bound to one
                    user. Revoke closes the run and deletes the token.
                </p>
                <p class="text-sm text-snitch-ink/60">
                    Brief webhook:
                    <span class="font-mono">{{ brief_configured ? 'configured' : 'missing' }}</span>
                    · Chat webhook:
                    <span class="font-mono">{{ chat_configured ? 'configured' : 'missing' }}</span>
                </p>
            </header>

            <section
                v-if="brief_configured"
                class="rounded border border-snitch-ink/10 bg-snitch-lift p-4"
            >
                <h2 class="text-sm font-semibold uppercase tracking-wide text-snitch-ink/80">
                    Kick off brief automation
                </h2>
                <p class="mt-1 text-sm text-snitch-ink/70">
                    Posts the real webhook for one user (force clears the 180s guard).
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Form
                        v-for="userId in [1, 6]"
                        :key="`kick-${userId}`"
                        v-bind="AutomationRunController.kickoffBrief.form()"
                        class="inline"
                    >
                        <input
                            type="hidden"
                            name="user_id"
                            :value="userId"
                        >
                        <input
                            type="hidden"
                            name="force"
                            value="1"
                        >
                        <button
                            type="submit"
                            class="snitch-btn snitch-btn-spot px-3 py-1.5 text-sm font-medium"
                        >
                            User {{ userId }}
                        </button>
                    </Form>
                </div>
            </section>

            <section class="rounded border border-snitch-ink/10 bg-snitch-lift p-4">
                <h2 class="flex items-center gap-2 text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                    <Bot
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    Recent runs
                </h2>
                <div
                    v-if="runs.length === 0"
                    class="mt-3 text-sm text-snitch-ink/70"
                >
                    No automation runs yet.
                </div>
                <ul
                    v-else
                    class="mt-3 space-y-3"
                >
                    <li
                        v-for="run in runs"
                        :key="run.id"
                        class="flex flex-wrap items-start justify-between gap-3 border-b border-snitch-ink/10 pb-3 text-sm last:border-b-0 last:pb-0"
                    >
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium text-snitch-ink">
                                {{ run.type }} · {{ run.status }}
                                <span class="font-mono text-snitch-ink/60">#{{ run.id }}</span>
                            </p>
                            <p class="text-snitch-ink/70">
                                {{ run.user_name || 'User' }}
                                <span class="font-mono">{{ run.user_email }}</span>
                            </p>
                            <p class="font-mono text-xs text-snitch-ink/55 break-all">
                                run {{ run.uuid }}
                                <template v-if="run.cursor_run_id"> · cursor {{ run.cursor_run_id }}</template>
                            </p>
                            <p
                                v-if="run.cost_notes"
                                class="text-snitch-ink/70"
                            >
                                Cost notes: {{ run.cost_notes }}
                            </p>
                            <p
                                v-if="run.error"
                                class="text-snitch-ink/70"
                            >
                                {{ run.error }}
                            </p>
                        </div>
                        <Form
                            v-if="run.has_token || run.status === 'queued' || run.status === 'running'"
                            v-bind="AutomationRunController.revoke.form({ run: run.id })"
                            class="shrink-0"
                        >
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
        </div>
    </div>
</template>

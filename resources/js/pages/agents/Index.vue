<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Bot, Copy, KeyRound } from '@lucide/vue';
import { ref, watch } from 'vue';
import { rotateToken } from '@/actions/App/Http/Controllers/AgentsController';
import McpConnectGuide from '@/components/agents/McpConnectGuide.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    mcp_url: string;
    register_url: string;
    clients: Array<{
        id: string;
        name: string;
        blurb: string;
        snippet: string;
        steps: string[];
    }>;
    general: {
        title: string;
        blurb: string;
        snippet: string;
        steps: string[];
    };
    tools: string[];
    has_mcp_token: boolean;
    plain_token: string | null;
}>();

const copied = ref(false);
const liveToken = ref<string | null>(props.plain_token);

watch(
    () => props.plain_token,
    (token) => {
        if (token) {
            liveToken.value = token;
            copied.value = false;
        }
    },
);

async function copyToken(): Promise<void> {
    if (!liveToken.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(liveToken.value);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Agents" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-6xl">
            <header class="border-b border-snitch-ink/10 pb-5">
                <p class="snitch-ink-label">Agents</p>
                <h1 class="snitch-display mt-1 text-3xl text-snitch-ink sm:text-4xl">
                    Connect your agent
                </h1>
                <p class="mt-1.5 max-w-2xl text-sm text-snitch-ink/65 sm:text-base">
                    Mint a Sanctum API token, paste the MCP config into Cursor, Claude, Codex, or any HTTP MCP client.
                </p>
            </header>

            <div class="mt-6 grid gap-6 lg:grid-cols-2 lg:items-stretch">
                <section class="snitch-scrap relative space-y-4 p-5 pt-6 sm:p-6">
                    <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                    <div class="flex items-start gap-3">
                        <KeyRound class="mt-0.5 size-4 shrink-0 text-snitch-ink/50" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <h2 class="snitch-display text-2xl text-snitch-ink">API token</h2>
                            <p class="mt-1 text-sm text-snitch-ink/70">
                                {{
                                    has_mcp_token
                                        ? 'A token already exists. Rotate to revoke the old one and issue a new secret.'
                                        : 'Create a token for your agent. Shown once after create or rotate.'
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="liveToken"
                        class="space-y-2 rounded-sm border border-snitch-spot/40 bg-snitch-spot/10 p-3"
                    >
                        <p class="text-xs font-semibold text-snitch-ink">
                            Copy this token now. It will not be shown again after you leave.
                        </p>
                        <div class="flex flex-wrap items-center gap-2">
                            <code class="min-w-0 flex-1 break-all text-xs text-snitch-ink">{{ liveToken }}</code>
                            <button
                                type="button"
                                class="snitch-btn snitch-btn-ghost px-2 py-1 text-xs"
                                @click="copyToken"
                            >
                                <Copy class="relative z-10 size-3.5" aria-hidden="true" />
                                <span class="relative z-10">{{ copied ? 'Copied' : 'Copy' }}</span>
                            </button>
                        </div>
                    </div>

                    <Form v-bind="rotateToken.form()" class="flex flex-wrap gap-2">
                        <button type="submit" class="snitch-btn snitch-btn-spot px-3 py-2 text-sm">
                            <Bot class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                            <span class="relative z-10">
                                {{ has_mcp_token ? 'Rotate token' : 'Create token' }}
                            </span>
                        </button>
                    </Form>
                </section>
            </div>

            <div class="mt-6">
                <McpConnectGuide
                    :mcp-url="mcp_url"
                    :register-url="register_url"
                    :clients="clients"
                    :general="general"
                    :tools="tools"
                    :api-token="liveToken"
                    :show-endpoints="false"
                />
            </div>
        </div>
    </div>
</template>

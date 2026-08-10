<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Bot } from '@lucide/vue';
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

const liveToken = ref<string | null>(props.plain_token);

watch(
    () => props.plain_token,
    (token) => {
        if (token) {
            liveToken.value = token;
        }
    },
);
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

            <div class="mt-6">
                <McpConnectGuide
                    :mcp-url="mcp_url"
                    :register-url="register_url"
                    :clients="clients"
                    :general="general"
                    :tools="tools"
                    :api-token="liveToken"
                    :show-endpoints="false"
                >
                    <template #title-action>
                        <Form v-bind="rotateToken.form()">
                            <button type="submit" class="snitch-btn snitch-btn-spot px-3 py-2 text-sm">
                                <Bot class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                                <span class="relative z-10">
                                    {{ has_mcp_token ? 'Rotate token' : 'Create token' }}
                                </span>
                            </button>
                        </Form>
                    </template>
                </McpConnectGuide>
            </div>
        </div>
    </div>
</template>

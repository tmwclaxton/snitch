<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Bot } from '@lucide/vue';
import { ref, watch } from 'vue';
import McpConnectGuide from '@/components/agents/McpConnectGuide.vue';
import { rotateToken } from '@/actions/App/Http/Controllers/Settings/McpController';
import { show } from '@/routes/settings/mcp';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'MCP',
                href: show(),
            },
        ],
    },
});

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
    <div>
        <Head title="MCP" />

        <McpConnectGuide
            :mcp-url="mcp_url"
            :register-url="register_url"
            :clients="clients"
            :general="general"
            :tools="tools"
            :api-token="liveToken"
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
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import McpEndpoints from '@/components/agents/McpEndpoints.vue';

type ClientGuide = {
    id: string;
    name: string;
    blurb: string;
    snippet: string;
    steps: string[];
};

const props = withDefaults(
    defineProps<{
        mcpUrl: string;
        registerUrl: string;
        clients: ClientGuide[];
        general: {
            title: string;
            blurb: string;
            snippet: string;
            steps: string[];
        };
        tools: string[];
        apiToken?: string | null;
        showEndpoints?: boolean;
    }>(),
    {
        apiToken: null,
        showEndpoints: true,
    },
);

const tokenPlaceholder = 'YOUR_SNITCH_API_TOKEN';

function withToken(snippet: string): string {
    if (!props.apiToken) {
        return snippet;
    }

    return snippet.split(tokenPlaceholder).join(props.apiToken);
}

const options = computed<ClientGuide[]>(() => [
    ...props.clients.map((client) => ({
        ...client,
        snippet: withToken(client.snippet),
    })),
    {
        id: 'general',
        name: props.general.title,
        blurb: props.general.blurb,
        snippet: withToken(props.general.snippet),
        steps: props.general.steps,
    },
]);

const activeId = ref(options.value[0]?.id ?? 'cursor');

const activeClient = computed(
    () => options.value.find((client) => client.id === activeId.value) ?? options.value[0],
);

async function copyText(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
    } catch {
        // Clipboard can fail in insecure contexts; ignore.
    }
}
</script>

<template>
    <div class="space-y-6">
        <McpEndpoints
            v-if="showEndpoints"
            :mcp-url="mcpUrl"
            :register-url="registerUrl"
        />

        <section class="snitch-scrap relative space-y-4 p-5 pt-6 sm:p-6">
            <span class="snitch-tape right-4 -top-2" aria-hidden="true" />
            <div>
                <h2 class="snitch-display text-2xl text-snitch-ink">Connect your agent</h2>
                <p class="mt-1 text-sm text-snitch-ink/70">
                    Pick your client, paste the config, then call whoami.
                </p>
            </div>

            <div class="snitch-seg flex flex-wrap gap-1" role="tablist" aria-label="Agent clients">
                <button
                    v-for="client in options"
                    :key="client.id"
                    type="button"
                    role="tab"
                    class="snitch-seg-item px-3 py-1.5 text-sm"
                    :aria-selected="activeId === client.id"
                    :class="activeId === client.id ? 'snitch-seg-item-active' : ''"
                    @click="activeId = client.id"
                >
                    {{ client.name }}
                </button>
            </div>

            <div
                v-if="activeClient"
                class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] lg:items-start lg:gap-8"
            >
                <div class="space-y-3">
                    <p class="text-sm text-snitch-ink/75">{{ activeClient.blurb }}</p>
                    <ol class="list-decimal space-y-1.5 pl-5 text-sm text-snitch-ink/80">
                        <li v-for="(step, index) in activeClient.steps" :key="index">
                            {{ step }}
                        </li>
                    </ol>
                    <p
                        v-if="apiToken"
                        class="text-xs text-snitch-ink/55"
                    >
                        Preview includes your newly created token. Copy the config before you leave this page.
                    </p>
                </div>
                <div class="relative min-w-0">
                    <pre class="overflow-x-auto bg-snitch-ink/5 p-3 text-xs text-snitch-ink lg:min-h-48">{{ activeClient.snippet }}</pre>
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-ghost absolute top-2 right-2 px-2 py-1 text-xs"
                        @click="copyText(activeClient.snippet)"
                    >
                        Copy
                    </button>
                </div>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
            <section class="snitch-scrap relative space-y-3 p-5 pt-6 sm:p-6">
                <span class="snitch-tape right-5 -top-2" aria-hidden="true" />
                <h2 class="snitch-display text-2xl text-snitch-ink">Billing notes</h2>
                <ul class="list-disc space-y-2 pl-5 text-sm text-snitch-ink/80">
                    <li>Agent-created accounts start with £0 usage until claimed.</li>
                    <li>Claiming in the browser grants £5 once.</li>
                    <li>The £19/mo platform plan includes £30 usage credits each billing period.</li>
                    <li>Billable tools need a balance above 20p - subscribe for plan value or top up credits.</li>
                    <li>Usage on the billing page is shown for Apify, NanoGPT, Firecrawl, and TikHub.</li>
                </ul>
            </section>

            <section class="snitch-scrap relative space-y-3 p-5 pt-6 sm:p-6">
                <span class="snitch-tape left-4 -top-2" aria-hidden="true" />
                <h2 class="snitch-display text-2xl text-snitch-ink">Tool catalogue</h2>
                <p class="text-sm text-snitch-ink/70">
                    Authenticated tools on the MCP server (plus create_account on register).
                </p>
                <ul class="flex flex-wrap gap-2">
                    <li
                        v-for="tool in tools"
                        :key="tool"
                        class="rounded-sm bg-snitch-ink/5 px-2 py-1 font-mono text-xs text-snitch-ink/85"
                    >
                        {{ tool }}
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>

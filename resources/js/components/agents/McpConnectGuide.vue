<script setup lang="ts">
import { computed, ref } from 'vue';

type ClientGuide = {
    id: string;
    name: string;
    blurb: string;
    snippet: string;
    steps: string[];
};

const props = defineProps<{
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
}>();

const activeId = ref(props.clients[0]?.id ?? 'cursor');

const activeClient = computed(
    () => props.clients.find((client) => client.id === activeId.value) ?? props.clients[0],
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
        <section class="snitch-scrap relative space-y-3 p-5 pt-6 sm:p-6">
            <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
            <h2 class="snitch-display text-2xl text-snitch-ink">Endpoints</h2>
            <ul class="grid gap-4 text-sm text-snitch-ink/80 sm:grid-cols-2">
                <li class="min-w-0 space-y-1">
                    <span class="snitch-ink-label">Register</span>
                    <code class="block break-all">{{ registerUrl }}</code>
                    <span class="text-snitch-ink/65">create_account (no auth)</span>
                </li>
                <li class="min-w-0 space-y-1">
                    <span class="snitch-ink-label">API</span>
                    <code class="block break-all">{{ mcpUrl }}</code>
                    <span class="text-snitch-ink/65">bearer token required</span>
                </li>
            </ul>
        </section>

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
                    v-for="client in clients"
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

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="snitch-scrap relative space-y-3 p-5 pt-6 sm:p-6">
                <span class="snitch-tape left-6 -top-2" aria-hidden="true" />
                <h2 class="snitch-display text-2xl text-snitch-ink">{{ general.title }}</h2>
                <p class="text-sm text-snitch-ink/75">{{ general.blurb }}</p>
                <ol class="list-decimal space-y-1.5 pl-5 text-sm text-snitch-ink/80">
                    <li v-for="(step, index) in general.steps" :key="index">
                        {{ step }}
                    </li>
                </ol>
                <pre class="overflow-x-auto bg-snitch-ink/5 p-3 text-xs text-snitch-ink">{{ general.snippet }}</pre>
            </section>

            <section class="snitch-scrap relative space-y-3 p-5 pt-6 sm:p-6">
                <span class="snitch-tape right-5 -top-2" aria-hidden="true" />
                <h2 class="snitch-display text-2xl text-snitch-ink">Billing notes</h2>
                <ul class="list-disc space-y-2 pl-5 text-sm text-snitch-ink/80">
                    <li>Agent-created accounts start with £0 usage until claimed.</li>
                    <li>Claiming in the browser grants £5 once.</li>
                    <li>Billable tools need an active platform plan plus credit balance.</li>
                    <li>Usage on the billing page is shown for Apify, NanoGPT, and Firecrawl.</li>
                </ul>
            </section>
        </div>

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
</template>

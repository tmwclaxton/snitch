<script setup lang="ts">
import { useToastStore } from '@/stores/toastStore';

defineProps<{
    mcpUrl: string;
    registerUrl: string;
}>();

const toast = useToastStore();

async function copyText(value: string, label: string): Promise<void> {
    if (!value.trim()) {
        return;
    }

    try {
        await navigator.clipboard.writeText(value);
        toast.success(`${label} copied to clipboard.`);
    } catch {
        toast.error('Could not access the clipboard.');
    }
}
</script>

<template>
    <section class="snitch-scrap relative space-y-4 p-5 pt-6 sm:p-8">
        <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
        <h2 class="snitch-display text-2xl text-snitch-ink">Endpoints</h2>
        <ul class="space-y-4 text-sm text-snitch-ink sm:text-base">
            <li class="flex flex-wrap items-baseline gap-x-3 gap-y-2">
                <span class="shrink-0 font-medium text-snitch-ink">Register</span>
                <code class="min-w-0 flex-1 break-all">{{ registerUrl }}</code>
                <span class="text-sm text-snitch-ink/70">create_account, no auth</span>
                <button
                    type="button"
                    class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                    @click="copyText(registerUrl, 'Register URL')"
                >
                    Copy
                </button>
            </li>
            <li class="flex flex-wrap items-baseline gap-x-3 gap-y-2">
                <span class="shrink-0 font-medium text-snitch-ink">Server</span>
                <code class="min-w-0 flex-1 break-all">{{ mcpUrl }}</code>
                <span class="text-sm text-snitch-ink/70">bearer token required</span>
                <button
                    type="button"
                    class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                    @click="copyText(mcpUrl, 'MCP URL')"
                >
                    Copy
                </button>
            </li>
        </ul>
    </section>
</template>

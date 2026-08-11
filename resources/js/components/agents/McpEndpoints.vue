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
    <section class="snitch-scrap relative h-full space-y-3 p-5 pt-6 sm:p-6">
        <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
        <h2 class="snitch-display text-2xl text-snitch-ink">Endpoints</h2>
        <ul class="grid gap-4 text-sm text-snitch-ink/80">
            <li class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="snitch-ink-label">Register</span>
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-ghost px-2 py-1 text-xs"
                        @click="copyText(registerUrl, 'Register URL')"
                    >
                        Copy
                    </button>
                </div>
                <code class="block break-all">{{ registerUrl }}</code>
                <span class="text-snitch-ink/65">create_account (no auth)</span>
            </li>
            <li class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="snitch-ink-label">API</span>
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-ghost px-2 py-1 text-xs"
                        @click="copyText(mcpUrl, 'MCP URL')"
                    >
                        Copy
                    </button>
                </div>
                <code class="block break-all">{{ mcpUrl }}</code>
                <span class="text-snitch-ink/65">bearer token required</span>
            </li>
        </ul>
    </section>
</template>

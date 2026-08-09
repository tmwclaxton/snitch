<script setup lang="ts">
defineProps<{
    mcpUrl: string;
    registerUrl: string;
}>();
</script>

<template>
    <section class="snitch-scrap relative h-full space-y-3 p-5 pt-6 sm:p-6">
        <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
        <h2 class="snitch-display text-2xl text-snitch-ink">Endpoints</h2>
        <ul class="grid gap-4 text-sm text-snitch-ink/80">
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
</template>

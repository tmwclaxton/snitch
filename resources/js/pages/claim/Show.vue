<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';

const props = defineProps<{
    email: string;
    name: string;
    claimToken: string;
    loginUrl: string;
}>();
</script>

<template>
    <Head title="Claim your Snitch account" />

    <div class="snitch-surface min-h-screen px-4 py-16">
        <div class="snitch-scrap mx-auto max-w-lg space-y-6 p-6">
            <p class="snitch-ink-label">Account claim</p>
            <h1 class="font-display text-3xl">Claim this Snitch account</h1>
            <p class="text-sm text-[var(--snitch-ink)]/75">
                An agent created <strong>{{ name }}</strong>
                <span v-if="email"> ({{ email }})</span>. Sign in with WorkOS to take ownership and receive £5
                usage credit once.
            </p>
            <Form :action="`/claim/${props.claimToken}`" method="post" class="flex flex-col gap-3">
                <button type="submit" class="snitch-btn">Continue to sign in</button>
            </Form>
            <Link :href="loginUrl" class="text-sm underline">Already have a session? Log in</Link>
        </div>
    </div>
</template>

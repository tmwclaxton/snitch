<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { LoaderCircle, Send } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { store } from '@/routes/contact';

defineOptions({
    layout: PublicLayout,
});
</script>

<template>
    <div class="px-4 py-14 sm:px-8 sm:py-20">
        <div class="mx-auto grid max-w-6xl gap-10 lg:grid-cols-[1fr_1.1fr]">
            <div>
                <h1 class="snitch-hero-display text-4xl text-snitch-caution-fog">
                    Say hello.
                </h1>
                <p class="mt-5 max-w-md text-base leading-relaxed text-snitch-caution-fog/80">
                    Questions about Snitch, billing, partnerships, or
                    privacy? Send a note. We read every message.
                </p>
                <p class="contact-annotation mt-6 text-xl text-snitch-caution-fog">
                    Prefer email?
                    <a
                        href="mailto:hello@snitchsocial.net"
                        class="font-medium underline decoration-snitch-caution-yellow/80 underline-offset-2 hover:text-snitch-caution-yellow"
                    >
                        hello@snitchsocial.net
                    </a>
                </p>
            </div>

            <div class="border border-white/10 bg-[#141416] p-6 sm:p-8">
                <Form
                    :action="store.url()"
                    method="post"
                    class="space-y-4"
                    #default="{ errors, processing, recentlySuccessful }"
                >
                    <div>
                        <label
                            for="name"
                            class="mb-1 block text-sm font-medium text-snitch-caution-fog"
                        >
                            Name
                        </label>
                        <input
                            id="name"
                            name="name"
                            type="text"
                            required
                            autocomplete="name"
                            class="w-full border border-white/20 bg-snitch-caution-ink px-3 py-2 text-sm text-snitch-caution-fog outline-none focus:border-snitch-caution-yellow"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div>
                        <label
                            for="email"
                            class="mb-1 block text-sm font-medium text-snitch-caution-fog"
                        >
                            Email
                        </label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            required
                            autocomplete="email"
                            class="w-full border border-white/20 bg-snitch-caution-ink px-3 py-2 text-sm text-snitch-caution-fog outline-none focus:border-snitch-caution-yellow"
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <div>
                        <label
                            for="message"
                            class="mb-1 block text-sm font-medium text-snitch-caution-fog"
                        >
                            Message
                        </label>
                        <textarea
                            id="message"
                            name="message"
                            rows="5"
                            required
                            class="w-full border border-white/20 bg-snitch-caution-ink px-3 py-2 text-sm text-snitch-caution-fog outline-none focus:border-snitch-caution-yellow"
                        />
                        <InputError :message="errors.message" />
                    </div>

                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 bg-snitch-caution-yellow px-5 py-2.5 text-sm font-semibold uppercase tracking-wide text-snitch-caution-ink hover:opacity-90 disabled:opacity-60"
                        :disabled="processing"
                    >
                        <LoaderCircle
                            v-if="processing"
                            class="size-3.5 shrink-0 animate-spin"
                            aria-hidden="true"
                        />
                        <Send
                            v-else
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            {{ processing ? 'Sending...' : 'Send message' }}
                        </span>
                    </button>

                    <p
                        v-if="recentlySuccessful"
                        class="text-sm text-snitch-caution-fog/80"
                    >
                        Message sent.
                    </p>
                </Form>
            </div>
        </div>
    </div>
</template>

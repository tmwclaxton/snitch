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
                <h1 class="text-4xl font-semibold tracking-tight text-neutral-950">
                    Say hello.
                </h1>
                <p class="mt-4 max-w-md text-neutral-600">
                    Questions about Snitch, billing, partnerships, or
                    privacy? Send a note. We read every message.
                </p>
                <p class="contact-annotation mt-6 text-xl text-neutral-950">
                    Prefer email?
                    <a
                        href="mailto:hello@snitchsocial.net"
                        class="font-medium underline decoration-[#F0C400]/70 underline-offset-2"
                    >
                        hello@snitchsocial.net
                    </a>
                </p>
            </div>

            <div class="border border-neutral-200 bg-white p-6 shadow-[0_20px_50px_-15px_rgba(0,0,0,0.10)] sm:p-8">
                <Form
                    :action="store.url()"
                    method="post"
                    class="space-y-4"
                    #default="{ errors, processing, recentlySuccessful }"
                >
                    <div>
                        <label
                            for="name"
                            class="mb-1 block text-sm font-medium text-neutral-950"
                        >
                            Name
                        </label>
                        <input
                            id="name"
                            name="name"
                            type="text"
                            required
                            autocomplete="name"
                            class="w-full border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-950 outline-none focus:border-neutral-400"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div>
                        <label
                            for="email"
                            class="mb-1 block text-sm font-medium text-neutral-950"
                        >
                            Email
                        </label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            required
                            autocomplete="email"
                            class="w-full border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-950 outline-none focus:border-neutral-400"
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <div>
                        <label
                            for="message"
                            class="mb-1 block text-sm font-medium text-neutral-950"
                        >
                            Message
                        </label>
                        <textarea
                            id="message"
                            name="message"
                            rows="5"
                            required
                            class="w-full border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-950 outline-none focus:border-neutral-400"
                        />
                        <InputError :message="errors.message" />
                    </div>

                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 bg-[#F0C400] px-5 py-2.5 text-sm font-medium text-neutral-950 hover:opacity-90 disabled:opacity-60"
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
                        class="text-sm text-neutral-600"
                    >
                        Message sent.
                    </p>
                </Form>
            </div>
        </div>
    </div>
</template>

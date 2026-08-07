<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { store } from '@/routes/contact';

defineOptions({
    layout: PublicLayout,
});
</script>

<template>
    <div>
        <SeoHead
            title="Contact"
            description="Get in touch with the Snitch team."
            path="/contact"
        />

        <div class="px-5 py-14 sm:px-8 sm:py-20">
            <div class="mx-auto grid max-w-6xl gap-10 lg:grid-cols-[1fr_1.1fr]">
                <div>
                    <h1 class="snitch-display text-4xl text-snitch-ink">
                        Say hello.
                    </h1>
                    <p class="mt-4 max-w-md text-snitch-ink/80">
                        Questions about Snitch, partnerships, or privacy? Send a
                        note. We read every message.
                    </p>
                    <p
                        class="snitch-annotation contact-annotation mt-6 text-xl text-snitch-ink"
                    >
                        Prefer email?
                        <a
                            href="mailto:hello@snitch.app"
                            class="snitch-marker-underline text-snitch-ink"
                        >
                            hello@snitch.app
                        </a>
                    </p>
                </div>

                <div class="snitch-doc relative p-6 sm:p-8">
                    <Form
                        v-bind="store.form()"
                        class="relative z-10 space-y-4"
                        #default="{ errors, processing, recentlySuccessful }"
                    >
                        <div>
                            <label
                                for="name"
                                class="mb-1 block text-sm font-medium text-snitch-ink"
                            >
                                Name
                            </label>
                            <input
                                id="name"
                                name="name"
                                type="text"
                                required
                                autocomplete="name"
                                class="snitch-field"
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div>
                            <label
                                for="email"
                                class="mb-1 block text-sm font-medium text-snitch-ink"
                            >
                                Email
                            </label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                required
                                autocomplete="email"
                                class="snitch-field"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div>
                            <label
                                for="message"
                                class="mb-1 block text-sm font-medium text-snitch-ink"
                            >
                                Message
                            </label>
                            <textarea
                                id="message"
                                name="message"
                                rows="5"
                                required
                                class="snitch-field"
                            />
                            <InputError :message="errors.message" />
                        </div>

                        <button
                            type="submit"
                            class="snitch-btn"
                            :disabled="processing"
                        >
                            {{ processing ? 'Sending...' : 'Send message' }}
                        </button>

                        <p
                            v-if="recentlySuccessful"
                            class="text-sm text-snitch-ink/80"
                        >
                            Message sent.
                        </p>
                    </Form>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.contact-annotation {
    color: var(--snitch-ink);
}
</style>

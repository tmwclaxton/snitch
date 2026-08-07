<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import InputError from '@/components/InputError.vue';
import { edit } from '@/routes/profile';

defineProps<{
    status?: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const form = useForm({
    name: user.value.name,
    email: user.value.email,
});

function submit(): void {
    form.patch(ProfileController.update.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div>
        <Head title="Profile settings" />

        <div class="snitch-doc relative space-y-8 p-5 sm:p-6">
            <span class="snitch-tape left-5 -top-2" aria-hidden="true" />

            <div class="relative z-10">
                <h2 class="snitch-display text-2xl text-snitch-ink">
                    Profile information
                </h2>
                <p class="mt-1.5 text-sm text-snitch-ink/65">
                    Update your name and email address.
                </p>

                <form class="mt-5 space-y-4" @submit.prevent="submit">
                    <label class="block text-sm font-medium text-snitch-ink">
                        Name
                        <input
                            id="name"
                            v-model="form.name"
                            class="snitch-field mt-1"
                            required
                            autocomplete="name"
                            placeholder="Full name"
                        />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </label>

                    <label class="block text-sm font-medium text-snitch-ink">
                        Email address
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="snitch-field mt-1"
                            required
                            autocomplete="username"
                            placeholder="Email address"
                            disabled
                        />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </label>

                    <button
                        type="submit"
                        class="snitch-btn snitch-btn-spot"
                        :disabled="form.processing"
                        data-test="update-profile-button"
                    >
                        <span class="relative z-10">
                            {{ form.processing ? 'Saving…' : 'Save' }}
                        </span>
                    </button>
                </form>
            </div>

            <div class="relative z-10 border-t border-dashed border-snitch-ink/15 pt-8">
                <DeleteUser />
            </div>
        </div>
    </div>
</template>

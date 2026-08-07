<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    destroy,
    store,
    sync,
} from '@/actions/App/Http/Controllers/CompetitorController';
import AppLayout from '@/layouts/AppLayout.vue';

type Account = {
    id: number;
    platform: string;
    handle: string;
    display_name: string | null;
    avatar: string | null;
    url: string;
    posts_count?: number;
    last_synced_at: string | null;
};

const props = defineProps<{
    accounts: Account[];
    platforms: string[];
}>();

defineOptions({
    layout: AppLayout,
});

const form = useForm({
    platform: props.platforms[0] ?? 'instagram',
    handle: '',
    url: '',
    display_name: '',
});

function remove(account: Account): void {
    router.delete(destroy.url(account.id));
}

function syncNow(account: Account): void {
    router.post(sync.url(account.id));
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full p-6">
        <Head title="Competitors" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-5xl">
            <h1 class="snitch-display text-4xl text-snitch-ink">
                Tracked accounts
            </h1>
            <p class="mt-2 text-snitch-ink/65">
                Cutout profiles from Instagram, TikTok, Facebook, LinkedIn, and
                Pinterest.
            </p>

            <form
                class="snitch-scrap relative mt-8 grid gap-3 p-5 pt-8 md:grid-cols-4"
                @submit.prevent="form.post(store.url(), { onSuccess: () => form.reset('handle', 'url', 'display_name') })"
            >
                <span class="snitch-tape right-8 -top-2" aria-hidden="true" />
                <label>
                    <span class="text-xs uppercase tracking-wide text-snitch-ink/55">Platform</span>
                    <select v-model="form.platform" class="snitch-field mt-1">
                        <option v-for="platform in platforms" :key="platform" :value="platform">
                            {{ platform }}
                        </option>
                    </select>
                </label>
                <label>
                    <span class="text-xs uppercase tracking-wide text-snitch-ink/55">Handle</span>
                    <input
                        v-model="form.handle"
                        class="snitch-field mt-1"
                        placeholder="@competitor"
                        required
                    />
                </label>
                <label>
                    <span class="text-xs uppercase tracking-wide text-snitch-ink/55">URL</span>
                    <input
                        v-model="form.url"
                        type="url"
                        class="snitch-field mt-1"
                    />
                </label>
                <div class="flex items-end">
                    <button type="submit" class="snitch-btn w-full" :disabled="form.processing">
                        Add
                    </button>
                </div>
            </form>

            <ul class="snitch-contact-reveal mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="(account, index) in accounts"
                    :key="account.id"
                    class="snitch-cutout relative overflow-hidden p-5"
                >
                    <span
                        class="snitch-stamp"
                        :style="{
                            transform: `rotate(${index % 2 === 0 ? -2 : 1.5}deg)`,
                        }"
                    >
                        {{ account.platform }}
                    </span>
                    <div class="mt-4 flex items-center gap-3">
                        <img
                            v-if="account.avatar"
                            :src="account.avatar"
                            alt=""
                            class="h-14 w-14 object-cover shadow-[3px_3px_0_rgba(0,0,0,0.12)]"
                            style="clip-path: polygon(4% 0, 100% 3%, 96% 100%, 0 97%)"
                        />
                        <div
                            v-else
                            class="flex h-14 w-14 items-center justify-center bg-snitch-teal/20 text-sm font-semibold"
                            style="clip-path: polygon(4% 0, 100% 3%, 96% 100%, 0 97%)"
                        >
                            {{ account.handle.slice(0, 2).toUpperCase() }}
                        </div>
                        <div>
                            <p class="snitch-display text-xl">
                                {{ account.display_name || account.handle }}
                            </p>
                            <p class="snitch-annotation text-lg">
                                @{{ account.handle }}
                            </p>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-snitch-ink/55">
                        {{ account.posts_count ?? 0 }} posts
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                            @click="syncNow(account)"
                        >
                            Sync now
                        </button>
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                            @click="remove(account)"
                        >
                            Remove
                        </button>
                        <Link
                            v-if="account.url"
                            :href="account.url"
                            class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                            target="_blank"
                        >
                            Open
                        </Link>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>

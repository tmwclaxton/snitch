<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import SnitchImage from '@/components/SnitchImage.vue';

const props = defineProps<{
    post: {
        id: number;
        handle: string | null;
        is_own_account?: boolean;
        format: string;
        pi: number;
        hook: string | null;
        thumbnail_url: string | null;
        url: string | null;
    };
}>();

const open = ref(false);

/** Bare handle (no @) with soft wrap only after . or _. Full handle always visible. */
const handle = computed(() => {
    if (props.post.is_own_account) {
        return 'You';
    }

    const bare = (props.post.handle ?? 'unknown').replace(/^@/, '');

    return bare.replace(/([._])/g, '$1\u200b');
});

const handleLabel = computed(() =>
    props.post.is_own_account ? 'You' : (props.post.handle ?? 'unknown').replace(/^@/, ''),
);

const piLabel = computed(() => `${props.post.pi.toFixed(1)}× usual`);
</script>

<template>
    <Link
        :href="feedShow.url(post.id)"
        class="group relative flex h-full min-w-0 flex-col overflow-hidden rounded border border-snitch-ink/10 bg-snitch-lift text-left"
        @mouseenter="open = true"
        @mouseleave="open = false"
        @focus="open = true"
        @blur="open = false"
    >
        <div class="relative aspect-[4/5] w-full shrink-0 overflow-hidden bg-snitch-ink/5">
            <SnitchImage
                v-if="post.thumbnail_url"
                :src="post.thumbnail_url"
                :alt="handleLabel"
                class="absolute inset-0 block size-full"
                img-class="size-full object-cover"
            />
            <div
                v-else
                class="flex size-full items-center justify-center bg-snitch-fog/40 p-2"
            >
                <span class="snitch-format-tag text-sm">{{ post.format }}</span>
            </div>
            <div
                v-if="open && post.hook"
                class="absolute inset-0 overflow-y-auto bg-[#0e0e10]/92 p-2 text-sm leading-snug text-[#edeae2]"
            >
                {{ post.hook }}
            </div>
        </div>
        <div class="space-y-0.5 p-2">
            <p class="text-[13px] font-medium leading-snug break-words text-snitch-ink">
                {{ handle }}
            </p>
            <p class="font-mono text-base font-semibold tabular-nums text-snitch-ink">
                {{ piLabel }}
            </p>
        </div>
    </Link>
</template>

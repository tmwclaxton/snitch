<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import SnitchAvatar from '@/components/SnitchAvatar.vue';

export type SnitchFace = {
    id: number;
    handle: string;
    display_name: string | null;
    avatar: string | null;
    platform: string;
};

defineProps<{
    snitches: SnitchFace[];
}>();

function label(snitch: SnitchFace): string {
    return snitch.display_name?.trim() || `@${snitch.handle}`;
}
</script>

<template>
    <div
        v-if="snitches.length"
        class="snitch-face-pile"
        aria-label="These snitches"
    >
        <Link
            v-for="(snitch, index) in snitches"
            :key="snitch.id"
            :href="competitorShow.url(snitch.id)"
            class="snitch-face-pile-item"
            :style="{ zIndex: String(snitches.length - index) }"
            :title="label(snitch)"
        >
            <span class="snitch-face-pile-mark">
                <SnitchAvatar
                    :src="snitch.avatar"
                    :name="snitch.display_name"
                    :handle="snitch.handle"
                    :alt="label(snitch)"
                    size="sm"
                />
            </span>
            <span class="snitch-face-pile-name">{{ label(snitch) }}</span>
        </Link>
    </div>
</template>

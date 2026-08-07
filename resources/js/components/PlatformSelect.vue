<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { platformIconSrc, platformLabel } from '@/lib/platforms';
import { cn } from '@/lib/utils';

const props = defineProps<{
    modelValue: string;
    platforms: string[];
    id?: string;
    class?: HTMLAttributes['class'];
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const selected = computed(() => props.modelValue);

function onUpdate(value: unknown): void {
    if (typeof value === 'string' && value !== '') {
        emit('update:modelValue', value);
    }
}
</script>

<template>
    <Select :model-value="selected" @update:model-value="onUpdate">
        <SelectTrigger
            :id="id"
            :class="
                cn(
                    'snitch-platform-select-trigger mt-1 w-full rounded-none shadow-none ring-0 focus-visible:ring-0 data-[size=default]:h-auto data-[size=sm]:h-auto',
                    props.class,
                )
            "
            aria-label="Platform"
        >
            <SelectValue placeholder="Platform">
                <span class="flex min-w-0 items-center gap-2">
                    <img
                        v-if="selected"
                        :src="platformIconSrc(selected)"
                        alt=""
                        class="snitch-platform-logo size-5 shrink-0"
                        width="20"
                        height="20"
                    />
                    <span class="truncate">{{ platformLabel(selected) }}</span>
                </span>
            </SelectValue>
        </SelectTrigger>

        <SelectContent class="snitch-platform-select-content">
            <SelectItem
                v-for="platform in platforms"
                :key="platform"
                :value="platform"
                class="snitch-platform-select-item"
            >
                <span class="flex min-w-0 items-center gap-2">
                    <img
                        :src="platformIconSrc(platform)"
                        alt=""
                        class="snitch-platform-logo size-5 shrink-0"
                        width="20"
                        height="20"
                    />
                    <span>{{ platformLabel(platform) }}</span>
                </span>
            </SelectItem>
        </SelectContent>
    </Select>
</template>

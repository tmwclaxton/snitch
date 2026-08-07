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
import { cn } from '@/lib/utils';

export type PaperSelectOption = {
    value: string;
    label: string;
    iconSrc?: string | null;
};

const props = defineProps<{
    modelValue: string;
    options: PaperSelectOption[];
    id?: string;
    ariaLabel?: string;
    placeholder?: string;
    class?: HTMLAttributes['class'];
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const selected = computed(() => props.modelValue);

const selectedOption = computed(
    () => props.options.find((option) => option.value === selected.value) ?? null,
);

function onUpdate(value: unknown): void {
    if (typeof value === 'string') {
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
                    'snitch-platform-select-trigger w-full rounded-none shadow-none ring-0 focus-visible:ring-0 data-[size=default]:h-auto data-[size=sm]:h-auto',
                    props.class,
                )
            "
            :aria-label="ariaLabel"
        >
            <SelectValue :placeholder="placeholder ?? 'Select'">
                <span class="flex min-w-0 items-center gap-2">
                    <img
                        v-if="selectedOption?.iconSrc"
                        :src="selectedOption.iconSrc"
                        alt=""
                        class="snitch-platform-logo size-5 shrink-0"
                        width="20"
                        height="20"
                    />
                    <span class="truncate">
                        {{ selectedOption?.label ?? placeholder ?? 'Select' }}
                    </span>
                </span>
            </SelectValue>
        </SelectTrigger>

        <SelectContent class="snitch-platform-select-content">
            <SelectItem
                v-for="option in options"
                :key="option.value"
                :value="option.value"
                class="snitch-platform-select-item"
            >
                <span class="flex min-w-0 items-center gap-2">
                    <img
                        v-if="option.iconSrc"
                        :src="option.iconSrc"
                        alt=""
                        class="snitch-platform-logo size-5 shrink-0"
                        width="20"
                        height="20"
                    />
                    <span>{{ option.label }}</span>
                </span>
            </SelectItem>
        </SelectContent>
    </Select>
</template>

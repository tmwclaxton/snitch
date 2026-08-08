<script setup lang="ts">
import { Check, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AnalysisTermChip from '@/components/AnalysisTermChip.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { analysisTermIcon } from '@/lib/analysisTerms';
import type { AnalysisTermDimension } from '@/lib/analysisTerms';

export type PaperTermPickerOption = {
    slug: string;
    label: string;
    section: string;
    count?: number;
};

const props = defineProps<{
    open: boolean;
    title: string;
    description?: string;
    options: PaperTermPickerOption[];
    modelValue: string[];
    dimension?: AnalysisTermDimension;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    'update:modelValue': [value: string[]];
}>();

const draft = ref<string[]>([]);
const query = ref('');

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            draft.value = [...props.modelValue];
            query.value = '';
        }
    },
);

const sections = computed(() => {
    const needle = query.value.trim().toLowerCase();
    const grouped = new Map<string, PaperTermPickerOption[]>();

    for (const option of props.options) {
        if (needle !== '') {
            const haystack = `${option.label} ${option.slug} ${option.section}`.toLowerCase();

            if (!haystack.includes(needle) && !option.slug.replaceAll('_', ' ').includes(needle)) {
                continue;
            }
        }

        const bucket = grouped.get(option.section) ?? [];
        bucket.push(option);
        grouped.set(option.section, bucket);
    }

    return [...grouped.entries()].map(([name, terms]) => ({
        name,
        terms: terms.sort((a, b) => a.label.localeCompare(b.label)),
    }));
});

const selectedCount = computed(() => draft.value.length);

function setOpen(value: boolean): void {
    emit('update:open', value);
}

function toggle(slug: string): void {
    if (draft.value.includes(slug)) {
        draft.value = draft.value.filter((value) => value !== slug);

        return;
    }

    draft.value = [...draft.value, slug];
}

function clearDraft(): void {
    draft.value = [];
}

function apply(): void {
    emit('update:modelValue', [...draft.value]);
    emit('update:open', false);
}

function isSelected(slug: string): boolean {
    return draft.value.includes(slug);
}
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent
            class="snitch-modal-panel snitch-term-picker-dialog flex max-h-[min(88vh,44rem)] w-full max-w-3xl flex-col gap-0 overflow-hidden border-0 bg-transparent p-0 shadow-none sm:max-w-3xl"
            :show-close-button="false"
        >
            <div class="snitch-scrap relative flex max-h-[min(88vh,44rem)] flex-col overflow-hidden p-5 sm:p-6">
                <span class="snitch-tape left-8 -top-2" aria-hidden="true" />

                <DialogHeader class="relative z-10 shrink-0 space-y-2 text-left">
                    <DialogTitle class="snitch-display flex items-center gap-2 text-2xl text-snitch-ink">
                        <component
                            :is="analysisTermIcon({ dimension })"
                            class="size-5 shrink-0 text-snitch-ink/55"
                            aria-hidden="true"
                        />
                        {{ title }}
                    </DialogTitle>
                    <DialogDescription class="text-sm text-snitch-ink/65">
                        {{ description ?? 'Pick one or more. Every option is listed below.' }}
                    </DialogDescription>
                </DialogHeader>

                <div class="relative z-10 mt-4 shrink-0">
                    <label class="snitch-filter-field">
                        <span>Filter list</span>
                        <input
                            v-model="query"
                            type="search"
                            class="snitch-platform-select-trigger w-full rounded-none text-sm text-snitch-ink outline-none placeholder:text-snitch-ink/35"
                            placeholder="Narrow the list…"
                            aria-label="Filter terms in picker"
                        >
                    </label>
                    <p class="mt-2 text-xs text-snitch-ink/50">
                        {{ selectedCount }} selected
                        <span v-if="query.trim()"> · showing matches only</span>
                    </p>
                </div>

                <div class="relative z-10 mt-4 min-h-0 flex-1 overflow-y-auto pe-1">
                    <div
                        v-if="sections.length === 0"
                        class="py-8 text-center text-sm text-snitch-ink/55"
                    >
                        No terms match that filter.
                    </div>

                    <section
                        v-for="section in sections"
                        :key="section.name"
                        class="mb-5 last:mb-1"
                    >
                        <h3 class="snitch-ink-label mb-2 inline-flex items-center gap-1.5">
                            <component
                                :is="analysisTermIcon({ dimension, section: section.name })"
                                class="size-3 shrink-0 opacity-70"
                                aria-hidden="true"
                            />
                            {{ section.name }}
                            <span class="ms-0.5 normal-case tracking-normal text-snitch-ink/40">
                                ({{ section.terms.length }})
                            </span>
                        </h3>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="term in section.terms"
                                :key="term.slug"
                                type="button"
                                class="p-0"
                                :aria-pressed="isSelected(term.slug)"
                                @click="toggle(term.slug)"
                            >
                                <AnalysisTermChip
                                    variant="picker"
                                    :label="term.label"
                                    :dimension="dimension"
                                    :section="section.name"
                                    :slug="term.slug"
                                    :count="term.count"
                                    :selected="isSelected(term.slug)"
                                />
                            </button>
                        </div>
                    </section>
                </div>

                <DialogFooter class="relative z-10 mt-4 shrink-0 flex-row flex-wrap items-center justify-between gap-2 border-t border-snitch-ink/10 pt-4 sm:justify-between">
                    <button
                        type="button"
                        class="text-sm font-medium text-snitch-ink/55 underline decoration-snitch-ink/20 underline-offset-4 transition hover:text-snitch-ink"
                        @click="clearDraft"
                    >
                        Clear selection
                    </button>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                            @click="setOpen(false)"
                        >
                            <X class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                            <span class="relative z-10">Cancel</span>
                        </button>
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-spot px-3 py-1.5 text-sm"
                            @click="apply"
                        >
                            <span class="relative z-10 inline-flex items-center gap-1.5">
                                <Check class="size-3.5 shrink-0" aria-hidden="true" />
                                Apply
                            </span>
                        </button>
                    </div>
                </DialogFooter>
            </div>
        </DialogContent>
    </Dialog>
</template>

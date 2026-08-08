<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

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
                    <DialogTitle class="snitch-display text-2xl text-snitch-ink">
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
                        <h3 class="snitch-ink-label mb-2">
                            {{ section.name }}
                            <span class="ms-1 normal-case tracking-normal text-snitch-ink/40">
                                ({{ section.terms.length }})
                            </span>
                        </h3>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="term in section.terms"
                                :key="term.slug"
                                type="button"
                                class="border px-2.5 py-1.5 text-left text-sm transition"
                                :class="
                                    isSelected(term.slug)
                                        ? 'border-snitch-ink/25 bg-snitch-spot text-snitch-on-spot shadow-[2px_2px_0_color-mix(in_oklab,var(--snitch-press)_35%,transparent)]'
                                        : 'border-snitch-ink/15 bg-[color-mix(in_oklab,var(--snitch-lift)_55%,var(--snitch-paper))] text-snitch-ink shadow-[1px_1px_0_color-mix(in_oklab,var(--snitch-spot)_18%,transparent)] hover:border-snitch-ink/30'
                                "
                                :aria-pressed="isSelected(term.slug)"
                                @click="toggle(term.slug)"
                            >
                                {{ term.label }}
                                <span
                                    v-if="term.count != null && term.count > 0"
                                    :class="
                                        isSelected(term.slug)
                                            ? 'text-snitch-on-spot/70'
                                            : 'text-snitch-ink/50'
                                    "
                                >
                                    · {{ term.count }}
                                </span>
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
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-spot px-3 py-1.5 text-sm"
                            @click="apply"
                        >
                            <span class="relative z-10">Apply</span>
                        </button>
                    </div>
                </DialogFooter>
            </div>
        </DialogContent>
    </Dialog>
</template>

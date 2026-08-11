<script setup lang="ts">
import { LoaderCircle, Sparkles, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    generateBrief,
    updateBrief,
} from '@/actions/App/Http/Controllers/CompetitorController';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { platformIconSrc, platformLabel } from '@/lib/platforms';
import { useToastStore } from '@/stores/toastStore';

const props = defineProps<{
    open: boolean;
    platforms: string[];
    brief: string;
    busy?: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    submit: [payload: { platforms: string[]; brief: string }];
}>();

const toast = useToastStore();
const BRIEF_AUTOSAVE_MS = 500;

const selectedPlatforms = ref<string[]>([...props.platforms]);
const briefText = ref(props.brief ?? '');
const generatingBrief = ref(false);
const briefSaveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
let briefSaveTimer: ReturnType<typeof setTimeout> | null = null;
let lastSavedBrief = props.brief ?? '';

const canKickOff = computed(
    () =>
        selectedPlatforms.value.length > 0 &&
        briefText.value.trim().length >= 8 &&
        !props.busy &&
        !generatingBrief.value,
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        selectedPlatforms.value = [...props.platforms];
        briefText.value = props.brief ?? '';
        lastSavedBrief = props.brief ?? '';
        briefSaveState.value = 'idle';
    },
);

function setOpen(value: boolean): void {
    emit('update:open', value);
}

function csrfToken(): string {
    return (
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
    );
}

function jsonHeaders(): Record<string, string> {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
    };
}

function togglePlatform(platform: string): void {
    if (selectedPlatforms.value.includes(platform)) {
        if (selectedPlatforms.value.length === 1) {
            return;
        }

        selectedPlatforms.value = selectedPlatforms.value.filter((item) => item !== platform);

        return;
    }

    selectedPlatforms.value = [...selectedPlatforms.value, platform];
}

function clearBriefSaveTimer(): void {
    if (briefSaveTimer !== null) {
        clearTimeout(briefSaveTimer);
        briefSaveTimer = null;
    }
}

async function persistBrief(value: string): Promise<void> {
    if (value === lastSavedBrief) {
        return;
    }

    briefSaveState.value = 'saving';

    try {
        const response = await fetch(updateBrief.url(), {
            method: 'PATCH',
            headers: jsonHeaders(),
            body: JSON.stringify({
                competitor_brief: value,
            }),
        });

        if (!response.ok) {
            briefSaveState.value = 'error';

            return;
        }

        lastSavedBrief = value;
        briefSaveState.value = 'saved';
    } catch {
        briefSaveState.value = 'error';
    }
}

function scheduleBriefSave(): void {
    clearBriefSaveTimer();
    briefSaveTimer = setTimeout(() => {
        briefSaveTimer = null;
        void persistBrief(briefText.value);
    }, BRIEF_AUTOSAVE_MS);
}

async function onGenerateBrief(): Promise<void> {
    if (generatingBrief.value || props.busy || selectedPlatforms.value.length === 0) {
        return;
    }

    clearBriefSaveTimer();
    generatingBrief.value = true;

    try {
        const response = await fetch(generateBrief.url(), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({
                platforms: selectedPlatforms.value,
            }),
        });

        const data = (await response.json()) as { brief?: string; message?: string };

        if (!response.ok) {
            toast.error(data.message || 'Could not generate brief.');

            return;
        }

        briefText.value = data.brief ?? '';
        lastSavedBrief = briefText.value;
        briefSaveState.value = 'saved';
    } catch {
        toast.error('Could not generate brief.');
    } finally {
        generatingBrief.value = false;
    }
}

function onKickOff(): void {
    if (!canKickOff.value) {
        return;
    }

    clearBriefSaveTimer();
    emit('submit', {
        platforms: [...selectedPlatforms.value],
        brief: briefText.value.trim(),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent
            :show-close-button="false"
            class="snitch-modal-panel gap-0 overflow-hidden border-0 p-0 shadow-none sm:max-w-xl"
        >
            <div class="snitch-doc relative max-h-[min(90vh,40rem)] overflow-y-auto p-6 sm:p-7">
                <span class="snitch-tape left-6 -top-2" aria-hidden="true" />

                <div class="relative z-10 flex items-start justify-between gap-4">
                    <div>
                        <DialogTitle class="snitch-display flex items-center gap-2 text-2xl text-snitch-ink">
                            <Sparkles class="size-5 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                            Suggest competitors
                        </DialogTitle>
                        <DialogDescription class="mt-1.5 text-sm text-snitch-ink/65">
                            Steer niche and platforms before we scrape the web for rivals.
                        </DialogDescription>
                    </div>
                    <DialogClose
                        class="snitch-btn snitch-btn-ghost shrink-0 px-2.5 py-1.5 text-sm"
                        aria-label="Close suggest options"
                    >
                        <X class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                        <span class="relative z-10">Close</span>
                    </DialogClose>
                </div>

                <div class="relative z-10 mt-5 space-y-5">
                    <div>
                        <span class="snitch-ink-label">Platforms</span>
                        <div class="snitch-seg mt-2 flex flex-wrap gap-1" role="group" aria-label="Suggest platforms">
                            <button
                                v-for="platform in platforms"
                                :key="platform"
                                type="button"
                                class="snitch-seg-item inline-flex items-center gap-1.5 px-2.5 py-1.5 text-sm"
                                :class="
                                    selectedPlatforms.includes(platform)
                                        ? 'snitch-seg-item-active'
                                        : ''
                                "
                                :aria-pressed="selectedPlatforms.includes(platform)"
                                @click="togglePlatform(platform)"
                            >
                                <img
                                    :src="platformIconSrc(platform)"
                                    alt=""
                                    class="size-3.5 shrink-0"
                                    width="14"
                                    height="14"
                                />
                                {{ platformLabel(platform) }}
                            </button>
                        </div>
                        <p class="mt-1.5 text-xs text-snitch-ink/55">
                            Pick TikTok / Instagram when you want reel-like posts in the feed.
                        </p>
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="snitch-ink-label">Brief</span>
                            <span
                                v-if="briefSaveState === 'saving'"
                                class="text-xs text-snitch-ink/45"
                            >
                                Saving…
                            </span>
                            <span
                                v-else-if="briefSaveState === 'saved'"
                                class="text-xs text-snitch-ink/45"
                            >
                                Saved
                            </span>
                            <span
                                v-else-if="briefSaveState === 'error'"
                                class="text-xs text-red-700/80"
                            >
                                Save failed
                            </span>
                        </div>
                        <textarea
                            v-model="briefText"
                            rows="4"
                            class="snitch-field mt-2 w-full resize-y text-sm"
                            placeholder="Describe the niche rivals you want - e.g. social listening / competitor intelligence SaaS on TikTok and Instagram."
                            :disabled="busy || generatingBrief"
                            @input="scheduleBriefSave"
                        />
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                                :disabled="generatingBrief || busy || selectedPlatforms.length === 0"
                                @click="onGenerateBrief"
                            >
                                <LoaderCircle
                                    v-if="generatingBrief"
                                    class="relative z-10 size-3.5 shrink-0 animate-spin"
                                    aria-hidden="true"
                                />
                                <span class="relative z-10">
                                    {{ generatingBrief ? 'Generating…' : 'Generate' }}
                                </span>
                            </button>
                            <button
                                type="button"
                                class="snitch-btn snitch-btn-spot px-3 py-1.5 text-sm"
                                :disabled="!canKickOff"
                                @click="onKickOff"
                            >
                                <LoaderCircle
                                    v-if="busy"
                                    class="relative z-10 size-3.5 shrink-0 animate-spin"
                                    aria-hidden="true"
                                />
                                <Sparkles
                                    v-else
                                    class="relative z-10 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="relative z-10">
                                    {{ busy ? 'Finding…' : 'Kick off suggest' }}
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>

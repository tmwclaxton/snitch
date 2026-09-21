<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed } from 'vue';
import WatchingYouController from '@/actions/App/Http/Controllers/WatchingYouController';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { platformIconSrc, platformLabel } from '@/lib/platforms';

export type WatchingRow = {
    handle: string | null;
    watched: boolean;
    watcher_count: number;
    platform?: string;
};

export type WatchingPayload = {
    brand: WatchingRow[];
    check: WatchingRow | null;
};

type ListedRow = WatchingRow & { handle: string };

const props = defineProps<{
    open: boolean;
    watching?: WatchingPayload | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const form = useForm({
    handle: '',
});

const brandRows = computed(() =>
    (props.watching?.brand ?? []).filter((row): row is ListedRow => Boolean(row.handle)),
);

const checkRow = computed((): ListedRow | null => {
    const check = props.watching?.check;

    if (!check?.handle) {
        return null;
    }

    const alreadyListed = brandRows.value.some(
        (row) => row.handle.toLowerCase() === check.handle?.toLowerCase(),
    );

    return alreadyListed ? null : { ...check, handle: check.handle };
});

const resultRows = computed((): ListedRow[] => {
    const rows = [...brandRows.value];

    if (checkRow.value) {
        rows.push(checkRow.value);
    }

    return rows;
});

function setOpen(value: boolean): void {
    emit('update:open', value);
}

function submit(): void {
    form.post(WatchingYouController.url(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.reset('handle'),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent
            :show-close-button="false"
            class="snitch-modal-panel gap-0 overflow-hidden border-0 p-0 shadow-none sm:max-w-md"
        >
            <div class="snitch-doc relative space-y-6 p-6 sm:p-8">
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />

                <DialogHeader class="relative z-10 space-y-1 text-left">
                    <DialogTitle class="snitch-display text-xl text-snitch-ink">
                        Am I being tracked?
                    </DialogTitle>
                    <DialogDescription class="sr-only">
                        Check a username
                    </DialogDescription>
                </DialogHeader>

                <ul
                    v-if="resultRows.length"
                    class="relative z-10 space-y-2"
                    data-test="watching-results"
                >
                    <li
                        v-for="row in resultRows"
                        :key="`${row.platform ?? 'check'}-${row.handle}`"
                        class="flex items-center gap-3 border border-snitch-ink/10 px-4 py-3"
                        :data-test="row === checkRow ? 'watching-check-result' : undefined"
                    >
                        <img
                            v-if="row.platform"
                            :src="platformIconSrc(row.platform)"
                            :alt="`${platformLabel(row.platform)} logo`"
                            class="snitch-platform-logo size-6 shrink-0 object-contain"
                            width="24"
                            height="24"
                        />
                        <span class="min-w-0 flex-1 truncate text-sm text-snitch-ink">
                            @{{ row.handle }}
                        </span>
                        <span class="shrink-0 text-sm font-medium text-snitch-ink">
                            {{ row.watched ? 'Yes' : 'No' }}
                        </span>
                    </li>
                </ul>

                <form
                    class="relative z-10 space-y-6"
                    @submit.prevent="submit"
                >
                    <label class="block">
                        <span class="snitch-ink-label">Username</span>
                        <input
                            v-model="form.handle"
                            type="text"
                            name="handle"
                            maxlength="80"
                            autocomplete="off"
                            class="mt-2 w-full border border-snitch-ink/15 bg-snitch-paper px-4 py-2.5 text-sm text-snitch-ink"
                            placeholder="@handle"
                        />
                    </label>

                    <DialogFooter class="gap-2 sm:justify-start">
                        <DialogClose as-child>
                            <button type="button" class="snitch-btn snitch-btn-ghost">
                                <X class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                                <span class="relative z-10">Close</span>
                            </button>
                        </DialogClose>
                        <button
                            type="submit"
                            class="snitch-btn"
                            :disabled="form.processing"
                        >
                            <span class="relative z-10">{{ form.processing ? 'Checking...' : 'Check' }}</span>
                        </button>
                    </DialogFooter>
                </form>
            </div>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LoaderCircle, RefreshCw, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    batchSync,
    sync,
} from '@/actions/App/Http/Controllers/CompetitorController';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type SyncableAccount = {
    id: number;
    handle: string;
    display_name: string | null;
};

type SyncDefaults = {
    posts_limit: number;
    recency_days: number;
    posts_limit_max: number;
    recency_days_max: number;
};

const props = defineProps<{
    open: boolean;
    account?: SyncableAccount | null;
    accounts?: SyncableAccount[];
    syncDefaults: SyncDefaults;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    synced: [];
}>();

const processing = ref(false);
const postsLimit = ref(props.syncDefaults.posts_limit);
const recencyDays = ref(props.syncDefaults.recency_days);

const targets = computed(() => {
    if (props.accounts && props.accounts.length > 0) {
        return props.accounts;
    }

    return props.account ? [props.account] : [];
});

const isBatch = computed(() => targets.value.length > 1);

const displayName = computed(() => {
    const first = targets.value[0];

    if (!first) {
        return 'this snitch';
    }

    return first.display_name || first.handle || 'this snitch';
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        postsLimit.value = props.syncDefaults.posts_limit;
        recencyDays.value = props.syncDefaults.recency_days;
    },
);

function setOpen(value: boolean): void {
    if (!value) {
        processing.value = false;
    }

    emit('update:open', value);
}

function confirmSync(): void {
    if (targets.value.length === 0 || processing.value) {
        return;
    }

    processing.value = true;

    const payload = {
        posts_limit: postsLimit.value,
        recency_days: recencyDays.value,
    };

    const onFinish = (): void => {
        processing.value = false;
    };

    const onSuccess = (): void => {
        emit('synced');
        setOpen(false);
    };

    if (isBatch.value || (props.accounts && props.accounts.length > 0)) {
        router.post(
            batchSync.url(),
            {
                ids: targets.value.map((item) => item.id),
                ...payload,
            },
            { preserveScroll: true, onFinish, onSuccess },
        );

        return;
    }

    const single = targets.value[0];

    if (!single) {
        processing.value = false;

        return;
    }

    router.post(sync.url(single.id), payload, {
        preserveScroll: true,
        onFinish,
        onSuccess,
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent
            :show-close-button="false"
            class="snitch-modal-panel gap-0 overflow-hidden border-0 p-0 shadow-none sm:max-w-md"
        >
            <div class="snitch-doc relative space-y-6 p-6">
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />

                <DialogHeader class="relative z-10 space-y-3 text-left">
                    <DialogTitle class="snitch-display flex items-center gap-2 text-xl text-snitch-ink">
                        <RefreshCw class="size-5 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                        <template v-if="isBatch">
                            Sync {{ targets.length }} snitches?
                        </template>
                        <template v-else>
                            Sync this snitch?
                        </template>
                    </DialogTitle>
                    <DialogDescription class="text-sm text-snitch-ink/65">
                        <template v-if="isBatch">
                            Pull recent reels for
                            <span class="font-medium text-snitch-ink">{{ targets.length }}</span>
                            selected accounts using the options below.
                        </template>
                        <template v-else>
                            Pull recent reels for
                            <span class="font-medium text-snitch-ink">{{ displayName }}</span>
                            <template v-if="targets[0]">
                                (<span class="snitch-annotation text-base">@{{ targets[0].handle }}</span>).
                            </template>
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <div class="relative z-10 space-y-4">
                    <div class="space-y-1.5">
                        <label
                            for="sync-posts-limit"
                            class="snitch-ink-label text-[0.65rem] text-snitch-ink/55"
                        >
                            Max posts
                        </label>
                        <input
                            id="sync-posts-limit"
                            v-model.number="postsLimit"
                            type="number"
                            min="1"
                            :max="syncDefaults.posts_limit_max"
                            class="snitch-field mt-1 w-full text-sm"
                            data-test="sync-posts-limit-input"
                        />
                        <p class="text-xs text-snitch-ink/55">
                            Default {{ syncDefaults.posts_limit }}, up to {{ syncDefaults.posts_limit_max }} reel-like posts.
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <label
                            for="sync-recency-days"
                            class="snitch-ink-label text-[0.65rem] text-snitch-ink/55"
                        >
                            Lookback days
                        </label>
                        <input
                            id="sync-recency-days"
                            v-model.number="recencyDays"
                            type="number"
                            min="1"
                            :max="syncDefaults.recency_days_max"
                            class="snitch-field mt-1 w-full text-sm"
                            data-test="sync-recency-days-input"
                        />
                        <p class="text-xs text-snitch-ink/55">
                            Default {{ syncDefaults.recency_days }}, up to {{ syncDefaults.recency_days_max }} days back.
                        </p>
                    </div>

                    <p class="rounded-sm border border-snitch-ink/10 bg-snitch-paper/60 px-3 py-2 text-xs text-snitch-ink/65">
                        Sync is billable. Scraping and analysis usage is charged from your credit balance when the job runs.
                    </p>
                </div>

                <DialogFooter class="relative z-10 gap-2 sm:justify-start">
                    <DialogClose as-child>
                        <button type="button" class="snitch-btn snitch-btn-ghost">
                            <X class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                            <span class="relative z-10">Cancel</span>
                        </button>
                    </DialogClose>

                    <button
                        type="button"
                        class="snitch-btn"
                        :disabled="targets.length === 0 || processing"
                        data-test="confirm-sync-account-button"
                        @click="confirmSync"
                    >
                        <LoaderCircle
                            v-if="processing"
                            class="relative z-10 size-3.5 shrink-0 animate-spin"
                            aria-hidden="true"
                        />
                        <RefreshCw
                            v-else
                            class="relative z-10 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="relative z-10">
                            {{ processing ? 'Syncing…' : (isBatch ? `Sync ${targets.length}` : 'Sync') }}
                        </span>
                    </button>
                </DialogFooter>
            </div>
        </DialogContent>
    </Dialog>
</template>

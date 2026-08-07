<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref } from 'vue';
import {
    autofillStatus,
    startAutofill,
} from '@/actions/App/Http/Controllers/OnboardingController';
import { useToastStore } from '@/stores/toastStore';

export type BrandOwnHandles = {
    instagram: string;
    tiktok: string;
    facebook: string;
    linkedin: string;
};

export type BrandProfileFormData = {
    name?: string;
    website?: string | null;
    description?: string;
    own_handles?: Record<string, string | null>;
};

type AutofillFields = {
    name?: string | null;
    website?: string | null;
    description?: string | null;
    own_handles?: Partial<Record<keyof BrandOwnHandles, string | null>>;
};

type AutofillStatusResponse = {
    id: string;
    status: 'pending' | 'processing' | 'completed' | 'failed' | 'missing';
    fields?: AutofillFields | null;
    error?: string | null;
    website?: string | null;
};

const props = withDefaults(
    defineProps<{
        brand?: BrandProfileFormData | null;
        platforms: string[];
        submitUrl: string;
        submitMethod?: 'post' | 'put' | 'patch';
        submitLabel?: string;
        scrapClass?: string;
        showTape?: boolean;
    }>(),
    {
        brand: null,
        submitMethod: 'post',
        submitLabel: 'Save',
        scrapClass: 'snitch-scrap relative space-y-3 p-4 pt-5 sm:p-5 sm:pt-6',
        showTape: true,
    },
);

const emit = defineEmits<{
    saved: [];
}>();

const toast = useToastStore();

function stripWebsiteScheme(value: string): string {
    return value.trim().replace(/^https?:\/\//i, '');
}

function absoluteWebsite(value: string): string {
    const host = stripWebsiteScheme(value);

    return host === '' ? '' : `https://${host}`;
}

const form = useForm({
    name: props.brand?.name ?? '',
    website: stripWebsiteScheme(props.brand?.website ?? ''),
    description: props.brand?.description ?? '',
    own_handles: {
        instagram: props.brand?.own_handles?.instagram ?? '',
        tiktok: props.brand?.own_handles?.tiktok ?? '',
        facebook: props.brand?.own_handles?.facebook ?? '',
        linkedin: props.brand?.own_handles?.linkedin ?? '',
    } satisfies BrandOwnHandles,
});

const autofilling = ref(false);
const autofillMessage = ref('');
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const canAutofill = computed(
    () => stripWebsiteScheme(form.website).length > 0 && !autofilling.value && !form.processing,
);

function onWebsiteInput(event: Event): void {
    const target = event.target as HTMLInputElement;
    const next = stripWebsiteScheme(target.value);

    if (next !== form.website) {
        form.website = next;
    }
}

function clearPoll(): void {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}

onUnmounted(() => {
    clearPoll();
});

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function filled(value: string | null | undefined): value is string {
    return typeof value === 'string' && value.trim() !== '';
}

function mergeAutofillFields(fields: AutofillFields): void {
    if (filled(fields.name)) {
        form.name = fields.name;
    }

    if (filled(fields.website)) {
        form.website = stripWebsiteScheme(fields.website);
    }

    if (filled(fields.description)) {
        form.description = fields.description;
    }

    const handles = fields.own_handles;

    if (!handles) {
        return;
    }

    (Object.keys(form.own_handles) as Array<keyof BrandOwnHandles>).forEach((platform) => {
        const value = handles[platform];

        if (filled(value)) {
            form.own_handles[platform] = value;
        }
    });
}

async function pollAutofill(id: string, attempt = 0): Promise<void> {
    if (attempt > 40) {
        autofilling.value = false;
        autofillMessage.value = 'Timed out waiting for scrape.';
        toast.error('Autofill timed out. Try again in a moment.');

        return;
    }

    const response = await fetch(autofillStatus.url(id), {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok && response.status !== 404) {
        throw new Error('Unable to check autofill status.');
    }

    const payload = (await response.json()) as AutofillStatusResponse;

    if (payload.status === 'completed') {
        if (payload.fields) {
            mergeAutofillFields(payload.fields);
        }

        autofilling.value = false;
        autofillMessage.value = 'Autofill complete.';
        toast.success('Pulled what we could from your website.');

        return;
    }

    if (payload.status === 'failed' || payload.status === 'missing') {
        autofilling.value = false;
        autofillMessage.value = payload.error || 'Autofill failed.';
        toast.error(payload.error || 'Could not autofill from that website.');

        return;
    }

    autofillMessage.value = payload.status === 'processing' ? 'Scraping…' : 'Queued…';
    pollTimer = setTimeout(() => {
        void pollAutofill(id, attempt + 1);
    }, 1500);
}

async function requestAutofill(): Promise<void> {
    if (!canAutofill.value) {
        return;
    }

    clearPoll();
    autofilling.value = true;
    autofillMessage.value = 'Scraping…';

    const website = absoluteWebsite(form.website);

    try {
        const response = await fetch(startAutofill.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ website }),
        });

        if (!response.ok) {
            const errorBody = (await response.json().catch(() => null)) as
                | { message?: string; errors?: Record<string, string[]> }
                | null;
            const message =
                errorBody?.errors?.website?.[0] ||
                errorBody?.message ||
                'Could not start website autofill.';

            throw new Error(message);
        }

        const payload = (await response.json()) as { id: string };
        await pollAutofill(payload.id);
    } catch (error) {
        autofilling.value = false;
        autofillMessage.value = error instanceof Error ? error.message : 'Autofill failed.';
        toast.error(autofillMessage.value);
    }
}

function submitBrand(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    };

    const payload = form.transform((data) => ({
        ...data,
        website: absoluteWebsite(data.website),
    }));

    if (props.submitMethod === 'put') {
        payload.put(props.submitUrl, options);

        return;
    }

    if (props.submitMethod === 'patch') {
        payload.patch(props.submitUrl, options);

        return;
    }

    payload.post(props.submitUrl, options);
}
</script>

<template>
    <form
        :class="scrapClass"
        @submit.prevent="submitBrand"
    >
        <span
            v-if="showTape"
            class="snitch-tape left-5 -top-2"
            aria-hidden="true"
        />

        <div>
            <label for="brand-website" class="text-sm font-medium text-snitch-ink">
                Website
            </label>
            <div class="mt-0.5 flex flex-col gap-2 sm:flex-row sm:items-center">
                <div class="snitch-field-group min-w-0 flex-1">
                    <span class="snitch-field-prefix" aria-hidden="true">https://</span>
                    <input
                        id="brand-website"
                        :value="form.website"
                        type="text"
                        inputmode="url"
                        autocomplete="url"
                        class="snitch-field-bare"
                        placeholder="www.yourbrand.com"
                        :disabled="autofilling"
                        @input="onWebsiteInput"
                    />
                </div>
                <button
                    type="button"
                    class="snitch-btn shrink-0 whitespace-nowrap px-3 py-2 text-sm"
                    :disabled="!canAutofill"
                    @click="requestAutofill"
                >
                    {{ autofilling ? 'Scraping…' : 'Autofill from website' }}
                </button>
            </div>
            <p
                v-if="autofillMessage"
                class="mt-1.5 text-sm text-snitch-ink/60"
                aria-live="polite"
            >
                {{ autofillMessage }}
            </p>
        </div>

        <label class="block">
            <span class="text-sm font-medium text-snitch-ink">Brand name</span>
            <input v-model="form.name" class="snitch-field mt-0.5" required />
        </label>

        <label class="block">
            <span class="text-sm font-medium text-snitch-ink">Short description</span>
            <textarea
                v-model="form.description"
                class="snitch-field mt-0.5 min-h-20"
                required
            />
        </label>

        <div class="grid gap-2 sm:grid-cols-2 sm:gap-x-3 sm:gap-y-2">
            <label
                v-for="platform in platforms"
                :key="platform"
                class="block"
            >
                <span class="text-xs uppercase tracking-wide text-snitch-ink/55">
                    Your {{ platform }}
                </span>
                <input
                    v-model="form.own_handles[platform as keyof typeof form.own_handles]"
                    class="snitch-field mt-0.5"
                    :placeholder="`@${platform}`"
                />
            </label>
        </div>

        <div class="flex flex-wrap gap-3 pt-0.5">
            <button
                type="submit"
                class="snitch-btn snitch-btn-spot"
                :disabled="form.processing || autofilling"
                data-test="update-brand-button"
            >
                <span class="relative z-10">
                    {{ form.processing ? 'Saving…' : submitLabel }}
                </span>
            </button>
        </div>
    </form>
</template>

<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Trash2, TriangleAlert, X } from '@lucide/vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
</script>

<template>
    <div class="space-y-4">
        <div>
            <h2 class="snitch-display text-xl text-snitch-ink">
                Delete account
            </h2>
            <p class="mt-1.5 text-sm text-snitch-ink/65">
                Delete your account and all of its resources.
            </p>
        </div>

        <div class="snitch-scrap relative space-y-3 p-4 pt-5">
            <span class="snitch-tape right-5 -top-2" aria-hidden="true" />
            <p class="relative z-10 flex items-center gap-2 text-sm font-medium text-snitch-ink">
                <TriangleAlert class="size-4 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                Warning
            </p>
            <p class="relative z-10 text-sm text-snitch-ink/70">
                Please proceed with caution - this cannot be undone.
            </p>

            <Dialog>
                <DialogTrigger as-child>
                    <button
                        type="button"
                        class="snitch-btn relative z-10"
                        data-test="delete-user-button"
                    >
                        <Trash2 class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                        <span class="relative z-10">Delete account</span>
                    </button>
                </DialogTrigger>
                <DialogContent
                    :show-close-button="false"
                    class="snitch-modal-panel gap-0 overflow-hidden border-0 p-0 shadow-none sm:max-w-md"
                >
                    <Form
                        :action="ProfileController.destroy.url()"
                        method="delete"
                        reset-on-success
                        :options="{
                            preserveScroll: true,
                        }"
                        class="snitch-doc relative space-y-6 p-6"
                        v-slot="{ processing, reset, clearErrors }"
                    >
                        <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                        <DialogHeader class="relative z-10 space-y-3 text-left">
                            <DialogTitle class="snitch-display flex items-center gap-2 text-xl text-snitch-ink">
                                <Trash2 class="size-5 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                                Delete your account?
                            </DialogTitle>
                            <DialogDescription class="text-sm text-snitch-ink/65">
                                Once your account is deleted, all of its resources
                                and data will also be permanently deleted. Please
                                confirm you would like to permanently delete your
                                account.
                            </DialogDescription>
                        </DialogHeader>

                        <DialogFooter class="relative z-10 gap-2 sm:justify-start">
                            <DialogClose as-child>
                                <button
                                    type="button"
                                    class="snitch-btn snitch-btn-ghost"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    <X class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                                    <span class="relative z-10">Cancel</span>
                                </button>
                            </DialogClose>

                            <button
                                type="submit"
                                class="snitch-btn"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                <Trash2 class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                                <span class="relative z-10">Delete account</span>
                            </button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>

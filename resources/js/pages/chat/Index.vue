<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Bot, MessageSquare, Send } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout,
});

const props = defineProps<{
    daily_cap: number;
    remaining: number;
    capped: boolean;
    mcp_path: string;
    mcp_url: string;
    tools: string[];
}>();

type ChatMessage = {
    role: 'user' | 'assistant';
    content: string;
};

const messages = ref<ChatMessage[]>([]);
const draft = ref('');
const sending = ref(false);
const remaining = ref(props.remaining);
const capped = ref(props.capped);
const streamError = ref<string | null>(null);
const transcript = ref<HTMLElement | null>(null);

const canSend = computed(() => !sending.value && draft.value.trim() !== '' && !capped.value);

async function scrollToBottom(): Promise<void> {
    await nextTick();
    const el = transcript.value;

    if (el) {
        el.scrollTop = el.scrollHeight;
    }
}

async function send(): Promise<void> {
    const text = draft.value.trim();

    if (!text || sending.value) {
        return;
    }

    streamError.value = null;
    messages.value.push({ role: 'user', content: text });
    draft.value = '';
    sending.value = true;
    await scrollToBottom();

    const assistantIndex = messages.value.push({ role: 'assistant', content: '' }) - 1;
    const history = messages.value
        .slice(0, -1)
        .filter((row) => row.content.trim() !== '')
        .slice(-16)
        .map((row) => ({ role: row.role, content: row.content }));

    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        const response = await fetch('/chat/stream', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                message: text,
                history,
            }),
        });

        if (!response.ok || !response.body) {
            throw new Error('Chat request failed.');
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
            const { done, value } = await reader.read();

            if (done) {
                break;
            }

            buffer += decoder.decode(value, { stream: true });
            const chunks = buffer.split('\n\n');
            buffer = chunks.pop() ?? '';

            for (const chunk of chunks) {
                const line = chunk
                    .split('\n')
                    .map((part) => part.trim())
                    .find((part) => part.startsWith('data:'));

                if (!line) {
                    continue;
                }

                const raw = line.slice(5).trim();

                if (raw === '' || raw === '[DONE]') {
                    continue;
                }

                let payload: {
                    type?: string;
                    text?: string;
                    message?: string;
                    remaining?: number;
                    mcp_path?: string;
                };

                try {
                    payload = JSON.parse(raw) as typeof payload;
                } catch {
                    continue;
                }

                if (payload.type === 'delta' && typeof payload.text === 'string') {
                    messages.value[assistantIndex].content += payload.text;
                    await scrollToBottom();
                }

                if (payload.type === 'capped') {
                    capped.value = true;
                    remaining.value = 0;
                    messages.value[assistantIndex].content =
                        payload.message ??
                        "You have hit today's in-app chat limit. Connect your AI client to keep asking with MCP.";
                    await scrollToBottom();
                }

                if (payload.type === 'error') {
                    streamError.value = payload.message ?? 'Chat failed.';

                    if (messages.value[assistantIndex].content === '') {
                        messages.value[assistantIndex].content = streamError.value;
                    }
                }

                if (payload.type === 'done' && typeof payload.remaining === 'number') {
                    remaining.value = payload.remaining;

                    if (remaining.value <= 0) {
                        capped.value = true;
                    }
                }
            }
        }

        if (messages.value[assistantIndex].content.trim() === '') {
            messages.value[assistantIndex].content = 'No reply came back. Try again.';
        }
    } catch (error) {
        streamError.value = error instanceof Error ? error.message : 'Chat failed.';

        if (messages.value[assistantIndex].content === '') {
            messages.value[assistantIndex].content = streamError.value;
        }
    } finally {
        sending.value = false;
        await scrollToBottom();
    }
}
</script>

<template>
    <div>
        <Head title="Chat" />

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <header class="space-y-1">
                <p class="font-mono text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                    Chat
                </p>
                <h1 class="snitch-hero-display text-3xl text-snitch-ink sm:text-4xl">
                    Ask Snitch
                </h1>
                <p class="text-sm text-snitch-ink/70 sm:text-base">
                    Stored data only - the same read tools as MCP. Remaining today:
                    {{ remaining }} of {{ daily_cap }}.
                </p>
            </header>

            <div
                v-if="capped"
                class="rounded border border-snitch-ink/15 bg-snitch-lift p-4 text-sm text-snitch-ink"
            >
                <p>
                    You have hit today's in-app chat limit. Connect your AI client to keep asking with MCP.
                </p>
                <Link
                    :href="mcp_path"
                    class="snitch-btn snitch-btn-spot mt-3 inline-flex items-center gap-2 px-3 py-2 text-sm"
                >
                    <Bot
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    Connect your AI
                </Link>
            </div>

            <section class="flex min-h-[28rem] flex-1 flex-col rounded border border-snitch-ink/10 bg-snitch-lift">
                <div
                    ref="transcript"
                    class="flex-1 space-y-4 overflow-y-auto p-4 text-sm leading-relaxed"
                >
                    <div
                        v-if="messages.length === 0"
                        class="space-y-2 text-snitch-ink/70"
                    >
                        <p class="flex items-center gap-2 font-medium text-snitch-ink">
                            <MessageSquare
                                class="size-4"
                                aria-hidden="true"
                            />
                            Try asking
                        </p>
                        <ul class="list-disc space-y-1 pl-5">
                            <li>Which competitors are quiet right now?</li>
                            <li>What are our top posts this month?</li>
                            <li>Summarise today's brief.</li>
                        </ul>
                        <p class="pt-2 text-snitch-ink/55">
                            Tools: {{ tools.join(', ') }}
                        </p>
                    </div>

                    <div
                        v-for="(row, index) in messages"
                        :key="index"
                        class="space-y-1"
                    >
                        <p class="font-mono text-sm uppercase tracking-wide text-snitch-ink/55">
                            {{ row.role === 'user' ? 'You' : 'Snitch' }}
                        </p>
                        <p class="whitespace-pre-wrap text-sm text-snitch-ink sm:text-base">
                            {{ row.content || (sending && index === messages.length - 1 ? '…' : '') }}
                        </p>
                    </div>
                </div>

                <form
                    class="flex flex-col gap-3 border-t border-snitch-ink/10 p-4 sm:flex-row"
                    @submit.prevent="send"
                >
                    <label class="sr-only" for="chat-message">Message</label>
                    <textarea
                        id="chat-message"
                        v-model="draft"
                        rows="2"
                        class="min-h-[3rem] flex-1 resize-y rounded border border-snitch-ink/20 bg-snitch-paper px-3 py-2 text-sm text-snitch-ink outline-none focus:border-snitch-spot sm:text-base"
                        placeholder="Ask about trackers, posts, growth, or today's brief"
                        :disabled="sending || capped"
                        @keydown.enter.exact.prevent="send"
                    />
                    <button
                        type="submit"
                        class="snitch-btn snitch-btn-spot inline-flex items-center justify-center gap-2 px-4 py-2 text-sm disabled:opacity-50"
                        :disabled="!canSend"
                    >
                        <Send
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        Send
                    </button>
                </form>
            </section>

            <p
                v-if="streamError"
                class="text-sm text-snitch-ink/70"
            >
                {{ streamError }}
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Bot, Send } from '@lucide/vue';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import ChatMarkdown from '@/components/chat/ChatMarkdown.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout,
});

type ChatPost = {
    url: string;
    handle?: string | null;
    caption?: string | null;
    thumbnail_url?: string | null;
    likes?: number | null;
    comments?: number | null;
    times_usual_label?: string | null;
};

type ChatMessage = {
    role: 'user' | 'assistant';
    content: string;
    posts?: ChatPost[];
};

const props = defineProps<{
    daily_cap: number;
    remaining: number;
    capped: boolean;
    mcp_path: string;
    mcp_url: string;
    suggestions: string[];
}>();

const messages = ref<ChatMessage[]>([]);
const draft = ref('');
const sending = ref(false);
const statusMessage = ref<string | null>(null);
const remaining = ref(props.remaining);
const capped = ref(props.capped);
const streamError = ref<string | null>(null);
const transcript = ref<HTMLElement | null>(null);
const stickToBottom = ref(true);

const canSend = computed(() => !sending.value && draft.value.trim() !== '' && !capped.value);
const empty = computed(() => messages.value.length === 0);

function onScroll(): void {
    const el = transcript.value;

    if (!el) {
        return;
    }

    const distance = el.scrollHeight - el.scrollTop - el.clientHeight;
    stickToBottom.value = distance < 80;
}

async function scrollToBottomIfNeeded(): Promise<void> {
    if (!stickToBottom.value) {
        return;
    }

    await nextTick();
    const el = transcript.value;

    if (el) {
        el.scrollTop = el.scrollHeight;
    }
}

async function send(textOverride?: string): Promise<void> {
    const text = (textOverride ?? draft.value).trim();

    if (!text || sending.value || capped.value) {
        return;
    }

    streamError.value = null;
    statusMessage.value = null;
    messages.value.push({ role: 'user', content: text });
    draft.value = '';
    sending.value = true;
    stickToBottom.value = true;
    await scrollToBottomIfNeeded();

    const assistantIndex = messages.value.push({ role: 'assistant', content: '', posts: [] }) - 1;
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
                    message?: string | null;
                    remaining?: number;
                    posts?: ChatPost[];
                };

                try {
                    payload = JSON.parse(raw) as typeof payload;
                } catch {
                    continue;
                }

                if (payload.type === 'status') {
                    statusMessage.value = payload.message ?? null;
                }

                if (payload.type === 'posts' && Array.isArray(payload.posts)) {
                    const existing = messages.value[assistantIndex].posts ?? [];
                    const merged = new Map(existing.map((post) => [post.url, post]));

                    for (const post of payload.posts) {
                        merged.set(post.url, post);
                    }

                    messages.value[assistantIndex].posts = [...merged.values()];
                }

                if (payload.type === 'delta' && typeof payload.text === 'string') {
                    messages.value[assistantIndex].content += payload.text;
                    await scrollToBottomIfNeeded();
                }

                if (payload.type === 'capped') {
                    capped.value = true;
                    remaining.value = 0;
                    statusMessage.value = null;
                    messages.value[assistantIndex].content =
                        payload.message ??
                        "You have hit today's in-app chat limit. Connect your AI to keep asking with MCP.";
                    await scrollToBottomIfNeeded();
                }

                if (payload.type === 'error') {
                    streamError.value = payload.message ?? 'Chat failed.';
                    statusMessage.value = null;

                    if (messages.value[assistantIndex].content === '') {
                        messages.value[assistantIndex].content = streamError.value;
                    }
                }

                if (payload.type === 'done' && typeof payload.remaining === 'number') {
                    remaining.value = payload.remaining;
                    statusMessage.value = null;

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
        statusMessage.value = null;

        if (messages.value[assistantIndex].content === '') {
            messages.value[assistantIndex].content = streamError.value;
        }
    } finally {
        sending.value = false;
        statusMessage.value = null;
        await scrollToBottomIfNeeded();
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void send();
    }
}

onMounted(() => {
    transcript.value?.addEventListener('scroll', onScroll, { passive: true });
});

onUnmounted(() => {
    transcript.value?.removeEventListener('scroll', onScroll);
});
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col bg-[#0E0E10]">
        <Head title="Chat" />

        <div class="mx-auto flex min-h-0 w-full max-w-[760px] flex-1 flex-col px-4 pb-4 pt-4 sm:px-6 sm:pb-6 sm:pt-6">
            <div
                ref="transcript"
                class="min-h-0 flex-1 overflow-y-auto"
            >
                <div
                    v-if="empty"
                    class="flex min-h-full flex-col justify-center gap-8 py-10"
                >
                    <div class="space-y-3 text-center">
                        <h1 class="font-hero text-3xl uppercase leading-tight tracking-tight text-[#EDEAE2] sm:text-4xl">
                            Ask Snitch
                        </h1>
                        <p class="text-[15px] leading-relaxed text-[#EDEAE2]/70">
                            Answers from your stored trackers, posts, and briefs.
                        </p>
                    </div>

                    <div class="flex flex-wrap justify-center gap-2">
                        <button
                            v-for="chip in suggestions"
                            :key="chip"
                            type="button"
                            class="rounded-full border border-white/10 bg-[#141416] px-3.5 py-2 text-left text-[15px] leading-snug text-[#EDEAE2]/75 transition hover:border-white/25 hover:text-[#EDEAE2]"
                            :disabled="sending || capped"
                            @click="send(chip)"
                        >
                            {{ chip }}
                        </button>
                    </div>
                </div>

                <div
                    v-else
                    class="space-y-5 py-2"
                >
                    <div
                        v-for="(row, index) in messages"
                        :key="index"
                        class="w-full"
                    >
                        <div
                            v-if="row.role === 'user'"
                            class="flex justify-end"
                        >
                            <div class="max-w-[85%] rounded-2xl bg-[#1E1E22] px-4 py-2.5 text-[15px] leading-relaxed text-[#EDEAE2]">
                                {{ row.content }}
                            </div>
                        </div>
                        <div
                            v-else
                            class="w-full"
                        >
                            <ChatMarkdown
                                v-if="row.content"
                                :source="row.content"
                                :posts="row.posts"
                            />
                            <p
                                v-else-if="sending && index === messages.length - 1"
                                class="text-[15px] text-[#EDEAE2]/55"
                            >
                                {{ statusMessage || 'Thinking...' }}
                            </p>
                        </div>
                    </div>

                    <p
                        v-if="sending && statusMessage && messages[messages.length - 1]?.content"
                        class="text-[15px] text-[#EDEAE2]/55"
                    >
                        {{ statusMessage }}
                    </p>
                </div>
            </div>

            <div
                v-if="capped"
                class="mt-3 rounded-xl border border-white/10 bg-[#141416] p-4 text-[15px] leading-relaxed text-[#EDEAE2]"
            >
                <p>
                    You have hit today's in-app chat limit. Connect your AI to keep asking with MCP.
                </p>
                <Link
                    :href="mcp_path"
                    class="mt-3 inline-flex items-center gap-2 rounded-md bg-[#FCD700] px-3 py-2 text-sm font-medium uppercase tracking-wide text-[#0E0E10]"
                >
                    <Bot
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    Connect your AI
                </Link>
            </div>

            <form
                class="mt-3 flex items-end gap-2 rounded-2xl border border-white/10 bg-[#141416] p-2"
                @submit.prevent="send()"
            >
                <label
                    class="sr-only"
                    for="chat-message"
                >Message</label>
                <textarea
                    id="chat-message"
                    v-model="draft"
                    rows="1"
                    class="max-h-40 min-h-[44px] flex-1 resize-none bg-transparent px-3 py-2.5 text-[15px] leading-relaxed text-[#EDEAE2] outline-none placeholder:text-[#EDEAE2]/45"
                    placeholder="Ask about growth, rivals, or today's brief"
                    :disabled="sending || capped"
                    @keydown="onKeydown"
                />
                <button
                    type="submit"
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#FCD700] text-[#0E0E10] transition disabled:opacity-40"
                    :disabled="!canSend"
                    aria-label="Send"
                >
                    <Send
                        class="size-4"
                        aria-hidden="true"
                    />
                </button>
            </form>

            <p class="mt-2 text-center font-mono text-sm text-[#EDEAE2]/45">
                Remaining today {{ remaining }} of {{ daily_cap }}
            </p>

            <p
                v-if="streamError"
                class="mt-1 text-center text-[15px] text-[#FF3D8B]"
            >
                {{ streamError }}
            </p>
        </div>
    </div>
</template>

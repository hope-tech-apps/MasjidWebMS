<template>
    <div>
        <PageDataContainer title="Masjid Assistant"
            :buttonProps="{ title: 'New chat', type: 'button', class: 'btn btn-outline-secondary', disabled: busy }"
            @headerButtonClick="resetChat">

            <div class="assistant-shell">

                <!-- Transcript -->
                <div ref="transcriptEl" class="assistant-transcript">

                    <!-- Empty state -->
                    <div v-if="!messages.length" class="assistant-empty">
                        <div class="assistant-empty-title">
                            Ask me to help with {{ masjidStore.masjid?.name ?? 'your masjid' }}
                        </div>
                        <p class="assistant-empty-note">
                            I can only do things you already have access to, and only for this masjid.
                            Attach a flyer and I'll read the details off it.
                        </p>
                        <div class="assistant-suggestions">
                            <button v-for="s in SUGGESTIONS" :key="s" type="button"
                                class="assistant-suggestion" @click="draft = s">
                                {{ s }}
                            </button>
                        </div>
                    </div>

                    <div v-for="(m, i) in messages" :key="i" class="assistant-turn"
                        :class="m.role === 'user' ? 'is-user' : 'is-assistant'">

                        <div class="assistant-bubble">
                            <img v-if="m.image" :src="m.image" alt="attached image" class="assistant-attachment" />
                            <div v-if="m.text" class="assistant-text">{{ m.text }}</div>
                        </div>

                        <!-- What it actually did. Rendered from the server's action log, not
                             from the reply text, so the admin can verify the claims. -->
                        <div v-if="m.actions?.length" class="assistant-actions">
                            <div class="assistant-actions-title">What I did</div>
                            <div v-for="(a, j) in m.actions" :key="j" class="assistant-action"
                                :class="{ 'is-failed': !a.ok }">
                                <span class="assistant-action-mark">{{ a.ok ? '✓' : '✕' }}</span>
                                <span class="assistant-action-body">
                                    <strong>{{ actionLabel(a) }}</strong>
                                    <span v-if="actionDetail(a)" class="assistant-action-detail">
                                        — {{ actionDetail(a) }}
                                    </span>
                                    <span v-if="!a.ok && a.error" class="assistant-action-error">
                                        {{ a.error }}
                                    </span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div v-if="busy" class="assistant-turn is-assistant">
                        <div class="assistant-bubble assistant-working">
                            <span class="spinner-border spinner-border-sm" role="status"></span>
                            <span>Working on it…</span>
                        </div>
                    </div>
                </div>

                <!-- Composer -->
                <div class="assistant-composer">
                    <div v-if="imagePreview" class="assistant-image-chip">
                        <img :src="imagePreview" alt="attachment preview" />
                        <span class="assistant-image-name">{{ imageFile?.name }}</span>
                        <button type="button" class="btn-close btn-close-sm" aria-label="Remove attachment"
                            @click="clearImage"></button>
                    </div>

                    <div class="assistant-input-row">
                        <button type="button" class="btn btn-light assistant-attach-btn" :disabled="busy"
                            title="Attach an image" @click="fileInput?.click()">
                            <i class="bi bi-paperclip"></i>
                        </button>
                        <input ref="fileInput" type="file" class="d-none"
                            accept="image/png,image/jpeg,image/webp" @change="onImagePicked" />

                        <textarea v-model="draft" class="form-control assistant-textarea" rows="2"
                            :disabled="busy" placeholder="Ask the assistant to do something…"
                            @keydown.enter.exact.prevent="send"></textarea>

                        <button type="button" class="btn btn-success assistant-send-btn"
                            :disabled="busy || !draft.trim()" @click="send">
                            Send
                        </button>
                    </div>
                    <div class="assistant-hint">
                        Enter to send · Shift+Enter for a new line · PNG, JPEG or WebP up to 8&nbsp;MB
                    </div>
                </div>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import PageDataContainer from '@/components/PageDataContainer.vue';
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import { MSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError, AxiosResponse } from 'axios';
import { nextTick, ref } from 'vue';

// One entry in the server's action log — the assistant's receipts.
type AssistantAction = {
    tool: string;
    input: Record<string, any>;
    ok: boolean;
    error: string | null;
};

type ChatMessage = {
    role: 'user' | 'assistant';
    text: string;
    image?: string;               // object URL, for display only
    actions?: AssistantAction[];
};

const SUGGESTIONS = [
    'What announcements do we have running right now?',
    "Add an announcement about this Friday's khutbah",
    'Change our app colors to a deeper green',
];

// A tool name is an implementation detail; admins need plain language.
const ACTION_LABELS: Record<string, string> = {
    list_announcements: 'Read your announcements',
    create_announcement: 'Created an announcement',
    list_events: 'Read your events',
    create_event: 'Created an event',
    update_theme: 'Updated your theme colors',
    request_feature: 'Sent a request to Hope Tech Inc',
};

// Stores
const authStore = useAuthStore();
const masjidStore = useMasjidStore();

// State
const messages = ref<ChatMessage[]>([]);
const draft = ref('');
const busy = ref(false);
const imageFile = ref<File | null>(null);
const imagePreview = ref<string | null>(null);

// Html refs
const fileInput = ref<HTMLInputElement | null>(null);
const transcriptEl = ref<HTMLElement | null>(null);

const actionLabel = (a: AssistantAction): string =>
    ACTION_LABELS[a.tool] ?? a.tool.replace(/_/g, ' ');

/** The one field that tells the admin *which* thing was touched. */
const actionDetail = (a: AssistantAction): string => {
    const i = a.input ?? {};
    if (a.tool === 'update_theme') {
        return Object.entries(i)
            .filter(([, v]) => !!v)
            .map(([k, v]) => `${k.replace('_color', '')} ${v}`)
            .join(', ');
    }
    return (i.title as string) || (i.summary as string) || '';
};

const clearImage = () => {
    if (imagePreview.value) URL.revokeObjectURL(imagePreview.value);
    imageFile.value = null;
    imagePreview.value = null;
    if (fileInput.value) fileInput.value.value = '';
};

const onImagePicked = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;

    // Mirror the server's cap (8192 KB) so an oversized file fails here, with a
    // clear message, instead of coming back as a 422.
    if (file.size > 8 * 1024 * 1024) {
        MSwal.fire('Too large', 'Please attach an image under 8 MB.', 'warning');
        if (fileInput.value) fileInput.value.value = '';
        return;
    }

    clearImage();
    imageFile.value = file;
    imagePreview.value = URL.createObjectURL(file);
};

const resetChat = () => {
    messages.value.forEach(m => { if (m.image) URL.revokeObjectURL(m.image); });
    messages.value = [];
    draft.value = '';
    clearImage();
};

const scrollToEnd = async () => {
    await nextTick();
    if (transcriptEl.value) transcriptEl.value.scrollTop = transcriptEl.value.scrollHeight;
};

const send = async () => {
    const text = draft.value.trim();
    if (!text || busy.value) return;

    const masjidId = authStore.dashboardMasjidId ?? masjidStore.masjid?.id;
    if (!masjidId) {
        MSwal.fire('Sorry', 'No masjid is selected.', 'error');
        return;
    }

    // Replay prior turns so the conversation has memory. Text only — re-uploading
    // images every turn would multiply the bill for no benefit, and the server caps
    // history at 20 entries anyway.
    const history = messages.value
        .filter(m => !!m.text)
        .slice(-20)
        .map(m => ({ role: m.role, content: m.text }));

    const payload = new FormData();
    payload.append('message', text);
    if (imageFile.value) payload.append('image', imageFile.value);
    history.forEach((h, i) => {
        payload.append(`history[${i}][role]`, h.role);
        payload.append(`history[${i}][content]`, h.content);
    });

    messages.value.push({
        role: 'user',
        text,
        image: imagePreview.value ?? undefined,
    });

    // The preview URL now belongs to the transcript entry, so drop our handle on it
    // without revoking — clearImage() would kill the image still on screen.
    imageFile.value = null;
    imagePreview.value = null;
    if (fileInput.value) fileInput.value.value = '';

    draft.value = '';
    busy.value = true;
    scrollToEnd();

    await ApiService.post(`/api/admin/masjids/${masjidId}/assistant/chat`, payload)
        .then((res: AxiosResponse) => {
            if (res.data?.status === 'success' && res.data?.data) {
                messages.value.push({
                    role: 'assistant',
                    text: res.data.data.reply ?? '',
                    actions: res.data.data.actions ?? [],
                });
            } else {
                MSwal.fire('Sorry', getMessageFromObj(res), 'warning');
            }
        })
        .catch((e: AxiosError<BackendResponseData>) => {
            console.log(e);
            MSwal.fire('Sorry', getMessageFromObj(e), 'error');
        })
        .finally(() => {
            busy.value = false;
            scrollToEnd();
        });
};
</script>

<style scoped>
.assistant-shell {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    height: min(65vh, 40rem);
}

.assistant-transcript {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: .5rem;
}

/* Empty state */
.assistant-empty {
    margin: auto;
    max-width: 34rem;
    text-align: center;
    color: #6b6c6f;
}

.assistant-empty-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #212529;
}

.assistant-empty-note {
    margin: .5rem 0 1.25rem;
    font-size: .9rem;
}

.assistant-suggestions {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    justify-content: center;
}

.assistant-suggestion {
    background: #fff;
    border: 1px solid var(--input-border, #dee2e6);
    border-radius: 2rem;
    padding: .4rem .9rem;
    font-size: .85rem;
    color: #495057;
}

.assistant-suggestion:hover {
    border-color: var(--cgreen, #1b7f4c);
    color: var(--cgreen, #1b7f4c);
}

/* Turns */
.assistant-turn {
    display: flex;
    flex-direction: column;
    gap: .4rem;
    max-width: min(46rem, 85%);
}

.assistant-turn.is-user {
    align-self: flex-end;
    align-items: flex-end;
}

.assistant-turn.is-assistant {
    align-self: flex-start;
}

.assistant-bubble {
    padding: .7rem 1rem;
    border-radius: 1rem;
    background: #f1f3f5;
    color: #212529;
}

.is-user .assistant-bubble {
    background: var(--cgreen, #1b7f4c);
    color: #fff;
}

.assistant-text {
    white-space: pre-wrap;
    word-break: break-word;
    line-height: 1.5;
}

.assistant-attachment {
    display: block;
    max-width: 14rem;
    max-height: 14rem;
    object-fit: contain;
    border-radius: .5rem;
    margin-bottom: .5rem;
}

.assistant-working {
    display: flex;
    align-items: center;
    gap: .6rem;
    color: #6b6c6f;
}

/* Action trail */
.assistant-actions {
    border: 1px solid var(--input-border, #dee2e6);
    border-radius: .75rem;
    padding: .6rem .85rem;
    background: #fff;
    font-size: .85rem;
    width: 100%;
}

.assistant-actions-title {
    font-weight: 600;
    color: #6b6c6f;
    margin-bottom: .35rem;
    text-transform: uppercase;
    font-size: .7rem;
    letter-spacing: .04em;
}

.assistant-action {
    display: flex;
    gap: .5rem;
    align-items: baseline;
    padding: .15rem 0;
}

.assistant-action-mark {
    color: var(--cgreen, #1b7f4c);
    font-weight: 700;
}

.assistant-action.is-failed .assistant-action-mark {
    color: #dc3545;
}

.assistant-action-detail {
    color: #6b6c6f;
}

.assistant-action-error {
    display: block;
    color: #dc3545;
    /* Belt-and-braces: messages are sanitized server-side, but nothing unexpected
       should ever push the panel sideways. */
    overflow-wrap: anywhere;
}

/* Composer */
.assistant-composer {
    border-top: 1px solid var(--input-border, #dee2e6);
    padding-top: .75rem;
    flex-shrink: 0;
}

.assistant-image-chip {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    border: 1px solid var(--input-border, #dee2e6);
    border-radius: .5rem;
    padding: .35rem .5rem;
    margin-bottom: .5rem;
    max-width: 100%;
}

.assistant-image-chip img {
    width: 2.25rem;
    height: 2.25rem;
    object-fit: cover;
    border-radius: .35rem;
}

.assistant-image-name {
    font-size: .8rem;
    color: #6b6c6f;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 14rem;
}

.assistant-input-row {
    display: flex;
    align-items: flex-end;
    gap: .5rem;
}

.assistant-textarea {
    resize: none;
    min-height: 2.6rem;
    max-height: 9rem;
    overflow-y: auto;
}

.assistant-attach-btn,
.assistant-send-btn {
    flex-shrink: 0;
    height: 2.6rem;
}

.assistant-hint {
    margin-top: .4rem;
    font-size: .75rem;
    color: #6b6c6f;
}
</style>

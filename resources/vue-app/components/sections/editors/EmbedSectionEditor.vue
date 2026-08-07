<template>
    <div class="embed-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">What are you embedding? <span class="text-danger">*</span></label>
                <select class="form-select" v-model="localContent.provider" @change="onProviderChange">
                    <option :value="null" disabled>Choose a provider…</option>
                    <option v-for="p in PROVIDERS" :key="p.key" :value="p.key">{{ p.label }}</option>
                </select>
                <div class="form-text">{{ providerHelp }}</div>
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Address (URL) <span class="text-danger">*</span></label>
                <input
                    type="url"
                    class="form-control"
                    v-model="localContent.url"
                    @input="emitUpdate"
                    placeholder="https://…"
                    required
                />
                <!--
                  The hosts are shown BEFORE saving, not only in the rejection message.
                  The commonest mistakes here are a http:// URL pasted from an old page
                  and a URL that belongs to a different provider than the one selected,
                  and neither is visible to an admin without the list in front of them.
                -->
                <div class="form-text">
                    <template v-if="selectedProvider">
                        Must be an <code>https://</code> address on:
                        <strong>{{ selectedProvider.hosts }}</strong>.
                    </template>
                    <template v-else>
                        Pick a provider first — each one accepts addresses from its own site only.
                    </template>
                </div>
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.title"
                    @input="emitUpdate"
                    placeholder="e.g. Events Calendar"
                />
                <div class="form-text">
                    Shown above the widget. Also read aloud by screen readers to announce
                    what the frame contains, so it is worth filling in.
                </div>
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Intro text</label>
                <textarea
                    class="form-control"
                    rows="2"
                    v-model="localContent.caption"
                    @input="emitUpdate"
                    placeholder="Optional line shown between the heading and the widget"
                ></textarea>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Shape</label>
                <select class="form-select" v-model="localContent.aspect" @change="emitUpdate">
                    <option :value="null">Use a fixed height</option>
                    <option value="16:9">Widescreen (16:9) — video</option>
                    <option value="4:3">Landscape (4:3) — maps</option>
                    <option value="1:1">Square (1:1)</option>
                </select>
                <div class="form-text">A shape scales to any screen; a height does not.</div>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Height (pixels)</label>
                <input
                    type="number"
                    class="form-control"
                    min="240"
                    step="20"
                    v-model.number="localContent.height"
                    @input="emitUpdate"
                    :disabled="!!localContent.aspect"
                    placeholder="720"
                />
                <div class="form-text">
                    Used only when no shape is set. Capped at 85% of the visitor's screen so
                    the rest of the page stays reachable on a phone.
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Background Color</label>
                <input
                    type="color"
                    class="form-control form-control-color flex-shrink-0"
                    v-model="localContent.background_color"
                    @input="emitUpdate"
                />
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Link text</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.fallback_text"
                    @input="emitUpdate"
                    placeholder="Open in a new tab"
                />
                <div class="form-text">
                    A link to the same address always appears under the widget. Some sites
                    refuse to be shown inside another page — when that happens the frame is
                    simply blank, and this link is how visitors still get there.
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * Editor for an `embed` section.
 *
 * The provider list below MIRRORS App\Support\EmbedProviders; that class is
 * authoritative and validates every save, so the worst a drift here can do is offer a
 * choice the server rejects with a message naming the hosts it wanted. It cannot let a
 * bad URL through — the check that matters does not run in this file.
 *
 * `site_page` is offered unconditionally even though the server only accepts it once the
 * masjid has a website on file; the rejection message says exactly that.
 */
import { EmbedSectionContent, EmbedProvider } from '@/core/types/data/masjid-related/PageSection';
import { ref, watch, computed } from 'vue';

const props = defineProps<{
    modelValue: EmbedSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: EmbedSectionContent];
}>();

const PROVIDERS: { key: EmbedProvider; label: string; hosts: string; help: string }[] = [
    {
        key: 'site_page',
        label: 'A page from our own website',
        hosts: 'your masjid’s website address',
        help: 'Best for a calendar or page that still lives on your old website. Set the website address in the masjid’s settings first.',
    },
    {
        key: 'wix_widget',
        label: 'Wix widget (events calendar)',
        hosts: 'wixapps.net, wixsite.com, filesusr.com, parastorage.com, wix.com',
        help: 'A widget served directly by Wix. If your calendar is a page on your Wix site, choose “A page from our own website” instead.',
    },
    {
        key: 'youtube',
        label: 'YouTube video',
        hosts: 'youtube.com, youtube-nocookie.com, youtu.be',
        help: 'Use the video’s embed address (youtube.com/embed/…), not the watch link.',
    },
    {
        key: 'google_maps',
        label: 'Google Map',
        hosts: 'google.com, maps.google.com',
        help: 'In Google Maps: Share → Embed a map, then copy the address out of the code it gives you.',
    },
    {
        key: 'google_form',
        label: 'Google Form',
        hosts: 'docs.google.com, forms.gle',
        help: 'For forms you already run in Google. Manara’s own Sign-up Form section keeps responses in here instead.',
    },
];

const localContent = ref<EmbedSectionContent>({
    provider: props.modelValue?.provider ?? null,
    url: props.modelValue?.url || '',
    title: props.modelValue?.title || '',
    caption: props.modelValue?.caption || '',
    height: props.modelValue?.height ?? null,
    aspect: props.modelValue?.aspect ?? null,
    fallback_text: props.modelValue?.fallback_text || '',
    background_color: props.modelValue?.background_color || '#ffffff',
});

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = { ...newVal };
    }
}, { deep: true });

const selectedProvider = computed(() =>
    PROVIDERS.find((p) => p.key === localContent.value.provider) ?? null
);

const providerHelp = computed(
    () => selectedProvider.value?.help ?? 'Each provider only accepts addresses from its own site.'
);

const emitUpdate = () => {
    // `iframe` is added by the API when serving the page; it is never authored here and
    // must not be echoed back on save.
    const { iframe: _ignored, ...content } = localContent.value;
    emit('update:modelValue', content as EmbedSectionContent);
};

/**
 * Video is 16:9 and a map is 4:3 essentially always, so switching provider offers the
 * right shape rather than leaving the admin with a fixed pixel height to guess at.
 * Only applied while the admin has not chosen a shape themselves.
 */
const onProviderChange = () => {
    if (!localContent.value.aspect && !localContent.value.height) {
        if (localContent.value.provider === 'youtube') localContent.value.aspect = '16:9';
        if (localContent.value.provider === 'google_maps') localContent.value.aspect = '4:3';
    }
    emitUpdate();
};
</script>

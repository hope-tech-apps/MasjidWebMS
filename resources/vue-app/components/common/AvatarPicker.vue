<template>
    <div>
        <div class="d-flex align-items-center gap-3 mb-3">
            <PersonAvatar :avatar="preview" :first-name="firstName" :last-name="lastName" :size="72" ring="#286c56" />
            <div>
                <div class="fw-semibold">{{ [firstName, lastName].filter(Boolean).join(' ') || 'This student' }}</div>
                <div class="text-muted small">
                    {{ chosen ? 'Tap Save to keep this avatar.' : 'No avatar yet — showing initials.' }}
                </div>
            </div>
        </div>

        <div v-if="loading" class="text-muted small">Loading avatars…</div>

        <template v-else-if="catalogue">
            <label class="form-label small text-muted">Character</label>
            <div class="d-flex gap-2 mb-3">
                <button v-for="c in catalogue.characters" :key="c.id" type="button"
                        class="btn btn-sm" :class="character === c.id ? 'btn-success' : 'btn-outline-secondary'"
                        @click="character = c.id">
                    {{ c.label }}
                </button>
            </div>

            <label class="form-label small text-muted">Skin tone</label>
            <div class="d-flex gap-2 mb-3">
                <button v-for="t in catalogue.tones" :key="t.id" type="button"
                        class="avatar-swatch" :class="{ 'avatar-swatch--on': tone === t.id }"
                        :style="{ background: t.swatch }" :aria-label="t.id"
                        @click="tone = t.id"></button>
            </div>

            <label class="form-label small text-muted">
                {{ character === 'ameera' ? 'Hijab colour' : 'Kufi colour' }}
            </label>
            <div class="d-flex gap-2 mb-3 flex-wrap">
                <button v-for="c in catalogue.colors" :key="c.id" type="button"
                        class="avatar-swatch" :class="{ 'avatar-swatch--on': color === c.id }"
                        :style="{ background: c.swatch }" :aria-label="c.label"
                        @click="color = c.id"></button>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-success" :disabled="saving || !chosen" @click="save">
                    <span v-if="saving" class="spinner-border spinner-border-sm"></span>
                    <span v-else>Save avatar</span>
                </button>
                <button class="btn btn-outline-secondary" :disabled="saving" @click="clear">
                    Use initials
                </button>
            </div>

            <div v-if="error" class="alert alert-danger small mt-3 mb-0">{{ error }}</div>
        </template>
    </div>
</template>

<script setup lang="ts">
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import ApiService from '@/core/services/ApiService';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * Choosing one of the forty drawings the platform ships.
 *
 * The catalogue is FETCHED rather than hardcoded so the picker cannot drift
 * from what the server will accept — adding a colour is then a deploy, not a
 * front-end release.
 */
const props = defineProps<{
    masjidId: number | string;
    contactId?: number | null;
    /** When set, saves through the family realm instead of the admin one. */
    familyEndpoint?: string | null;
    avatar?: { character?: string; tone?: string; color?: string; url?: string } | null;
    firstName?: string | null;
    lastName?: string | null;
}>();

const emit = defineEmits<{ (e: 'saved', avatar: any): void }>();

const catalogue = ref<any>(null);
const loading = ref(true);
const saving = ref(false);
const error = ref('');

const character = ref(props.avatar?.character ?? 'ameer');
const tone = ref(props.avatar?.tone ?? '');
const color = ref(props.avatar?.color ?? '');

const chosen = computed(() => !!character.value && !!tone.value && !!color.value);

/** The live preview comes from the catalogue, so it is a real file every time. */
const preview = computed(() => {
    if (!chosen.value || !catalogue.value) return props.avatar ?? null;
    const match = catalogue.value.options.find(
        (o: any) => o.character === character.value && o.tone === tone.value && o.color === color.value
    );
    return match ? { ...match } : props.avatar ?? null;
});

watch(() => props.avatar, (next) => {
    character.value = next?.character ?? 'ameer';
    tone.value = next?.tone ?? '';
    color.value = next?.color ?? '';
});

onMounted(async () => {
    try {
        const url = props.familyEndpoint
            ? `/api/family/masjids/${props.masjidId}/avatars`
            : `/api/admin/masjids/${props.masjidId}/avatars`;
        const res = await ApiService.get(url as any);
        catalogue.value = res.data?.data ?? null;
    } catch {
        error.value = 'The avatar list could not be loaded.';
    } finally {
        loading.value = false;
    }
});

const send = async (body: Record<string, string | null>) => {
    saving.value = true;
    error.value = '';
    try {
        const url = props.familyEndpoint
            ?? `/api/admin/masjids/${props.masjidId}/contacts/${props.contactId}/avatar`;
        const res = await ApiService.put(url as any, body);
        emit('saved', res.data?.data ?? null);
    } catch (e: any) {
        error.value = e?.response?.data?.message
            || e?.response?.data?.data?.character?.[0]
            || 'That avatar could not be saved.';
    } finally {
        saving.value = false;
    }
};

const save = () => send({ character: character.value, tone: tone.value, color: color.value });
const clear = () => send({ character: null, tone: null, color: null });
</script>

<style scoped>
.avatar-swatch {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: 2px solid #d7dde3;
    padding: 0;
    cursor: pointer;
}
.avatar-swatch--on {
    border-color: #286c56;
    box-shadow: 0 0 0 2px rgba(40, 108, 86, .25);
}
</style>

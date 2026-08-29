<template>
    <div class="student-mode">
        <div v-if="loading" class="text-center py-5"><span class="spinner-border text-success"></span></div>

        <div v-else-if="error" class="container py-5" style="max-width:520px;">
            <div class="alert alert-warning">{{ error }}</div>
            <button class="btn btn-outline-secondary" @click="handBack">Back to the family portal</button>
        </div>

        <template v-else>
            <div class="text-center pt-4 pb-2">
                <PersonAvatar
                    :avatar="student?.contact?.avatar"
                    :first-name="student?.contact?.first_name"
                    :last-name="student?.contact?.last_name"
                    :size="120" ring="#286c56" />
                <h1 class="h4 mt-3 mb-1">Assalamu alaikum, {{ student?.contact?.first_name }}!</h1>
                <p class="text-muted small mb-0">Pick how you want to look.</p>
            </div>

            <div class="container pb-4" style="max-width: 520px;">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <!-- Big targets: this is used by five- to eight-year-olds. -->
                        <label class="form-label small text-muted">Who are you?</label>
                        <div class="d-flex gap-2 mb-4">
                            <button v-for="c in catalogue?.characters" :key="c.id" type="button"
                                    class="btn flex-fill py-3"
                                    :class="character === c.id ? 'btn-success' : 'btn-outline-secondary'"
                                    @click="character = c.id">
                                {{ c.label }}
                            </button>
                        </div>

                        <label class="form-label small text-muted">Your skin</label>
                        <div class="d-flex gap-3 mb-4">
                            <button v-for="t in catalogue?.tones" :key="t.id" type="button"
                                    class="pick" :class="{ 'pick--on': tone === t.id }"
                                    :style="{ background: t.swatch }" @click="tone = t.id"></button>
                        </div>

                        <label class="form-label small text-muted">
                            {{ character === 'ameera' ? 'Your hijab' : 'Your kufi' }}
                        </label>
                        <div class="d-flex gap-3 mb-4 flex-wrap">
                            <button v-for="c in catalogue?.colors" :key="c.id" type="button"
                                    class="pick" :class="{ 'pick--on': color === c.id }"
                                    :style="{ background: c.swatch }" @click="color = c.id"></button>
                        </div>

                        <button class="btn btn-success btn-lg w-100" :disabled="!ready || saving" @click="save">
                            <span v-if="saving" class="spinner-border spinner-border-sm"></span>
                            <span v-else-if="justSaved">Saved!</span>
                            <span v-else>This is me</span>
                        </button>
                    </div>
                </div>

                <button class="btn btn-link w-100 mt-3 text-decoration-none" @click="handBack">
                    Give the phone back
                </button>
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import StudentApiService from '@/core/services/StudentApiService';
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

/**
 * The screen a child gets when a parent hands over the phone.
 *
 * Deliberately ONE thing: choose your face. Everything else a student might one
 * day see is a later slice with its own standing rules, and a screen that grew
 * quietly would be a screen nobody decided the disclosure rules for.
 */
const router = useRouter();

const ctx = StudentApiService.context();
const loading = ref(true);
const saving = ref(false);
const justSaved = ref(false);
const error = ref('');
const student = ref<any>(null);
const catalogue = ref<any>(null);

const character = ref('ameer');
const tone = ref('');
const color = ref('');
const ready = computed(() => !!character.value && !!tone.value && !!color.value);

const base = computed(() =>
    `/api/family/masjids/${ctx?.masjidId}/groups/${ctx?.groupId}/members/${ctx?.membershipId}/student`);

watch([character, tone, color], () => { justSaved.value = false; });

onMounted(async () => {
    if (!StudentApiService.isActive()) {
        error.value = 'This student session has ended. Ask a grown-up to start it again.';
        loading.value = false;
        return;
    }
    try {
        const res = await StudentApiService.get(`${base.value}/me`);
        student.value = res.data?.data?.student ?? null;
        catalogue.value = res.data?.data?.catalogue ?? null;

        const current = student.value?.contact?.avatar;
        character.value = current?.character ?? 'ameer';
        tone.value = current?.tone ?? '';
        color.value = current?.color ?? '';
    } catch (e: any) {
        // A session that has lapsed must say so in words a child's parent can
        // act on, not show an empty screen.
        error.value = e?.response?.status === 403 || e?.response?.status === 401
            ? 'This student session has ended. Ask a grown-up to start it again.'
            : 'Something went wrong. Ask a grown-up for help.';
    } finally {
        loading.value = false;
    }
});

const save = async () => {
    saving.value = true;
    try {
        const res = await StudentApiService.put(`${base.value}/avatar`, {
            character: character.value, tone: tone.value, color: color.value,
        });
        student.value = res.data?.data ?? student.value;
        justSaved.value = true;
    } catch {
        error.value = 'That could not be saved. Ask a grown-up for help.';
    } finally {
        saving.value = false;
    }
};

const handBack = () => {
    StudentApiService.end();
    router.replace(`/family/${ctx?.masjidId ?? ''}`);
};
</script>

<style scoped>
.student-mode { min-height: 100vh; background: #f6f8fa; }
.pick {
    width: 52px; height: 52px; border-radius: 50%;
    border: 3px solid #d7dde3; padding: 0; cursor: pointer;
}
.pick--on { border-color: #286c56; box-shadow: 0 0 0 3px rgba(40,108,86,.25); }
</style>

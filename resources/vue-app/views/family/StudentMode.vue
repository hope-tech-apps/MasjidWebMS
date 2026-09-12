<template>
    <div class="student-mode" :dir="dir" :lang="lang">
        <!-- Above every branch, not inside the loaded one: the session-expired
             message is the single most likely thing on this screen to need
             reading in the other language, and it renders before `student` and
             `catalogue` exist. `justify-content-end` is a flex end, so it
             follows the direction without an override. -->
        <div class="d-flex justify-content-end px-3 pt-3">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    :title="t('switch_lang_title')" @click="toggle">
                {{ switchLabel }}
            </button>
        </div>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border text-success"></span></div>

        <div v-else-if="error" class="container py-5" style="max-width:520px;">
            <div class="alert alert-warning">{{ t(error) }}</div>
            <button class="btn btn-outline-secondary" @click="handBack">{{ t('student_back') }}</button>
        </div>

        <template v-else>
            <div class="text-center pt-4 pb-2">
                <PersonAvatar
                    :avatar="student?.contact?.avatar"
                    :first-name="student?.contact?.first_name"
                    :last-name="student?.contact?.last_name"
                    :size="120" ring="#286c56" />
                <h1 class="h4 mt-3 mb-1">{{ t('student_greeting', student?.contact?.first_name ?? '') }}</h1>
                <p class="text-muted small mb-0">{{ t('student_sub') }}</p>
            </div>

            <div class="container pb-4" style="max-width: 520px;">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <!-- Big targets: this is used by five- to eight-year-olds. -->
                        <label class="form-label small text-muted">{{ t('student_who') }}</label>
                        <div class="d-flex gap-2 mb-4">
                            <button v-for="c in catalogue?.characters" :key="c.id" type="button"
                                    class="btn flex-fill py-3"
                                    :class="character === c.id ? 'btn-success' : 'btn-outline-secondary'"
                                    @click="character = c.id">
                                {{ c.label }}
                            </button>
                        </div>

                        <label class="form-label small text-muted">{{ t('student_skin') }}</label>
                        <div class="d-flex gap-3 mb-4">
                            <!-- `toneOption`, not the `t` this loop used to bind:
                                 a v-for named `t` shadows the translate function
                                 for everything inside it, which is a trap for
                                 whoever adds a label here next. -->
                            <button v-for="toneOption in catalogue?.tones" :key="toneOption.id" type="button"
                                    class="pick" :class="{ 'pick--on': tone === toneOption.id }"
                                    :style="{ background: toneOption.swatch }" @click="tone = toneOption.id"></button>
                        </div>

                        <label class="form-label small text-muted">
                            {{ character === 'ameera' ? t('student_hijab') : t('student_kufi') }}
                        </label>
                        <div class="d-flex gap-3 mb-4 flex-wrap">
                            <button v-for="c in catalogue?.colors" :key="c.id" type="button"
                                    class="pick" :class="{ 'pick--on': color === c.id }"
                                    :style="{ background: c.swatch }" @click="color = c.id"></button>
                        </div>

                        <button class="btn btn-success btn-lg w-100" :disabled="!ready || saving" @click="save">
                            <span v-if="saving" class="spinner-border spinner-border-sm"></span>
                            <span v-else-if="justSaved">{{ t('student_saved') }}</span>
                            <span v-else>{{ t('student_this_is_me') }}</span>
                        </button>
                    </div>
                </div>

                <button class="btn btn-link w-100 mt-3 text-decoration-none" @click="handBack">
                    {{ t('student_hand_back') }}
                </button>
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import StudentApiService from '@/core/services/StudentApiService';
import { useFamilyLang } from '@/views/family/familyI18n';
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

/**
 * The screen a child gets when a parent hands over the phone.
 *
 * Deliberately ONE thing: choose your face. Everything else a student might one
 * day see is a later slice with its own standing rules, and a screen that grew
 * quietly would be a screen nobody decided the disclosure rules for.
 *
 * The character, tone and colour labels come from the server's catalogue and
 * are shown as it sends them — this view translates its own words only.
 */
const router = useRouter();
const { lang, dir, toggle, t, switchLabel } = useFamilyLang();

const ctx = StudentApiService.context();
const loading = ref(true);
const saving = ref(false);
const justSaved = ref(false);

// A key, like everywhere else in the portal: the parent who reads this message
// may be the one reaching for the toggle a second later.
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
        error.value = 'student_session_over';
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
            ? 'student_session_over'
            : 'student_error';
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
        error.value = 'student_save_failed';
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

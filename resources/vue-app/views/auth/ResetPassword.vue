<template>
    <div class="d-flex flex-column align-items-center justify-content-center gap-5 w-100 min-vh-100 py-4">
        <div class="d-flex flex-column align-items-center justify-content-center gap-2">
            <img :src="'/manara-icon.svg'" alt="Manara" width="84" height="84" class="mb-1" />
            <div class="display-4 text-cgreen text-center fw-bold">Manara</div>
            <div class="fs-5 text-muted text-center">Masjid Management Portal</div>
        </div>

        <div class="container">
            <div class="d-flex flex-row flex-wrap align-items-center justify-content-center gap-4">
                <Form @submit="submit()" :validation-schema="validationSchema" class="card border-0 shadow p-3 overflow-auto sign-in-form">
                    <div class="card-header border-0 bg-white text-center fs-1 fw-bold text-cdark">
                        <div class="card-title">{{ done ? 'All set' : 'Choose a password' }}</div>
                    </div>

                    <div class="card-body d-flex flex-column align-items-start justify-content-start gap-4 w-100">
                        <div v-if="done" class="alert alert-success w-100 mb-0">
                            Your password is set. You can sign in with it now.
                        </div>

                        <div v-else-if="!hasLink" class="alert alert-warning w-100 mb-0">
                            This link is incomplete. Open the link from your email exactly as it was sent,
                            or ask for a new one.
                        </div>

                        <template v-else>
                            <p class="text-muted mb-0">
                                Setting the password for <strong>{{ email }}</strong>.
                                Nobody else knows it — not even us.
                            </p>

                            <div v-if="error" class="alert alert-danger w-100 mb-0">{{ error }}</div>

                            <ColumnInputContainer name="password" label="New password" :show_error="true">
                                <PasswordInput name="password" v-model="password" input-class="input w-100" />
                            </ColumnInputContainer>

                            <ColumnInputContainer name="password_confirmation" label="Confirm password" :show_error="true">
                                <PasswordInput name="password_confirmation" v-model="passwordConfirmation" input-class="input w-100" />
                            </ColumnInputContainer>

                            <small class="text-muted">At least 10 characters, with letters and numbers.</small>
                        </template>
                    </div>

                    <div class="card-footer bg-white border-0 d-flex flex-column gap-3">
                        <LoadingButton v-if="hasLink && !done" type="submit" classes="btn-success w-100" :is-loading="loading">
                            Set password
                        </LoadingButton>
                        <router-link to="/auth/sign-in" class="text-center text-decoration-none">
                            {{ done ? 'Go to sign in' : 'Back to sign in' }}
                        </router-link>
                    </div>
                </Form>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import PasswordInput from '@/components/form/PasswordInput.vue';
import LoadingButton from '@/components/form/LoadingButton.vue';
import ApiService from '@/core/services/ApiService';
import { Form } from 'vee-validate';
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { object, ref as yupRef, string } from 'yup';

const route = useRoute();

const token = ref(String(route.query.token ?? ''));
const email = ref(String(route.query.email ?? ''));
const hasLink = computed(() => token.value !== '' && email.value !== '');

const password = ref('');
const passwordConfirmation = ref('');
const loading = ref(false);
const done = ref(false);
const error = ref('');

const validationSchema = object().shape({
    password: string().min(10).required(),
    password_confirmation: string().oneOf([yupRef('password')], 'Passwords must match').required(),
});

const submit = async () => {
    loading.value = true;
    error.value = '';
    try {
        await ApiService.post('/api/admin/reset-password' as any, {
            token: token.value,
            email: email.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
        });
        done.value = true;
    } catch (e: any) {
        const data = e?.response?.data;
        error.value = data?.message
            || data?.data?.password?.[0]
            || 'That link could not be used. Ask for a new one.';
    } finally {
        loading.value = false;
    }
};
</script>

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
                        <div class="card-title">Forgot password</div>
                    </div>

                    <div class="card-body d-flex flex-column align-items-start justify-content-start gap-4 w-100">
                        <!-- Deliberately the same message whether or not the address exists:
                             the API does not disclose who has an account, and neither does this. -->
                        <div v-if="sent" class="alert alert-success w-100 mb-0">
                            {{ message }}
                        </div>

                        <template v-else>
                            <p class="text-muted mb-0">
                                Enter the email address for your account and we will send you a link to set a new password.
                            </p>
                            <ColumnInputContainer name="email" label="Your Email" :show_error="true">
                                <Field type="email" name="email" v-model="email" class="input w-100" placeholder="example@example.com" />
                            </ColumnInputContainer>
                        </template>
                    </div>

                    <div class="card-footer bg-white border-0 d-flex flex-column gap-3">
                        <LoadingButton v-if="!sent" type="submit" classes="btn-success w-100" :is-loading="loading">
                            Send reset link
                        </LoadingButton>
                        <router-link to="/auth/sign-in" class="text-center text-decoration-none">Back to sign in</router-link>
                    </div>
                </Form>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import LoadingButton from '@/components/form/LoadingButton.vue';
import ApiService from '@/core/services/ApiService';
import { Form, Field } from 'vee-validate';
import { ref } from 'vue';
import { object, string } from 'yup';

const validationSchema = object().shape({ email: string().email().required() });

const email = ref('');
const loading = ref(false);
const sent = ref(false);
const message = ref('');

const submit = async () => {
    loading.value = true;
    try {
        const res = await ApiService.post('/api/admin/forgot-password' as any, { email: email.value });
        message.value = res.data?.message || 'If that address belongs to an account, a reset link is on its way.';
    } catch (e: any) {
        // Even a failure must not reveal whether the address exists.
        message.value = 'If that address belongs to an account, a reset link is on its way.';
    } finally {
        sent.value = true;
        loading.value = false;
    }
};
</script>

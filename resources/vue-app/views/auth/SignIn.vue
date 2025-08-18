<template>
    <div class="d-flex flex-column align-items-center justify-content-center gap-5 w-100 min-vh-100 py-4">
        <div class="d-flex flex-column align-items-center justify-content-center gap-2">
            <div class="display-6 text-cgreen text-center fw-bold">
                Masjid App Admin Login
            </div>
        </div>

        <div class="container">
            <div class="d-flex flex-row flex-wrap align-items-center justify-content-center gap-4">
                <Form @submit="signIn()" :validation-schema="validationSchema" class="card border-0 shadow p-3 overflow-auto sign-in-form">
                    <div class="card-header border-0 bg-white text-center fs-1 fw-bold text-cdark">
                        <div class="card-title">Login</div>
                    </div>
                    <div
                        class="card-body d-flex flex-column align-items-start justify-content-start gap-4 w-100">
                        <ColumnInputContainer name="email" label="Your Email" :show_error="true">
                            <Field type="email" name="email" v-model="signData.email" class="input w-100" placeholder="example@example.com" />
                        </ColumnInputContainer>

                        <ColumnInputContainer name="email" label="Your Password" :show_error="true">
                            <Field type="password" name="password" v-model="signData.password" class="input w-100" />
                        </ColumnInputContainer>
                    </div>
                    <div class="card-footer bg-white border-0">
                        <LoadingButton type="submit" classes="btn-success w-100" :is-loading="submitLoading">
                            Sign In
                        </LoadingButton>
                    </div>
                </Form>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import LoadingButton from '@/components/form/LoadingButton.vue';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { Form, Field } from 'vee-validate';
import { onBeforeMount, ref } from 'vue';
import { useRouter } from 'vue-router';
import { object, string } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {
    if(authStore.isAuthenticated) {
        router.push('/');
    }
});

// Routing
const router = useRouter();

// Stores
const authStore = useAuthStore();
const masjidStore = useMasjidStore();

// Custom constants
const nexPath = ref<string|void>()
const validationSchema = object().shape({
    email: string().email().required(),
    password: string().required()
});

const signData = ref({
    email: "",
    password: ""
});
const submitLoading = ref<boolean>(false);

async function signIn () : Promise<void> {
    submitLoading.value = true;
    await authStore.login(signData.value.email, signData.value.password)
        .finally(async () => {
            if (authStore.isAuthenticated) {
                if(authStore.user?.type === 'MasjidAdmin' && authStore.user.masjid) {
                    authStore.saveDashboardMasjidId(authStore.user.masjid.id);
                    await masjidStore.fetchMasjid()
                        .finally(async () => {
                            router.push("/masjid");
                        });
                }
                else if(authStore.user?.type === 'SuperAdmin') {
                    router.push("/auth/dashboards");
                } else {
                    router.push("/auth/401");
                }
            } else {
                router.push("/");
            }
            submitLoading.value = false;
        });
}

</script>

<style scoped>
.sign-in-form {
    width: 22rem;
}
</style>
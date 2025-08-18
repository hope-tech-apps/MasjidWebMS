<template>
    <DataItemContainer title="Tasbih Details" @edit-button-click="router.push(`/tasabih/${route.params.tasbih_id}/edit`)"
        @delete-button-click="deleteTasbih" :show-archive="false">
        <div v-if="tasbih" class="d-flex flex-column gap-4">
            <div v-for="key in TASBIH_SHOW_ATTRIBUTE" class="d-flex flex-column gap-1 tasbih-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    {{ key }}
                </span>
                <p v-html="getAttributeValues(key as keyof Tasbih, tasbih)" class="fs-6 m-0">
                </p>
            </div>
        </div>
    </DataItemContainer>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import DataItemContainer from '@/components/DataItemContainer.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { Tasbih, TASBIH_SHOW_ATTRIBUTE } from '@/core/types/data/Tasabih';
import { TranslatableObject } from '@/core/types/data/interfaces/TranslatableObject';
import { useTasabihStore } from '@/stores/tasabihStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { onBeforeMount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    if (route.params.tasbih_id && tasabihStore.tasabihPaginated?.data) {
        tasabihStore.fetchTasbih(route.params.tasbih_id as string, tasbih);
    } else {
        router.push('/tasabih');
    }
});

// Routing
const router = useRouter()
const route = useRoute()

// Stores
const tasabihStore = useTasabihStore();

// Computed

// Custom constants
const tasbih = ref<Tasbih>();

// Functions
const getAttributeValues = (key: keyof Tasbih, tasbih: Tasbih) => {
    let text = '';
    if (typeof tasbih[key] === 'object') {
        let obj = tasbih[key] as TranslatableObject;
        if (obj) {
            text = `AR: ${obj.ar}<br />`;
            text += `EN: ${obj.en}`;
        }
    } else {
        text = tasbih[key] + '';
    }

    return text;
}

const deleteTasbih = async () => {
    QSwal.fire("Warning", 'You are going to delete this tasbih !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (tasbih.value?.id) {
                    await ApiService.delete(`/api/admin/tasabih/${tasbih.value.id}/`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Tasbih deleted successfully.";
                                swalInstance.icon = "success";
                            } else {
                                swalInstance.title = "Sorry";
                                swalInstance.text = getMessageFromObj(res);
                                swalInstance.icon = "warning";
                            }
                        })
                        .catch((e: AxiosError<BackendResponseData>) => {
                            console.log(e);
                            swalInstance.title = e.message;
                            swalInstance.text = getMessageFromObj(e);
                            swalInstance.icon = "error";
                        })
                        .finally(async () => {
                            await tasabihStore.fetchTasabihPaginated(1).finally(() => {
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/tasabih`);
                                });
                            });
                        });
                }
            }
        })
}

</script>

<style scoped>
.tasbih-attribute {
    background-color: var(--cgreen-light);
    border-left: 5px solid var(--cgreen);
    padding: .5rem;
}
</style>
<template>
    <DataItemContainer title="Zikr Details" @edit-button-click="router.push(`/azkar/${route.params.zikr_id}/edit`)"
        @delete-button-click="deleteZikr" :show-archive="false">
        <div v-if="zikr" class="d-flex flex-column gap-4">
            <div v-for="key in ZIKR_SHOW_ATTRIBUTES" class="d-flex flex-column gap-1 zikr-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    {{ key }}
                </span>
                <p v-html="getAttributeValues(key as keyof Zikr, zikr)" class="fs-6 m-0">
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
import { Zikr, ZIKR_SHOW_ATTRIBUTES } from '@/core/types/data/Azkar';
import { TranslatableObject } from '@/core/types/data/interfaces/TranslatableObject';
import { useAzkarStore } from '@/stores/azkarStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { onBeforeMount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    if (route.params.zikr_id && azkarStore.azkarPaginated?.data) {
        azkarStore.fetchZikr(route.params.zikr_id as string, zikr);
    } else {
        router.push('/azkar');
    }
});

// Routing
const router = useRouter()
const route = useRoute()

// Stores
const azkarStore = useAzkarStore();

// Computed

// Custom constants
const zikr = ref<Zikr>();

// Functions
const getAttributeValues = (key: keyof Zikr, zikr: Zikr) => {
    let text = '';
    if (typeof zikr[key] === 'object') {
        let obj = zikr[key] as TranslatableObject;
        if (obj) {
            text = `AR: ${obj.ar}<br />`;
            text += `EN: ${obj.en}`;
        }
    } else {
        text = zikr[key] + '';
    }

    return text;
}

const deleteZikr = async () => {
    QSwal.fire("Warning", 'You are going to delete this zikr !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (zikr.value?.id) {
                    await ApiService.delete(`/api/admin/azkar/${zikr.value.id}/`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Zikr deleted successfully.";
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
                            await azkarStore.fetchAzkarPaginated(1).finally(() => {
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/azkar`);
                                });
                            });
                        });
                }
            }
        })
}

</script>

<style scoped>
.zikr-attribute {
    background-color: var(--cgreen-light);
    border-left: 5px solid var(--cgreen);
    padding: .5rem;
}
</style>
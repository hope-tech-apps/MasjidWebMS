<template>
    <DataItemContainer title="Hadith Details" @edit-button-click="router.push(`/hadith/${route.params.hadith_id}/edit`)"
        @delete-button-click="deleteHadith" :show-archive="false">
        <div v-if="hadith" class="d-flex flex-column gap-4">
            <div v-for="key in HADITH_SHOW_ATTRIBUTES" class="d-flex flex-column gap-1 hadith-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    {{ key }}
                </span>
                <p v-html="getAttributeValues(key as keyof Hadith, hadith)" class="fs-6 m-0">
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
import { Hadith, HADITH_SHOW_ATTRIBUTES } from '@/core/types/data/Hadith';
import { useHadithStore } from '@/stores/hadithStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { onBeforeMount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    if (route.params.hadith_id && hadithsStore.hadithsPaginated?.data) {
        await hadithsStore.fetchHadith(route.params.hadith_id as string, hadith);
    } else {
        router.push('/hadith');
    }
});

// Routing
const router = useRouter()
const route = useRoute()

// Stores
const hadithsStore = useHadithStore();

// Computed

// Custom constants
const hadith = ref<Hadith>();

// Functions
const getAttributeValues = (key: keyof Hadith, hadith: Hadith) => {
    let text = '';
    if (key === 'references') {
        text = '';
        for (let i = 0; i < hadith.references.length; i++) {
            text += `${hadith.references[i].title}: (${hadith.references[i].reference})<br />`;
        }
    } else if (typeof hadith[key] === 'object') {
        let obj = hadith[key];
        text = `AR: ${obj.ar}<br />`;
        text += `EN: ${obj.en}`;
    } else {
        text = hadith[key] + '';
    }

    return text;
}

const deleteHadith = async () => {
    QSwal.fire("Warning", 'You are going to delete this hadith !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (hadith.value?.id) {
                    await ApiService.delete(`/api/admin/hadiths/${hadith.value.id}/`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Hadith deleted successfully.";
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
                            await hadithsStore.fetchHadithsPaginated(1).finally(() => {
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/hadith`);
                                });
                            });
                        });
                }
            }
        })
}

</script>

<style scoped>
.hadith-attribute {
    background-color: var(--cgreen-light);
    border-left: 5px solid var(--cgreen);
    padding: .5rem;
}
</style>
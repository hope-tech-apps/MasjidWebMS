<template>
    <div class="card border-0 py-4 px-3 w-100">
        <div class="card-header bg-white border-0 d-flex flex-column flex-sm-row align-items-start justify-content-between">
            <div class="card-title fs-4 fw-semibold">
                {{ title }}
            </div>
            <div class="card-toolbar d-flex gap-2">
                <slot name="headerButtons"></slot>
                <button v-if="!hideButton" :type="buttonProps.type" @click.prevent="headerButtonClick" :class="buttonProps.class"
                    :disabled="buttonProps.disabled">
                    {{ buttonProps.title }}
                </button>
            </div>
        </div>

        <div class="card-body w-100">
            <slot />
        </div>

        <div class="card-footer bg-white border-0 d-flex align-items-center justify-content-center w-100">
            <Pagination v-if="paginationOptions" :options="paginationOptions" @page-change="pageChange"></Pagination>
        </div>
    </div>
</template>

<script setup lang="ts">
import Pagination from '@/components/partials/Pagination.vue';
import { ButtonProps } from '@/core/types/elements/Buttons';
import { PaginationIndicies, PaginationOptions } from '@/core/types/elements/Pagination';
import { PropType, toRefs } from 'vue';

// Props
const props = defineProps({
    title: {
        type: String,
        required: true
    },
    hideButton: {
        type: Boolean,
        required: false,
        default: false
    },
    buttonProps: {
        type: Object as PropType<ButtonProps>,
        required: false,
        default: {
            title: "Add New",
            type: "button",
            class: "btn btn-success",
            disabled: false
        }
    },
    paginationOptions: {
        type: Object as PropType<PaginationOptions>,
        required: false
    }
})

// Emits
const emits = defineEmits(['headerButtonClick', 'pageChange']);

const { title, paginationOptions, buttonProps, hideButton } = toRefs(props);

function headerButtonClick() {
    emits('headerButtonClick');
}

function pageChange(indicies: PaginationIndicies) {
    emits('pageChange', indicies);
}

</script>

<template>
    <div class="card border-0 py-4 px-3 w-100 gap-4">
        <div class="card-header bg-white border-0 d-flex align-items-start gap-4 justify-content-between">
            <div class="d-flex align-items-center gap-4">
                <slot name="headerIcon"></slot>
                <div class="card-title fs-4 fw-semibold m-0">
                    {{ title }}
                </div>
            </div>
            <div class="card-toolbar">
                <!-- Account Dropdown Menu -->
                <div class="btn-group">
                    <button type="button" class=" btn btn-success dropdown-toggle d-flex align-items-center gap-2"
                        data-bs-toggle="dropdown" aria-expanded="false">
                        Actions
                    </button>
                    <ul class="dropdown-menu">

                        <!-- Edit LI -->
                        <li v-if="showEdit">
                            <button type="button" @click.prevent="editButtonClick"
                                class="dropdown-item d-flex align-items-center justify-content-centr gap-2">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <g clip-path="url(#clip0_310_3322)">
                                        <path
                                            d="M22.1511 15.0168C21.8209 15.0168 21.5533 15.2843 21.5533 15.6145V20.9216C21.5521 21.9116 20.7501 22.7139 19.7602 22.7148H2.98862C1.99861 22.7139 1.19664 21.9116 1.19545 20.9216V5.34555C1.19664 4.35578 1.99867 3.55358 2.98862 3.55238H8.29581C8.62594 3.55238 8.89354 3.28478 8.89354 2.95466C8.89354 2.62471 8.62594 2.35693 8.29581 2.35693H2.98862C1.33878 2.35879 0.00185294 3.69572 0 5.34555V20.9219C0.00185294 22.5717 1.33878 23.9086 2.98862 23.9105H19.7602C21.4099 23.9086 22.7469 22.5717 22.7488 20.9219V15.6145C22.7488 15.2843 22.4812 15.0168 22.1511 15.0168Z"
                                            fill="#6B6C6F" />
                                        <path
                                            d="M22.5121 0.81908C21.4616 -0.23136 19.7585 -0.23136 18.7082 0.81908L8.04427 11.4829C7.97128 11.5559 7.91844 11.6465 7.8909 11.746L6.48858 16.8087C6.46028 16.9107 6.45954 17.0183 6.48645 17.1206C6.51336 17.2229 6.56695 17.3162 6.64175 17.3911C6.71654 17.4659 6.80985 17.5195 6.91215 17.5464C7.01445 17.5734 7.12208 17.5727 7.22402 17.5444L12.2867 16.1419C12.3862 16.1143 12.4769 16.0615 12.5499 15.9885L23.2134 5.32449C24.2622 4.27327 24.2622 2.57167 23.2134 1.52051L22.5121 0.81908ZM9.34666 11.8714L18.0742 3.14369L20.8889 5.95837L12.1612 14.6861L9.34666 11.8714ZM8.78444 12.9996L11.0331 15.2486L7.92264 16.1104L8.78444 12.9996ZM22.3683 4.47924L21.7343 5.11307L18.9194 2.29815L19.5536 1.66426C20.1371 1.08076 21.0831 1.08076 21.6666 1.66426L22.3683 2.36563C22.9508 2.94985 22.9508 3.89527 22.3683 4.47924Z"
                                            fill="#6B6C6F" />
                                    </g>
                                    <defs>
                                        <clipPath id="clip0_310_3322">
                                            <rect width="24" height="24" fill="white" />
                                        </clipPath>
                                    </defs>
                                </svg>
                                <span>Edit</span>
                            </button>
                        </li>

                        <!-- Delete LI -->
                        <li v-if="showDelete">
                            <button type="button" @click.prevent="deleteButtonClick"
                                class="dropdown-item d-flex align-items-center justify-content-centr gap-2 delete-li-btn">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M19.875 3H15.75V2.25C15.75 1.00936 14.7406 0 13.5 0H10.5C9.25936 0 8.25 1.00936 8.25 2.25V3H4.125C3.09113 3 2.25 3.84113 2.25 4.875V7.5C2.25 7.91419 2.58581 8.25 3 8.25H3.40988L4.05783 21.857C4.11506 23.0587 5.10225 24 6.30525 24H17.6947C18.8978 24 19.885 23.0587 19.9422 21.857L20.5901 8.25H21C21.4142 8.25 21.75 7.91419 21.75 7.5V4.875C21.75 3.84113 20.9089 3 19.875 3ZM9.75 2.25C9.75 1.83647 10.0865 1.5 10.5 1.5H13.5C13.9135 1.5 14.25 1.83647 14.25 2.25V3H9.75V2.25ZM3.75 4.875C3.75 4.66823 3.91823 4.5 4.125 4.5H19.875C20.0818 4.5 20.25 4.66823 20.25 4.875V6.75H3.75V4.875ZM18.4439 21.7857C18.4349 21.9783 18.3521 22.16 18.2125 22.293C18.073 22.4261 17.8875 22.5002 17.6947 22.5H6.30525C6.11245 22.5002 5.92699 22.4261 5.78746 22.293C5.64794 22.16 5.56508 21.9783 5.55614 21.7857L4.91156 8.25H19.0884L18.4439 21.7857Z"
                                        fill="#6B6C6F" />
                                    <path
                                        d="M12 21C12.4142 21 12.75 20.6642 12.75 20.25V10.5C12.75 10.0858 12.4142 9.75 12 9.75C11.5858 9.75 11.25 10.0858 11.25 10.5V20.25C11.25 20.6642 11.5858 21 12 21ZM15.75 21C16.1642 21 16.5 20.6642 16.5 20.25V10.5C16.5 10.0858 16.1642 9.75 15.75 9.75C15.3358 9.75 15 10.0858 15 10.5V20.25C15 20.6642 15.3358 21 15.75 21ZM8.25 21C8.66419 21 9 20.6642 9 20.25V10.5C9 10.0858 8.66419 9.75 8.25 9.75C7.83581 9.75 7.5 10.0858 7.5 10.5V20.25C7.5 20.6642 7.83577 21 8.25 21Z"
                                        fill="#6B6C6F" />
                                </svg>
                                <span>Delete</span>
                            </button>
                        </li>

                        <!-- Archive LI -->
                        <li v-if="showArchive">
                            <button type="button" @click.prevent="archiveButtonClick"
                                class="dropdown-item d-flex align-items-center justify-content-centr gap-2 archive-li-btn">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M20.25 2.25H3.75C3.15326 2.25 2.58097 2.48705 2.15901 2.90901C1.73705 3.33097 1.5 3.90326 1.5 4.5V6.75C1.5013 7.21421 1.64616 7.66665 1.9147 8.0453C2.18325 8.42395 2.56234 8.71027 3 8.865V19.5C3 20.0967 3.23705 20.669 3.65901 21.091C4.08097 21.5129 4.65326 21.75 5.25 21.75H18.75C19.3467 21.75 19.919 21.5129 20.341 21.091C20.7629 20.669 21 20.0967 21 19.5V8.865C21.4377 8.71027 21.8168 8.42395 22.0853 8.0453C22.3538 7.66665 22.4987 7.21421 22.5 6.75V4.5C22.5 3.90326 22.2629 3.33097 21.841 2.90901C21.419 2.48705 20.8467 2.25 20.25 2.25ZM19.5 19.5C19.5 19.6989 19.421 19.8897 19.2803 20.0303C19.1397 20.171 18.9489 20.25 18.75 20.25H5.25C5.05109 20.25 4.86032 20.171 4.71967 20.0303C4.57902 19.8897 4.5 19.6989 4.5 19.5V9H19.5V19.5ZM21 6.75C21 6.94891 20.921 7.13968 20.7803 7.28033C20.6397 7.42098 20.4489 7.5 20.25 7.5H3.75C3.55109 7.5 3.36032 7.42098 3.21967 7.28033C3.07902 7.13968 3 6.94891 3 6.75V4.5C3 4.30109 3.07902 4.11032 3.21967 3.96967C3.36032 3.82902 3.55109 3.75 3.75 3.75H20.25C20.4489 3.75 20.6397 3.82902 20.7803 3.96967C20.921 4.11032 21 4.30109 21 4.5V6.75Z"
                                        fill="#6B6C6F" />
                                    <path
                                        d="M9.75 12.75H14.25C14.4489 12.75 14.6397 12.671 14.7803 12.5303C14.921 12.3897 15 12.1989 15 12C15 11.8011 14.921 11.6103 14.7803 11.4697C14.6397 11.329 14.4489 11.25 14.25 11.25H9.75C9.55109 11.25 9.36032 11.329 9.21967 11.4697C9.07902 11.6103 9 11.8011 9 12C9 12.1989 9.07902 12.3897 9.21967 12.5303C9.36032 12.671 9.55109 12.75 9.75 12.75Z"
                                        fill="#6B6C6F" />
                                </svg>
                                <span>Archive</span>
                            </button>
                        </li>

                    </ul>
                </div>
            </div>
        </div>

        <div class="card-body w-100">
            <slot />
        </div>

        <slot name="card_footer" />
    </div>
</template>

<script setup lang="ts">
import { PaginationIndicies, PaginationOptions } from '@/core/types/elements/Pagination';
import { PropType, toRefs } from 'vue';

// Props
const props = defineProps({
    title: {
        type: String,
        required: true
    },
    showEdit: {
        type: Boolean,
        required: false,
        default: true
    },
    showDelete: {
        type: Boolean,
        required: false,
        default: true
    },
    showArchive: {
        type: Boolean,
        required: false,
        default: true
    }
})

// Emits
const emits = defineEmits(['pageChange', 'editButtonClick', 'deleteButtonClick', 'archiveButtonClick']);

const { title } = toRefs(props);

function editButtonClick() {
    emits('editButtonClick');
}

function deleteButtonClick() {
    emits('deleteButtonClick');
}

function archiveButtonClick() {
    emits('archiveButtonClick');
}

function pageChange(indicies: PaginationIndicies) {
    emits('pageChange', indicies);
}

</script>

<style scoped>
.dropdown-item:hover.delete-li-btn {
    background-color: var(--bs-danger);
    color: white;
}

.dropdown-menu .dropdown-item:hover.delete-li-btn svg path {
    fill: white;
}

.dropdown-item:hover.archive-li-btn {
    background-color: var(--bs-primary);
    color: white;
}

.dropdown-menu .dropdown-item:hover.archive-li-btn svg path {
    fill: white;
}
</style>
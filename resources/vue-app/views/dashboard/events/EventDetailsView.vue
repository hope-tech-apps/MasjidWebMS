<template>
    <DataItemContainer title="Event Details" @edit-button-click="router.push(`/masjid/events/${route.params.event_id}/edit`)"
        @delete-button-click="deleteEvent" :show-archive="false">
        <div v-if="event" class="d-flex flex-column gap-4">

            <!-- Duplicate action.
                 Lives here rather than in DataItemContainer's Actions dropdown
                 because that component is shared by every detail screen in the
                 dashboard and duplicating is an events-only idea. -->
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-outline-success d-flex align-items-center gap-2"
                    @click="openDuplicateModal">
                    <i class="bi bi-files"></i>
                    <span>Duplicate</span>
                </button>
            </div>

            <div class="d-flex flex-column gap-1 event-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    Title
                </span>
                <span class="fs-6 m-0">
                    {{ event.title }}
                </span>
            </div>
            <div class="d-flex flex-column gap-1 event-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    Date
                </span>
                <div class="d-flex flex-col flex-md-row gap-2">
                    <span class="fs-6 m-0">
                        <b>From: </b>{{ event.start }}
                    </span>
                    |
                    <span class="fs-6 m-0">
                        <b>To: </b>{{ event.end }}
                    </span>
                </div>
            </div>
            <div class="d-flex flex-column gap-1 event-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    Details
                </span>
                <p class="fs-6 m-0">
                    {{ event.details }}
                </p>
            </div>
            <div class="d-flex flex-column gap-1 event-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    Place
                </span>
                <span class="fs-6 m-0">
                    {{ event.place }}
                </span>
            </div>
            <div class="d-flex flex-column gap-1 event-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    Link
                </span>
                <span class="fs-6 m-0">
                    {{ event.link }}
                </span>
            </div>
        </div>
    </DataItemContainer>

    <!-- Duplicate modal.
         The whole point of this screen is that the admin sees EVERY date that
         will be created before anything is created: the review panel below is
         rendered from the same strings the request body carries, so there is
         no gap between what was shown and what is sent. -->
    <Teleport to="body">
        <div v-if="showDuplicateModal" class="modal fade show d-block" tabindex="-1"
            style="background: rgba(0,0,0,0.5);" @click.self="closeDuplicateModal">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title d-flex align-items-center gap-2">
                            <i class="bi bi-files"></i>
                            Copy this event to another date
                        </h5>
                        <button type="button" class="btn-close" @click="closeDuplicateModal"></button>
                    </div>

                    <form @submit.prevent="submitDuplicate">
                        <div class="modal-body d-flex flex-column gap-3">

                            <div class="alert alert-light border d-flex align-items-start gap-2 mb-0">
                                <i class="bi bi-info-circle mt-1"></i>
                                <div>
                                    Title, details, place and link are copied from
                                    <b>{{ event?.title }}</b>.
                                    <span class="d-block text-muted">
                                        Each copy is a separate event you can edit or delete on its own.
                                        Changing one copy later does not change the others.
                                    </span>
                                </div>
                            </div>

                            <!-- One row per new date -->
                            <div v-for="(row, index) in duplicateRows" :key="row.key" class="border rounded p-3">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="fw-semibold text-muted">Copy {{ index + 1 }}</span>
                                    <button v-if="duplicateRows.length > 1" type="button"
                                        class="btn btn-sm btn-link text-danger text-decoration-none d-flex align-items-center gap-1"
                                        @click="removeRow(index)">
                                        <i class="bi bi-x-lg"></i>
                                        <span>Remove</span>
                                    </button>
                                </div>
                                <div class="row g-2">
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">Start date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" v-model="row.start_date"
                                            :min="todayIso">
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">Start time <span class="text-danger">*</span></label>
                                        <input type="time" class="form-control" v-model="row.start_time">
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">End date</label>
                                        <input type="date" class="form-control" v-model="row.end_date"
                                            :min="row.start_date || todayIso">
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">End time</label>
                                        <input type="time" class="form-control" v-model="row.end_time">
                                    </div>
                                </div>
                                <div v-if="rowErrors[index]" class="text-danger small mt-2">
                                    <i class="bi bi-exclamation-triangle me-1"></i>{{ rowErrors[index] }}
                                </div>
                            </div>

                            <div>
                                <button type="button"
                                    class="btn btn-sm btn-link text-decoration-none d-flex align-items-center gap-1 px-0"
                                    :disabled="duplicateRows.length >= MAX_DATES" @click="addRow">
                                    <i class="bi bi-plus-lg"></i>
                                    <span>Add another date</span>
                                </button>
                                <div v-if="duplicateRows.length >= MAX_DATES" class="text-muted small">
                                    {{ MAX_DATES }} dates is the maximum for one copy. Run it again for more.
                                </div>
                            </div>

                            <!-- Review: exactly what will be created -->
                            <div class="border rounded p-3 bg-light">
                                <div class="fw-semibold mb-2">
                                    <i class="bi bi-eye me-1"></i>
                                    This will create {{ previewDates.length }}
                                    {{ previewDates.length === 1 ? 'event' : 'events' }}
                                </div>
                                <ul v-if="previewDates.length" class="mb-0 ps-3">
                                    <!-- Keyed by position, not by date: two rows may briefly
                                         hold the same date while the admin is still typing,
                                         and duplicate keys would make Vue reuse the wrong node. -->
                                    <li v-for="(preview, previewIndex) in previewDates" :key="previewIndex"
                                        class="small">
                                        {{ preview.start }}
                                        <span v-if="preview.end" class="text-muted"> &rarr; {{ preview.end }}</span>
                                    </li>
                                </ul>
                                <div v-else class="text-muted small">
                                    Fill in a start date and time to see what will be created.
                                </div>
                            </div>

                            <div v-if="formError" class="alert alert-danger mb-0">
                                {{ formError }}
                            </div>

                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" @click="closeDuplicateModal">Cancel</button>
                            <LoadingButton type="submit" :is-loading="isDuplicating" classes="btn btn-success">
                                <span>
                                    Create {{ previewDates.length }}
                                    {{ previewDates.length === 1 ? 'event' : 'events' }}
                                </span>
                            </LoadingButton>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import DataItemContainer from '@/components/DataItemContainer.vue';
import LoadingButton from '@/components/form/LoadingButton.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import { Event } from '@/core/types/data/masjid-related/Event';
import { useEventsStore } from '@/stores/masjid/eventsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { computed, onBeforeMount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    if (route.params.event_id) {
        eventsStore.fetchEvent(route.params.event_id as string, event);
    } else {
        router.push('/masjid/events');
    }
});

// Routing
const router = useRouter()
const route = useRoute()

// Stores
const eventsStore = useEventsStore();
const masjidStore = useMasjidStore();

// Custom types
type DuplicateRow = {
    key: number;
    start_date: string;
    start_time: string;
    end_date: string;
    end_time: string;
};

type DuplicateDate = {
    start: string;
    end: string | null;
};

// Custom constants

/**
 * Mirrors DuplicateEventRequest::MAX_DATES. The server keeps its own copy — this
 * one only stops the admin building a batch the server would reject.
 */
const MAX_DATES = 12;

const event = ref<Event>();

const showDuplicateModal = ref(false);
const isDuplicating = ref(false);
const duplicateRows = ref<DuplicateRow[]>([]);
const rowErrors = ref<Record<number, string>>({});
const formError = ref('');
let nextRowKey = 0;

/**
 * The admin's OWN wall-calendar day, in ISO order, used as the `min` on every
 * date input and as the floor validateRows() enforces.
 *
 * It is built from getFullYear/getMonth/getDate on purpose.
 * `new Date().toISOString()` is the UTC day, and every organisation on this
 * platform sits in a UTC-negative zone: at 20:30 in New York, toISOString()
 * already says tomorrow, so `min` would mark TODAY out of range. The modal body
 * is a real <form> with a native submit, so constraint validation would then
 * block the submit event outright — submitDuplicate would never run and the
 * admin would see nothing but a native bubble on an input that may be scrolled
 * out of view. Every evening, on every masjid. (toLocaleDateString('en-CA')
 * gives the same string but leans on ICU locale data for the ordering; this
 * spelling cannot be broken by a runtime's locale set.)
 *
 * A ref, refreshed each time the modal opens, so a dashboard left open
 * overnight does not keep refusing the new day as "in the past".
 */
const localDayIso = (): string => {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
};

const todayIso = ref(localDayIso());

// Computed

/**
 * The dates that will actually be created, built from the rows the admin can
 * see. The request body is built from THIS list, so the review panel and the
 * payload can never drift apart — a preview that is computed separately from
 * the submission is a preview that eventually lies.
 */
const previewDates = computed<DuplicateDate[]>(() => {
    return duplicateRows.value
        .filter(row => row.start_date && row.start_time)
        .map(row => ({
            start: `${row.start_date} ${row.start_time}`,
            end: row.end_date && row.end_time ? `${row.end_date} ${row.end_time}` : null,
        }));
});

// Functions
const blankRow = (): DuplicateRow => ({
    key: nextRowKey++,
    start_date: '',
    start_time: '',
    end_date: '',
    end_time: '',
});

const openDuplicateModal = () => {
    formError.value = '';
    rowErrors.value = {};
    duplicateRows.value = [blankRow()];
    // Re-read the day here, not once at setup: a dashboard left open past
    // midnight would otherwise hold yesterday and refuse today.
    todayIso.value = localDayIso();
    showDuplicateModal.value = true;
};

const closeDuplicateModal = () => {
    showDuplicateModal.value = false;
};

const addRow = () => {
    if (duplicateRows.value.length < MAX_DATES) {
        duplicateRows.value.push(blankRow());
    }
};

const removeRow = (index: number) => {
    duplicateRows.value.splice(index, 1);
    rowErrors.value = {};
};

/**
 * Client-side checks, kept deliberately in step with EventFormView's yup schema
 * (a start date may not be in the past) and with DuplicateEventRequest. They are
 * guidance, not a boundary: the server validates the same things independently,
 * because a client cap that is the only cap is not a cap.
 */
const validateRows = (): boolean => {
    const errors: Record<number, string> = {};
    const seen = new Set<string>();

    duplicateRows.value.forEach((row, index) => {
        if (!row.start_date || !row.start_time) {
            errors[index] = 'A start date and start time are required.';
            return;
        }

        if (row.start_date < todayIso.value) {
            errors[index] = 'Pick a date that is not in the past.';
            return;
        }

        const start = `${row.start_date} ${row.start_time}`;

        if (seen.has(start)) {
            errors[index] = 'This date and time is already listed above.';
            return;
        }
        seen.add(start);

        if ((row.end_date && !row.end_time) || (!row.end_date && row.end_time)) {
            errors[index] = 'An end needs both a date and a time — or leave both blank.';
            return;
        }

        if (row.end_date && row.end_time && `${row.end_date} ${row.end_time}` <= start) {
            errors[index] = 'The end must be after the start.';
        }
    });

    rowErrors.value = errors;

    return Object.keys(errors).length === 0;
};

const submitDuplicate = async () => {
    formError.value = '';

    if (!validateRows()) {
        return;
    }

    const dates = previewDates.value;

    if (!dates.length) {
        formError.value = 'Add at least one date to copy this event onto.';
        return;
    }

    if (!event.value?.id || !masjidStore.masjid?.id) {
        formError.value = 'This event is still loading. Try again in a moment.';
        return;
    }

    // The confirmation names every date, so "I did not realise it would make
    // that many" cannot happen after the fact.
    const confirmation = await QSwal.fire(
        'Question',
        `Create ${dates.length} ${dates.length === 1 ? 'copy' : 'copies'} of this event?\n\n`
        + dates.map(date => date.end ? `${date.start} → ${date.end}` : date.start).join('\n'),
        'question'
    );

    if (!confirmation.isConfirmed) {
        return;
    }

    isDuplicating.value = true;

    const swalInstance: SweetAlertOptions = {
        title: "Success",
        text: "Nothing",
        icon: "success"
    };

    // Set ONLY on the branch that actually created rows. Everything destructive
    // to the admin's typing hangs off this flag — see the comment below the
    // call for why a `.finally` cannot be trusted with any of it.
    let created = false;

    // A plain object, so ApiService's interceptor sends it as JSON. FormData or
    // URLSearchParams would have to flatten `dates[0][start]` by hand, and this
    // codebase has already lost a release to a body encoded one way under a
    // Content-Type that said another.
    const apiEndpoint = `/api/admin/masjids/${masjidStore.masjid.id}/events/${event.value.id}/duplicate`;

    await ApiService.post(apiEndpoint as BackendApiRoute, { dates })
        .then(res => {
            if (res.data.status === 'success') {
                const createdCount = Array.isArray(res.data.data) ? res.data.data.length : dates.length;
                created = true;
                swalInstance.text = `${createdCount} ${createdCount === 1 ? 'event' : 'events'} created.`;
            } else {
                // A 200 that is not a success — the legacy {status:'failed'}
                // shape. Nothing was created.
                formError.value = getMessageFromObj(res);
            }
        })
        .catch((e: AxiosError<BackendResponseData>) => {
            // Every server refusal lands here: the 422 for a date that already
            // holds this event, for more than MAX_DATES dates, for a malformed
            // date, and any transport failure. In all of them the controller
            // ran inside DB::transaction and created NOTHING.
            formError.value = getMessageFromObj(e) || e.message;
        })
        .finally(() => {
            // The ONLY thing that is safe to do unconditionally.
            isDuplicating.value = false;
        });

    // Closing the modal, refreshing the list and navigating away used to sit in
    // the `.finally`, which meant a refused batch also threw away the admin's
    // work: openDuplicateModal rebuilds from ONE blank row, so up to twelve
    // hand-typed dates vanished, and the admin was left on the events list
    // reading "remove those dates and try again" about a form that no longer
    // existed. The server's own 422 is an instruction; it is only followable
    // while the rows are still on screen.
    if (!created) {
        return;
    }

    showDuplicateModal.value = false;

    await eventsStore.fetchMasjidEventsPaginated(1);
    await MSwal.fire(swalInstance);
    await router.push(`/masjid/events`);
};

const deleteEvent = async () => {
    // Say the blast radius out loud. Because a duplicate produces ORDINARY
    // independent rows rather than a linked series, deleting here removes
    // exactly one date and never touches a copy — the admin has to be told
    // that, or they will assume one delete cleaned up the whole run.
    QSwal.fire(
        "Warning",
        'You are going to delete this event.\n\n'
        + 'This removes 1 date — this one. Copies of this event on other dates are '
        + 'separate events and are not affected; delete each of those on its own.',
        'warning'
    )
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (event.value?.id) {
                    await ApiService.delete(`/api/admin/masjids/${masjidStore.masjid?.id}/events/${event.value.id}/`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Event deleted successfully.";
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
                            await eventsStore.fetchMasjidEventsPaginated(1).finally(() => {
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/masjid/events`);
                                });
                            });
                        });
                }
            }
        })
}

</script>

<style scoped>
.event-attribute {
    background-color: var(--cgreen-light);
    border-left: 5px solid var(--cgreen);
    padding: .5rem;
}
</style>

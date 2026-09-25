<template>
    <Teleport to="body">
        <div v-if="show" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="close">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-tags me-2"></i>Tags</h5>
                        <button type="button" class="btn-close" @click="close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">
                            Your own labels for {{ membersTerm.toLowerCase() }}. Tag people from the list or from
                            their record, filter the list by a tag, or send a broadcast to everyone with one.
                            A tag never overrides somebody's email or text-message choices.
                        </p>

                        <form class="d-flex gap-2 mb-3" @submit.prevent="create">
                            <input v-model="newName" class="form-control" maxlength="100" placeholder="New tag name" :disabled="busy">
                            <button type="submit" class="btn btn-success text-nowrap" :disabled="busy || !newName.trim()">Add tag</button>
                        </form>
                        <div v-if="newNameClash" class="small text-danger mb-3">"{{ newNameClash.name }}" already exists.</div>

                        <div v-if="contactsStore.tags.length === 0" class="text-muted small">No tags yet.</div>
                        <ul v-else class="list-group">
                            <li v-for="tag in contactsStore.tags" :key="tag.id" class="list-group-item d-flex align-items-center gap-2">
                                <template v-if="editingId === tag.id">
                                    <input v-model="editName" class="form-control form-control-sm" maxlength="100" :disabled="busy" @keyup.enter="saveRename(tag)">
                                    <button class="btn btn-sm btn-success" :disabled="busy || !editName.trim() || !!editNameClash" @click="saveRename(tag)">Save</button>
                                    <button class="btn btn-sm btn-light" :disabled="busy" @click="editingId = null">Cancel</button>
                                </template>
                                <template v-else>
                                    <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis">{{ tag.name }}</span>
                                    <span class="small text-muted ms-1">{{ tag.contacts_count ?? 0 }}</span>
                                    <div class="ms-auto btn-group btn-group-sm">
                                        <button class="btn btn-outline-secondary" title="Rename" :disabled="busy" @click="startRename(tag)"><i class="bi bi-pencil"></i></button>
                                        <button class="btn btn-outline-danger" title="Delete" :disabled="busy" @click="remove(tag)"><i class="bi bi-trash"></i></button>
                                    </div>
                                </template>
                            </li>
                        </ul>
                        <div v-if="editNameClash" class="small text-danger mt-2">"{{ editNameClash.name }}" already exists.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="close">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import Swal from 'sweetalert2';
import { useContactsStore } from '@/stores/masjid/contactsStore';
import { ContactTag } from '@/core/types/data/masjid-related/ContactTag';
import { clashingTag } from '@/views/dashboard/contacts/contactTags';

/**
 * Create, rename and delete an organisation's contact tags.
 *
 * `changed` fires after every write, so the directory can re-read its rows:
 * a rename or a delete changes the chips on members already on screen.
 */
defineProps<{ show: boolean; membersTerm: string }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed'): void }>();

const contactsStore = useContactsStore();

const busy = ref(false);
const newName = ref('');
const editingId = ref<number | null>(null);
const editName = ref('');

const newNameClash = computed(() => clashingTag(contactsStore.tags, newName.value));
const editNameClash = computed(() => editingId.value === null ? null : clashingTag(contactsStore.tags, editName.value, editingId.value));

function close() {
    editingId.value = null;
    emit('close');
}

/** The server's refusal sentence, verbatim, or a generic one. */
function refusal(e: any, fallback: string): string {
    const data = e?.response?.data;
    return data?.message
        || data?.data?.name_key?.[0]
        || data?.data?.name?.[0]
        || fallback;
}

async function create() {
    if (!newName.value.trim() || newNameClash.value) return;
    busy.value = true;
    try {
        await contactsStore.createTag(newName.value.trim());
        newName.value = '';
        emit('changed');
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Not added', text: refusal(e, 'The tag could not be added.') });
    } finally {
        busy.value = false;
    }
}

function startRename(tag: ContactTag) {
    editingId.value = tag.id;
    editName.value = tag.name;
}

async function saveRename(tag: ContactTag) {
    if (!editName.value.trim() || editNameClash.value) return;
    busy.value = true;
    try {
        await contactsStore.renameTag(tag.id, editName.value.trim());
        editingId.value = null;
        emit('changed');
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Not renamed', text: refusal(e, 'The tag could not be renamed.') });
    } finally {
        busy.value = false;
    }
}

async function remove(tag: ContactTag) {
    const count = tag.contacts_count ?? 0;
    const confirmed = await Swal.fire({
        icon: 'warning',
        title: `Delete "${tag.name}"?`,
        text: count > 0
            ? `It comes off ${count} ${count === 1 ? 'person' : 'people'}. Nobody is deleted.`
            : 'Nobody carries it. Nobody is deleted.',
        showCancelButton: true,
        confirmButtonText: 'Delete tag',
        confirmButtonColor: '#dc3545',
    });
    if (!confirmed.isConfirmed) return;

    busy.value = true;
    try {
        await contactsStore.deleteTag(tag.id);
        emit('changed');
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Not deleted', text: refusal(e, 'The tag could not be deleted.') });
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="position-relative">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input
                class="form-control"
                :class="{ 'is-invalid': !!error }"
                :placeholder="placeholder"
                :value="contact ? contactName(contact) : typed.name"
                @input="onType"
            >
            <button
                v-if="contact"
                type="button"
                class="btn btn-outline-secondary"
                title="Clear"
                @click="clear"
            >
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>

        <!-- Matches from this organization's directory. -->
        <div
            v-if="results.length && !contact"
            class="list-group position-absolute w-100 shadow-sm"
            style="z-index: 5; max-height: 14rem; overflow-y: auto"
        >
            <button
                v-for="person in results"
                :key="person.id"
                type="button"
                class="list-group-item list-group-item-action py-1"
                @click="pick(person)"
            >
                {{ contactName(person) }}
                <small class="text-muted">{{ person.email || person.phone || '' }}</small>
            </button>
        </div>

        <!--
            The free-text half, stated plainly. An admin who does not realise a
            typed name creates a NEW person will type a returning child's name
            and fork their record — which the merge verb then has to undo.
        -->
        <div v-else-if="searched && !contact && typed.name.trim()" class="form-text text-success">
            <i class="bi bi-plus-circle me-1"></i>
            No existing person matches — <strong>{{ typed.name.trim() }}</strong> will be added
            to the directory.
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { Contact } from '@/core/types/data/masjid-related/Contact';

/**
 * Pick an EXISTING person from this organization's directory, or type a name to
 * create a new one — for the manual-registration modal (T-041i).
 *
 * ONE CONTROL, TWO ANSWERS, AND THEY ARE MUTUALLY EXCLUSIVE. Picking somebody
 * sets `contact` and the typed half stops being sent; clearing the pick returns
 * to the typed half. The server refuses a row carrying both, because there is no
 * honest way to resolve "this existing person, but called that": attaching the
 * id would ignore a name the admin typed on purpose, and using the name would
 * ignore the person they picked.
 *
 * THE SEARCH IS THE PARENT'S. This component emits `search` with a callback
 * rather than calling the store itself, so the one place that knows which
 * endpoint and which tenant is the screen, not a picker — and so this stays a
 * control rather than a second data layer over `contacts`.
 *
 * It is a picker, not a directory: the parent caps the result list. A public
 * surface must never get one of these (.claude/rules/section-types.md — "public
 * sections are editorial, never a CRM view").
 */
const props = defineProps<{
    contact: Contact | null;
    typed: { name: string; email: string; phone: string };
    placeholder?: string;
    error?: string;
}>();

const emit = defineEmits<{
    (event: 'update:contact', contact: Contact | null): void;
    (event: 'update:typed', typed: { name: string; email: string; phone: string }): void;
    (event: 'search', term: string, resolve: (people: Contact[]) => void): void;
}>();

const results = ref<Contact[]>([]);
const searched = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;

const contactName = (person: Contact): string =>
    `${person.first_name ?? ''} ${person.last_name ?? ''}`.trim() || '(no name)';

/**
 * Typing REPLACES a pick. An admin who picked Amal and then typed over her name
 * meant to name somebody else; keeping the id would have submitted Amal under
 * the other person's name, which is exactly the both-keys row the server
 * refuses.
 */
const onType = (event: Event): void => {
    const value = (event.target as HTMLInputElement).value;

    if (props.contact) emit('update:contact', null);

    emit('update:typed', { ...props.typed, name: value });

    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        const term = value.trim();

        if (!term) {
            results.value = [];
            searched.value = false;
            return;
        }

        emit('search', term, (people) => {
            results.value = people;
            searched.value = true;
        });
    }, 350);
};

const pick = (person: Contact): void => {
    emit('update:contact', person);
    emit('update:typed', { name: '', email: '', phone: '' });
    results.value = [];
    searched.value = false;
};

const clear = (): void => {
    emit('update:contact', null);
    emit('update:typed', { name: '', email: '', phone: '' });
    results.value = [];
    searched.value = false;
};
</script>

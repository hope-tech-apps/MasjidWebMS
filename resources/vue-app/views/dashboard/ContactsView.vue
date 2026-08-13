<template>
    <div>
        <PageDataContainer
            :title="`${membersTerm} Directory`"
            :paginationOptions="paginationOptions"
            :buttonProps="{ title: 'Add Member', type: 'button', class: 'btn btn-success', disabled: false }"
            @headerButtonClick="openCreateModal"
            @pageChange="pageChange"
        >
            <div class="container w-100">
                <!-- Stats Card -->
                <div class="row mb-4">
                    <div class="col-md-4 col-lg-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="bi bi-people-fill"></i>
                            </div>
                            <div class="stats-content">
                                <div class="stats-label">Total {{ membersTerm }}</div>
                                <div class="stats-value">{{ paginationOptions?.itemsTotal || 0 }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search -->
                <div class="row mb-4">
                    <div class="col-md-8 col-lg-6">
                        <div class="search-box">
                            <i class="bi bi-search search-icon"></i>
                            <input
                                type="text"
                                class="search-input"
                                placeholder="Search by name, email, or phone..."
                                v-model="searchQuery"
                            >
                            <button
                                v-if="searchQuery"
                                class="search-clear-btn"
                                @click="searchQuery = ''"
                                type="button"
                            >
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                    <!--
                        DELETED MEMBERS.

                        A delete here is a SOFT delete, so a mis-click on a
                        congregant record was always meant to be recoverable —
                        and nothing in this application could reach a deleted
                        row, so in practice it was not. That also put the
                        `revoked` row that deleting a member writes beyond every
                        screen, including the access history right below, which
                        is the one thing that answers "who took my access away".

                        Off by default: the directory is the live membership, and
                        deleted members appearing in it unasked would be a
                        different screen.
                    -->
                    <div class="col-md-4 col-lg-6 d-flex align-items-center">
                        <div class="form-check ms-md-3 mt-3 mt-md-0">
                            <input
                                id="contacts-show-deleted"
                                class="form-check-input"
                                type="checkbox"
                                v-model="showDeleted"
                            >
                            <label class="form-check-label small text-muted" for="contacts-show-deleted">
                                Show deleted {{ membersTerm.toLowerCase() }}
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Loading State -->
                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else-if="contacts.length === 0" class="text-center py-5 text-muted">
                    <i class="bi bi-person-x fs-1 d-block mb-3"></i>
                    <p>No {{ membersTerm.toLowerCase() }} yet</p>
                </div>

                <!-- Members Table -->
                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="contact in contacts" :key="contact.id" :class="{ 'opacity-75': contact.deleted_at }">
                                <td>
                                    <strong>{{ contact.first_name }} {{ contact.last_name }}</strong>
                                    <span v-if="contact.deleted_at" class="badge bg-secondary-subtle text-secondary ms-2">
                                        Deleted
                                    </span>
                                </td>
                                <td>
                                    <a v-if="contact.email" :href="`mailto:${contact.email}`" class="text-decoration-none">
                                        {{ contact.email }}
                                    </a>
                                    <span v-else class="text-muted">-</span>
                                </td>
                                <td>
                                    <a v-if="contact.phone" :href="`tel:${contact.phone}`" class="text-decoration-none">
                                        {{ contact.phone }}
                                    </a>
                                    <span v-else class="text-muted">-</span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" @click="viewContact(contact)" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button
                                            v-if="!contact.deleted_at"
                                            class="btn btn-outline-secondary"
                                            @click="openEditModal(contact)"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button
                                            v-if="!contact.deleted_at"
                                            class="btn btn-outline-danger"
                                            @click="confirmDelete(contact)"
                                            title="Delete"
                                        >
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <!--
                                            Restore puts the member back in the
                                            directory and NOTHING else: their
                                            parent portal sign-in stays revoked
                                            until somebody re-opens it
                                            deliberately, because a grant
                                            produced as a side effect of an
                                            undelete is a grant nobody typed.
                                        -->
                                        <button
                                            v-else
                                            class="btn btn-outline-success"
                                            @click="confirmRestore(contact)"
                                            title="Restore"
                                        >
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </PageDataContainer>

        <!-- Create / Edit Modal -->
        <Teleport to="body">
            <div v-if="showFormModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="closeFormModal">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-person-plus me-2"></i>
                                {{ isEditForm ? 'Edit Member' : 'Add New Member' }}
                            </h5>
                            <button type="button" class="btn-close" @click="closeFormModal"></button>
                        </div>
                        <form @submit.prevent="submitForm">
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" v-model.trim="form.first_name" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" v-model.trim="form.last_name" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" v-model.trim="form.email">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Phone</label>
                                        <input type="text" class="form-control" v-model.trim="form.phone">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Notes</label>
                                        <textarea class="form-control" rows="3" v-model.trim="form.notes"></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="closeFormModal" :disabled="saving">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-success" :disabled="saving || !form.first_name || !form.last_name">
                                    <span v-if="saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    {{ isEditForm ? 'Save Changes' : 'Add Member' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- View Details Modal -->
        <Teleport to="body">
            <div v-if="showViewModal && selectedContact" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showViewModal = false">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-person-vcard me-2"></i>
                                Member Details
                                <span v-if="(selectedContact as any).is_placeholder" class="badge bg-warning-subtle text-warning ms-2">Unidentified card</span>
                            </h5>
                            <button type="button" class="btn-close" @click="showViewModal = false"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Name</h6>
                                    <p class="mb-0">{{ selectedContact.first_name }} {{ selectedContact.last_name }}</p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Email</h6>
                                    <p class="mb-0">
                                        <a v-if="selectedContact.email" :href="`mailto:${selectedContact.email}`">{{ selectedContact.email }}</a>
                                        <span v-else class="text-muted">Not provided</span>
                                    </p>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Phone</h6>
                                    <p class="mb-0">
                                        <a v-if="selectedContact.phone" :href="`tel:${selectedContact.phone}`">{{ selectedContact.phone }}</a>
                                        <span v-else class="text-muted">Not provided</span>
                                    </p>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Total giving</h6>
                                    <p class="mb-0 fw-semibold">{{ formatCents((selectedContact as any).giving_total || 0) }}</p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Card last-4 on file</h6>
                                    <p class="mb-0">
                                        <span v-for="c in ((selectedContact as any).cards || [])" :key="c.id" class="badge bg-light text-dark border me-1 font-monospace">{{ c.last4 }}</span>
                                        <span v-if="!((selectedContact as any).cards || []).length" class="text-muted">—</span>
                                    </p>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-12">
                                    <h6 class="text-muted mb-2">Notes</h6>
                                    <p class="mb-0 small" style="white-space: pre-wrap;">{{ selectedContact.notes || '—' }}</p>
                                </div>
                            </div>

                            <!--
                                Parent portal sign-in (T-015d).

                                Three states, never two: "never enabled" and
                                "revoked" are different facts about a family and
                                collapsing them into one "off" would hide that
                                somebody's access was withdrawn. The history
                                below is part of the screen rather than a
                                database question, because this grants a view of
                                a child's records and "it was on" is not an
                                answer to "who turned it on?".

                                Hidden for placeholder card stubs: they name no
                                person, and the server refuses them anyway.
                            -->
                            <div class="row mb-3" v-if="!(selectedContact as any).is_placeholder">
                                <div class="col-12">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="text-muted mb-0">Parent portal sign-in</h6>
                                        <span v-if="familyLogin" class="badge" :class="familyLoginBadgeClass">
                                            {{ familyLoginLabel }}
                                        </span>
                                    </div>

                                    <div v-if="familyLoginLoading" class="text-muted small">Checking…</div>

                                    <template v-else-if="familyLogin">
                                        <p class="mb-1 small">
                                            <span class="text-muted me-1">Sign-in email:</span>
                                            <span v-if="familyLogin.login_email" class="font-monospace">{{ familyLogin.login_email }}</span>
                                            <span v-else class="text-muted">Not set</span>
                                        </p>
                                        <p class="mb-2 small text-muted">
                                            <template v-if="familyLogin.last_login_at">
                                                Last signed in {{ formatDate(familyLogin.last_login_at) }}.
                                            </template>
                                            <template v-else>Has never signed in.</template>
                                        </p>

                                        <!--
                                            SAID BEFORE THE CLICK, and it is the
                                            server's own sentence.

                                            The screen used to offer "Enable
                                            parent portal sign-in" on every
                                            member, so a registrar working down a
                                            school roster reached a nine-year-old,
                                            clicked, typed an address, and only
                                            then learned it was never permitted.
                                            `ineligible_reason` is the string
                                            FamilyAccessService would have
                                            refused with — not a second copy of
                                            the rule in TypeScript that agrees
                                            today, and not a softer preview of a
                                            stricter write.

                                            Shown for an ENABLED member too. When
                                            standing lapses (a guardian edge
                                            removed, a ward deleted) the
                                            credential deliberately keeps working
                                            — see FamilyAccessService — and the
                                            office has to be able to read that
                                            here rather than learn it from a
                                            parent's phone call.
                                        -->
                                        <div v-if="!familyLogin.eligible" class="alert alert-warning py-2 small">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            <strong v-if="familyLogin.state === 'enabled'">
                                                This sign-in is on, but this member no longer qualifies for one.
                                            </strong>
                                            {{ familyLogin.ineligible_reason }}
                                        </div>

                                        <div class="btn-group btn-group-sm mb-2">
                                            <button
                                                type="button"
                                                class="btn btn-outline-success"
                                                @click="openFamilyLoginModal"
                                                :disabled="familyLoginSaving || !familyLogin.eligible"
                                                :title="familyLogin.eligible ? '' : (familyLogin.ineligible_reason ?? '')"
                                            >
                                                <i class="bi bi-key me-1"></i>
                                                {{ familyLogin.state === 'enabled' ? 'Change sign-in email' : (familyLogin.state === 'revoked' ? 'Re-enable sign-in' : 'Enable sign-in') }}
                                            </button>
                                            <button
                                                v-if="familyLogin.state === 'enabled'"
                                                type="button"
                                                class="btn btn-outline-danger"
                                                @click="confirmRevokeFamilyLogin"
                                                :disabled="familyLoginSaving"
                                            >
                                                <i class="bi bi-slash-circle me-1"></i> Revoke
                                            </button>
                                        </div>

                                        <details v-if="familyLogin.events.length" class="small">
                                            <summary class="text-muted" style="cursor: pointer;">
                                                Access history ({{ familyLogin.events.length }})
                                            </summary>
                                            <ul class="list-unstyled mt-2 mb-0">
                                                <!--
                                                    Four verbs, rendered as four.
                                                    A binary "revoked or else
                                                    Enabled" printed the word
                                                    Enabled over a `merged` row —
                                                    an audit trail that mislabels
                                                    a grant is worse than one
                                                    that omits it.
                                                -->
                                                <li v-for="e in familyLogin.events" :key="e.id" class="mb-1">
                                                    <span class="badge me-1" :class="eventBadgeClass(e.action)">{{ eventLabel(e.action) }}</span>
                                                    <span class="font-monospace">{{ e.login_email || '—' }}</span>
                                                    <span class="text-muted"> by {{ e.actor_name }} · {{ formatDateTime(e.created_at) }}</span>
                                                </li>
                                            </ul>
                                        </details>
                                    </template>
                                </div>
                            </div>

                            <h6 class="text-muted mb-2">Giving history</h6>
                            <div class="table-responsive" style="max-height:40vh; overflow-y:auto;">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Date</th><th>Fund</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
                                    <tbody>
                                        <tr v-for="d in ((selectedContact as any).donations || [])" :key="d.id">
                                            <td>{{ formatDate(d.donated_at || d.created_at) }}</td>
                                            <td>{{ d.fund?.name || '—' }}</td>
                                            <td class="text-capitalize">{{ d.source === 'offline' ? (d.payment_method || 'offline') : 'card' }}</td>
                                            <td class="text-end">{{ formatCents(d.charged_amount) }}</td>
                                        </tr>
                                        <tr v-if="!((selectedContact as any).donations || []).length"><td colspan="4" class="text-center text-muted py-3">No giving recorded</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="showViewModal = false">Close</button>
                            <!--
                                MERGE IS FOR ANY DUPLICATE, not only for an
                                unidentified card.

                                This button used to render only on a placeholder,
                                so a registrar looking at two duplicate CHILD rows
                                had no merge affordance at all — while the rules
                                named this verb as the reconciliation for exactly
                                that. The only route to it was to open one of the
                                two and find nothing.

                                The wording follows the record: "Attach to member"
                                is right for a card that names nobody, and "Merge
                                into another member" is right for two rows that
                                are one person.
                            -->
                            <button type="button" class="btn btn-outline-primary" @click="openMerge">
                                <i class="bi bi-person-plus me-1"></i>
                                {{ (selectedContact as any).is_placeholder ? 'Attach to member' : 'Merge into another member' }}
                            </button>
                            <button type="button" class="btn btn-outline-secondary" @click="editFromView">
                                <i class="bi bi-pencil me-1"></i> Edit
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!--
            Enable / re-address parent portal sign-in.

            Its own modal rather than a field on the member form, because it is
            not a field on the member: the four login_* columns are not fillable
            server-side precisely so that no save of a phone number can enable a
            login as a side effect.
        -->
        <Teleport to="body">
            <div v-if="showFamilyLoginModal && selectedContact" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showFamilyLoginModal = false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-key me-2"></i>
                                {{ familyLogin?.state === 'enabled' ? 'Change sign-in email' : 'Enable parent portal sign-in' }}
                            </h5>
                            <button type="button" class="btn-close" @click="showFamilyLoginModal = false"></button>
                        </div>
                        <form @submit.prevent="submitFamilyLogin">
                            <div class="modal-body">
                                <p class="text-muted small">
                                    This lets <strong>{{ selectedContact.first_name }} {{ selectedContact.last_name }}</strong>
                                    sign in and see the records of the children they are listed as a guardian for —
                                    and only those children. They receive a one-time code at this address each time
                                    they sign in; there is no password.
                                </p>

                                <label class="form-label">Sign-in email <span class="text-danger">*</span></label>
                                <input
                                    type="email"
                                    class="form-control"
                                    v-model.trim="familyLoginEmail"
                                    placeholder="parent@example.com"
                                    required
                                >
                                <div class="form-text">
                                    Deliberately separate from the contact email on their record, which is often
                                    imported and shared by a whole household. Use an address that belongs to this
                                    parent alone — two members cannot share one sign-in address.
                                </div>

                                <div v-if="familyLoginError" class="alert alert-danger mt-3 mb-0 py-2 small">
                                    {{ familyLoginError }}

                                    <!--
                                        The way through, offered only after the
                                        server has refused and explained. The
                                        server decides whether it applies (the
                                        holder's access must already have ended);
                                        this checkbox is the operator saying yes
                                        to what they have just read, never a
                                        force switch they could tick in advance.
                                        A holder who can sign in right now is
                                        refused again with it set.
                                    -->
                                    <div v-if="familyLoginCanReassign" class="form-check mt-2">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            id="reassignAddressConfirm"
                                            v-model="familyLoginReassign"
                                        >
                                        <label class="form-check-label" for="reassignAddressConfirm">
                                            Move this sign-in address to
                                            <strong>{{ selectedContact.first_name }} {{ selectedContact.last_name }}</strong>.
                                            The other record loses it, and both halves are written to
                                            <strong>this</strong> member's access history — including where the
                                            address came from, which the other record cannot show once it has
                                            been deleted.
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="showFamilyLoginModal = false" :disabled="familyLoginSaving">
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="btn"
                                    :class="familyLoginReassign ? 'btn-warning' : 'btn-success'"
                                    :disabled="familyLoginSaving || !familyLoginEmail"
                                >
                                    <span v-if="familyLoginSaving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    {{ familyLoginReassign
                                        ? 'Reassign address and enable'
                                        : (familyLogin?.state === 'enabled' ? 'Save address' : 'Enable sign-in') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Attach placeholder card to a member -->
        <Teleport to="body">
            <div v-if="showMergeModal && selectedContact" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showMergeModal = false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ isPlaceholderMerge ? 'Attach card to a member' : 'Merge into another member' }}</h5>
                            <button type="button" class="btn-close" @click="showMergeModal = false"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small">
                                This moves “{{ selectedContact.first_name }} {{ selectedContact.last_name }}”’s giving, card
                                <template v-if="!isPlaceholderMerge">and roster entries </template>
                                onto {{ isPlaceholderMerge ? 'a member' : 'the surviving record' }}, then removes
                                {{ isPlaceholderMerge ? 'the placeholder' : 'this one' }}.
                            </p>

                            <!--
                                A MERGE IS A DE-DUPLICATION, NEVER AN
                                AUTHORIZATION — said before the click, because
                                this was the third door found in this area. The
                                registrar who merged two identical child rows
                                authenticated a de-duplication, and a guardian
                                claim an anonymous form had written rode across
                                onto the real child and opened her behaviour, her
                                ḥifẓ and a safeguarding thread. The server now
                                refuses to let a confirmation follow a row onto a
                                different person; this sentence is so nobody
                                learns that from a support call.
                            -->
                            <div v-if="!isPlaceholderMerge" class="alert alert-info py-2 small">
                                <i class="bi bi-info-circle me-1"></i>
                                Roster entries move across, but this does not vouch for any of them. A
                                <strong>guardian</strong> entry that ends up pointing at a different person
                                goes back to being an unconfirmed claim and opens nothing until somebody
                                confirms it on that group's roster.
                            </div>

                            <!--
                                Said BEFORE the click, not reported after it. The
                                absorbed record is force-deleted, so a live
                                parent sign-in on it ends here; the survivor does
                                not inherit it, deliberately — a credential
                                granted as a side effect of a de-duplication is a
                                grant no administrator typed. The history moves
                                across, so it is still readable afterwards.

                                What this used to say and could not deliver was
                                "enable sign-in on them if they should still have
                                access": the merge also destroyed the guardian
                                edge that permission is derived from, so the
                                instruction led to a 422. The roster entries now
                                move onto the survivor with everything else, and
                                the server checks the survivor's actual
                                eligibility before repeating the promise — so this
                                copy stops asserting it and the response says it.
                            -->
                            <div v-if="familyLogin?.state === 'enabled'" class="alert alert-warning py-2 small">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                This record has parent portal sign-in enabled at
                                <span class="font-monospace">{{ familyLogin.login_email }}</span>.
                                Merging <strong>revokes it</strong> — the member you attach to does not inherit it.
                                Their group roster entries and the access history move across, and this screen
                                will tell you afterwards whether sign-in can be enabled on them.
                            </div>
                            <div class="btn-group w-100 mb-3">
                                <button class="btn" :class="mergeMode==='existing' ? 'btn-success' : 'btn-outline-secondary'" @click="mergeMode='existing'">Existing member</button>
                                <button class="btn" :class="mergeMode==='new' ? 'btn-success' : 'btn-outline-secondary'" @click="mergeMode='new'">New member</button>
                            </div>

                            <div v-if="mergeMode==='existing'">
                                <label class="form-label small text-muted">Search members</label>
                                <input class="form-control mb-2" v-model="mergeSearch" @input="searchMembers" placeholder="Name or email…">
                                <div class="list-group" style="max-height:30vh; overflow-y:auto;">
                                    <button v-for="m in mergeResults" :key="m.id" type="button"
                                        class="list-group-item list-group-item-action d-flex justify-content-between"
                                        :class="{ active: mergeTarget?.id === m.id }" @click="mergeTarget = m">
                                        <span>{{ m.first_name }} {{ m.last_name }}</span>
                                        <small class="text-muted">{{ m.email || '' }}</small>
                                    </button>
                                    <div v-if="!mergeResults.length" class="text-muted small p-2">Type to search…</div>
                                </div>
                            </div>

                            <div v-else class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">First name *</label>
                                    <input class="form-control" v-model="mergeNew.first_name">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">Last name</label>
                                    <input class="form-control" v-model="mergeNew.last_name">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">Email</label>
                                    <input class="form-control" v-model="mergeNew.email">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">Phone</label>
                                    <input class="form-control" v-model="mergeNew.phone">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" @click="showMergeModal = false">Cancel</button>
                            <button class="btn btn-success" :disabled="!canMerge || merging" @click="doMerge">
                                <span v-if="merging" class="spinner-border spinner-border-sm"></span>
                                <span v-else>{{ isPlaceholderMerge ? 'Attach' : 'Merge' }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { ref, onBeforeMount, computed, watch } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { Contact, ContactPayload, FamilyLoginEvent, FamilyLoginStatus } from '@/core/types/data/masjid-related/Contact';
import { useContactsStore } from '@/stores/masjid/contactsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import ApiService from '@/core/services/ApiService';
import Swal from 'sweetalert2';

// Store
const contactsStore = useContactsStore();
const masjidStore = useMasjidStore();

// State
const loading = ref(false);
const saving = ref(false);
const searchQuery = ref('');
const showFormModal = ref(false);
const showViewModal = ref(false);
const isEditForm = ref(false);
const editingId = ref<number | null>(null);
const selectedContact = ref<Contact | null>(null);
/** Include soft-deleted members in the listing. Off by default. */
const showDeleted = ref(false);
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const emptyForm = (): ContactPayload => ({ first_name: '', last_name: '', email: '', phone: '', notes: '' });
const form = ref<ContactPayload>(emptyForm());

// Computed
/** Whom this directory holds in the tenant's own words — "Congregants", "Families". */
const membersTerm = computed<string>(() => masjidStore.term('members'));

const contacts = computed<Contact[]>(() => (contactsStore.contactsPaginated?.data as Contact[]) || []);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!contactsStore.contactsPaginated) return undefined;
    return {
        currentPage: contactsStore.contactsPaginated.current_page,
        itemsTotal: contactsStore.contactsPaginated.total,
        perPage: contactsStore.contactsPaginated.per_page
    };
});

// Lifecycle
onBeforeMount(async () => {
    await loadData();
});

// Debounced search
watch(searchQuery, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
        await loadData(1, searchQuery.value);
    }, 500);
});

// Not debounced: a checkbox is one deliberate click, and waiting half a second
// to honour it reads as a broken control.
watch(showDeleted, async () => {
    await loadData(1, searchQuery.value);
});

// Methods
const loadData = async (page: number = 1, search: string = '') => {
    loading.value = true;
    try {
        await contactsStore.fetchContacts(page, search, showDeleted.value ? 'with' : '');
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to load members.' });
    } finally {
        loading.value = false;
    }
};

const pageChange = async (data: PageChangeData) => {
    await loadData(data.toPage, searchQuery.value);
};

const openCreateModal = () => {
    isEditForm.value = false;
    editingId.value = null;
    form.value = emptyForm();
    showFormModal.value = true;
};

const openEditModal = (contact: Contact) => {
    isEditForm.value = true;
    editingId.value = contact.id;
    form.value = {
        first_name: contact.first_name ?? '',
        last_name: contact.last_name ?? '',
        email: contact.email ?? '',
        phone: contact.phone ?? '',
        notes: contact.notes ?? ''
    };
    showViewModal.value = false;
    showFormModal.value = true;
};

const closeFormModal = () => {
    showFormModal.value = false;
};

const viewContact = async (contact: Contact) => {
    selectedContact.value = contact;   // show immediately with row data
    showViewModal.value = true;
    try {
        const full = await contactsStore.fetchContact(contact.id);   // hydrate cards + giving history
        if (full) selectedContact.value = full;
    } catch (e) { /* keep row-level data */ }

    await loadFamilyLogin(contact.id);
};

// --- Parent portal sign-in (T-015d) ---
//
// The ON-SWITCH for the family realm. Everything the portal needs has existed
// for several slices; nothing in the application ever wrote
// `contacts.login_enabled_at`, so no parent could sign in. These four calls are
// the admin surface for that, and they mirror the server exactly: three states,
// two write verbs, one audit trail.
const familyLogin = ref<FamilyLoginStatus | null>(null);
const familyLoginLoading = ref(false);
const familyLoginSaving = ref(false);
const showFamilyLoginModal = ref(false);
const familyLoginEmail = ref('');
const familyLoginError = ref('');
// Set by the SERVER's refusal (`reassignable`), never by reading the message:
// only one of the five refusals `enable()` can produce has a way through, and
// which one it is must not depend on the wording of a sentence.
const familyLoginCanReassign = ref(false);
const familyLoginReassign = ref(false);

/**
 * The three states, in the words an office uses. Never a boolean.
 *
 * "Not eligible" is a fourth WORD but not a fourth state: it is `never_enabled`
 * plus the server's eligibility answer, and it exists so the compact badge — the
 * thing a registrar scans on a member's record — says which members can hold a
 * sign-in at all. It never overrides `enabled` or `revoked`, which are facts
 * about a grant that happened; a lapsed grant is reported by the warning beside
 * the button instead, where there is room to say why.
 */
const familyLoginLabel = computed<string>(() => {
    if (familyLogin.value && !familyLogin.value.eligible && familyLogin.value.state === 'never_enabled') {
        return 'Not eligible';
    }
    switch (familyLogin.value?.state) {
        case 'enabled': return 'Enabled';
        case 'revoked': return 'Revoked';
        default: return 'Never enabled';
    }
});

const familyLoginBadgeClass = computed<string>(() => {
    switch (familyLogin.value?.state) {
        case 'enabled': return 'bg-success-subtle text-success';
        case 'revoked': return 'bg-danger-subtle text-danger';
        default: return 'bg-secondary-subtle text-secondary';
    }
});

const loadFamilyLogin = async (contactId: number | string) => {
    familyLogin.value = null;
    familyLoginLoading.value = true;
    try {
        familyLogin.value = await contactsStore.fetchFamilyLogin(contactId);
    } catch (e) {
        // A 403 here is an admin without `view contacts` — leave the panel blank
        // rather than inventing a state for it.
        familyLogin.value = null;
    } finally {
        familyLoginLoading.value = false;
    }
};

/**
 * The access history's five verbs. `merged`, `address_released` and
 * `address_claimed` are not grants and must not be badged as one — the previous
 * binary rendering printed "Enabled" over every row that was not a revocation,
 * which on a carried trail meant labelling a merge as somebody handing out
 * access. `address_claimed` is the row that says this member TOOK a sign-in
 * address off another record; without it the panel showed only the grant, and
 * the release half sat on a soft-deleted record no screen can open.
 */
const eventLabel = (action: FamilyLoginEvent['action']): string => {
    switch (action) {
        case 'revoked': return 'Revoked';
        case 'merged': return 'Merged in';
        case 'address_released': return 'Address released';
        case 'address_claimed': return 'Address taken over';
        default: return 'Enabled';
    }
};

const eventBadgeClass = (action: FamilyLoginEvent['action']): string => {
    switch (action) {
        case 'revoked': return 'bg-danger-subtle text-danger';
        case 'merged': return 'bg-info-subtle text-info';
        case 'address_released': return 'bg-warning-subtle text-warning';
        case 'address_claimed': return 'bg-warning-subtle text-warning';
        default: return 'bg-success-subtle text-success';
    }
};

const openFamilyLoginModal = () => {
    // Pre-filled with the CURRENT sign-in address when there is one, and blank
    // otherwise. Deliberately never pre-filled from `contact.email`: that column
    // is imported, frequently a shared household address and verified by nobody,
    // and a one-click default would be how a child's records reach whatever a
    // spreadsheet happened to carry.
    familyLoginEmail.value = familyLogin.value?.login_email ?? '';
    familyLoginError.value = '';
    familyLoginCanReassign.value = false;
    familyLoginReassign.value = false;
    showFamilyLoginModal.value = true;
};

const submitFamilyLogin = async () => {
    if (!selectedContact.value || !familyLoginEmail.value) return;
    familyLoginSaving.value = true;
    familyLoginError.value = '';
    const reassigning = familyLoginReassign.value;
    try {
        familyLogin.value = await contactsStore.enableFamilyLogin(
            selectedContact.value.id,
            familyLoginEmail.value,
            reassigning
        );
        showFamilyLoginModal.value = false;
        familyLoginCanReassign.value = false;
        familyLoginReassign.value = false;
        Swal.fire({
            icon: 'success',
            title: reassigning ? 'Address reassigned' : 'Sign-in enabled',
            text: reassigning
                ? `${familyLogin.value?.login_email} was taken from the previous record and now belongs to this member, who can request a sign-in code with it.`
                : `${familyLogin.value?.login_email} can now request a sign-in code.`,
            timer: reassigning ? 4000 : 2500,
            showConfirmButton: false
        });
    } catch (error: any) {
        // 422 is a REFUSAL with a message written for the person reading it —
        // the address is already used by a named member, or this is a
        // placeholder card stub. Shown in the form, not as a generic failure.
        const data = error?.response?.data;
        familyLoginError.value = data?.message
            ?? (data?.data && typeof data.data === 'object' ? Object.values(data.data).flat().join(' ') : '')
            ?? '';
        if (!familyLoginError.value) {
            familyLoginError.value = 'Could not enable sign-in. Please try again.';
        }
        // The server says whether this particular refusal has a way through. It
        // is never inferred from the wording, and it resets to false on every
        // other refusal so a stale checkbox cannot survive into an unrelated
        // failure.
        familyLoginCanReassign.value = data?.reassignable === true;
        if (!familyLoginCanReassign.value) {
            familyLoginReassign.value = false;
        }
    } finally {
        familyLoginSaving.value = false;
    }
};

const confirmRevokeFamilyLogin = async () => {
    if (!selectedContact.value) return;

    const result = await Swal.fire({
        title: 'Revoke portal access?',
        text: `${selectedContact.value.first_name} ${selectedContact.value.last_name} will be signed out immediately and will not be able to sign in again until access is re-enabled.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, revoke access'
    });

    if (!result.isConfirmed) return;

    familyLoginSaving.value = true;
    try {
        familyLogin.value = await contactsStore.revokeFamilyLogin(selectedContact.value.id);
        Swal.fire({ icon: 'success', title: 'Revoked', text: 'Portal access has been withdrawn.', timer: 2000, showConfirmButton: false });
    } catch (error: any) {
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: error?.response?.data?.message ?? 'Could not revoke access. Please try again.'
        });
    } finally {
        familyLoginSaving.value = false;
    }
};

const editFromView = () => {
    if (selectedContact.value) openEditModal(selectedContact.value);
};

// --- Placeholder → member merge ---
const showMergeModal = ref(false);
const mergeMode = ref<'existing' | 'new'>('existing');
const mergeSearch = ref('');
const mergeResults = ref<any[]>([]);
const mergeTarget = ref<any>(null);
const mergeNew = ref<any>({ first_name: '', last_name: '', email: '', phone: '' });
const merging = ref(false);
let mergeSearchTimer: any = null;

const canMerge = computed(() =>
    mergeMode.value === 'existing' ? !!mergeTarget.value : !!mergeNew.value.first_name);

/**
 * Which merge this is — a card that names nobody, or two rows that are one
 * person. It changes only the WORDS; the verb and everything it moves are the
 * same, which is why there is one modal rather than two that drift.
 */
const isPlaceholderMerge = computed<boolean>(() => !!(selectedContact.value as any)?.is_placeholder);

const openMerge = () => {
    mergeMode.value = 'existing';
    mergeSearch.value = ''; mergeResults.value = []; mergeTarget.value = null;
    mergeNew.value = { first_name: '', last_name: '', email: '', phone: '' };
    showMergeModal.value = true;
};

const searchMembers = () => {
    clearTimeout(mergeSearchTimer);
    mergeSearchTimer = setTimeout(async () => {
        const q = mergeSearch.value.trim();
        if (!q) { mergeResults.value = []; return; }
        const id = masjidStore.masjid?.id;
        const res = await ApiService.get(`/api/admin/masjids/${id}/contacts?search=${encodeURIComponent(q)}&per_page=8` as any);
        // exclude the placeholder itself and other placeholders
        mergeResults.value = (res.data?.data?.data || []).filter((c: any) => c.id !== selectedContact.value?.id && !c.is_placeholder);
    }, 300);
};

const doMerge = async () => {
    if (!selectedContact.value || !canMerge.value) return;
    // Captured BEFORE the reload below clears `selectedContact` — the outcome
    // dialog is worded for the record that has just been absorbed.
    const wasPlaceholderMerge = isPlaceholderMerge.value;
    const id = masjidStore.masjid?.id;
    const payload = new URLSearchParams();
    if (mergeMode.value === 'existing') payload.append('target_contact_id', String(mergeTarget.value.id));
    else {
        payload.append('first_name', mergeNew.value.first_name);
        if (mergeNew.value.last_name) payload.append('last_name', mergeNew.value.last_name);
        if (mergeNew.value.email) payload.append('email', mergeNew.value.email);
        if (mergeNew.value.phone) payload.append('phone', mergeNew.value.phone);
    }
    merging.value = true;
    try {
        const res = await ApiService.post(`/api/admin/masjids/${id}/contacts/${selectedContact.value.id}/merge` as any, payload);
        showMergeModal.value = false;
        showViewModal.value = false;
        await loadData();

        // The merge destroys the absorbed record, and with it any parent portal
        // sign-in it held — deliberately, because the survivor may be nobody's
        // guardian and a credential must never be granted by a side effect. The
        // server says when that happened; saying nothing here would make it the
        // silent loss it used to be.
        const familyLoginOutcome = res.data?.family_login;
        // …and what it did to the ROSTER, which used to be discarded entirely:
        // `carry()` returned {moved, dropped} and the controller threw it away,
        // so a merge that re-opened a guardian claim for confirmation said
        // nothing. A guardianship that stops working is not a detail.
        const rosterOutcome = res.data?.roster;
        const verb = wasPlaceholderMerge ? 'Attached' : 'Merged';

        if (familyLoginOutcome?.access_ended) {
            Swal.fire({
                icon: 'warning',
                title: `${verb} — portal access ended`,
                text: [familyLoginOutcome.message, rosterOutcome?.message].filter(Boolean).join(' '),
            });
        } else if (rosterOutcome?.unconfirmed > 0) {
            Swal.fire({ icon: 'warning', title: `${verb} — a guardian entry needs confirming`, text: rosterOutcome.message });
        } else if (rosterOutcome?.message) {
            Swal.fire({ icon: 'success', title: verb, text: rosterOutcome.message });
        } else {
            Swal.fire({
                icon: 'success',
                title: verb,
                text: wasPlaceholderMerge
                    ? 'The card and its giving were moved to the member.'
                    : 'The record and its giving were moved to the surviving member.',
            });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not attach. Please try again.' });
    } finally { merging.value = false; }
};

const formatCents = (cents: number): string => {
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format((cents ?? 0) / 100); }
    catch (e) { return `$${((cents ?? 0) / 100).toFixed(2)}`; }
};
const formatDate = (iso: string): string => {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? iso : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};
/** Audit entries carry a TIME as well as a date — "who, and when exactly". */
const formatDateTime = (iso: string): string => {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime())
        ? iso
        : d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
};

const submitForm = async () => {
    if (!form.value.first_name || !form.value.last_name) return;
    saving.value = true;
    try {
        if (isEditForm.value && editingId.value !== null) {
            await contactsStore.updateContact(editingId.value, form.value);
        } else {
            await contactsStore.createContact(form.value);
        }
        showFormModal.value = false;
        await loadData(isEditForm.value ? (paginationOptions.value?.currentPage || 1) : 1, searchQuery.value);
        Swal.fire({
            icon: 'success',
            title: isEditForm.value ? 'Saved!' : 'Added!',
            text: isEditForm.value ? 'Member updated successfully.' : 'Member added successfully.',
            timer: 2000,
            showConfirmButton: false
        });
    } catch (error: any) {
        const data = error?.response?.data?.data;
        const text = (data && typeof data === 'object')
            ? Object.values(data).flat().join(' ')
            : (error?.response?.data?.message ?? error?.message ?? 'Failed to save member.');
        Swal.fire({ icon: 'error', title: 'Error!', text });
    } finally {
        saving.value = false;
    }
};

const confirmDelete = async (contact: Contact) => {
    const result = await Swal.fire({
        title: 'Are you sure?',
        // The second sentence is what a delete actually does now, said before
        // the click rather than discovered afterwards: a trashed contact could
        // never sign in (familyLoginIsActive() checks trashed()), so the access
        // always ended here — it just used to end with the record still reading
        // "enabled" and nothing in the history saying who ended it.
        text: `Remove ${contact.first_name} ${contact.last_name} from the directory? `
            + 'Any parent portal sign-in they hold is revoked, and their sign-in address stays '
            + 'reserved to them until it is deliberately reassigned.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    });

    if (result.isConfirmed) {
        try {
            await contactsStore.deleteContact(contact.id);
            await loadData(paginationOptions.value?.currentPage || 1, searchQuery.value);
            Swal.fire({ icon: 'success', title: 'Deleted!', text: 'Member has been removed.', timer: 2000, showConfirmButton: false });
        } catch (error) {
            Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to delete member.' });
        }
    }
};

/**
 * Undelete a member — and say what it does NOT do.
 *
 * The one thing an operator will assume is that restoring the member restores
 * their parent portal sign-in. It does not: the delete revoked it, on the
 * record, and re-opening a credential is a typed, deliberate act everywhere
 * else in this screen. Saying so in the confirmation is the difference between
 * a rule and a surprise.
 */
const confirmRestore = async (contact: Contact) => {
    const result = await Swal.fire({
        title: 'Restore this member?',
        text: `${contact.first_name} ${contact.last_name} will be returned to the directory. `
            + 'Their parent portal sign-in stays revoked — re-enable it from their record if they '
            + 'should have access again.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, restore'
    });

    if (result.isConfirmed) {
        try {
            await contactsStore.restoreContact(contact.id);
            await loadData(paginationOptions.value?.currentPage || 1, searchQuery.value);
            Swal.fire({ icon: 'success', title: 'Restored', text: 'Member is back in the directory.', timer: 2000, showConfirmButton: false });
        } catch (error) {
            Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to restore member.' });
        }
    }
};

// Lock body scroll while any modal is open
watch([showFormModal, showViewModal], ([a, b]) => {
    document.body.style.overflow = (a || b) ? 'hidden' : '';
});
</script>

<style scoped>
.card {
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

/* Stats Card */
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    padding: 1.5rem;
    color: white;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: transform 0.2s, box-shadow 0.2s;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
}

.stats-icon {
    width: 60px;
    height: 60px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    flex-shrink: 0;
}

.stats-content {
    flex: 1;
}

.stats-label {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.stats-value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
}

/* Search Box */
.search-box {
    position: relative;
    width: 100%;
}

.search-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 1.1rem;
    pointer-events: none;
    z-index: 1;
}

.search-input {
    width: 100%;
    padding: 0.75rem 2.75rem 0.75rem 2.75rem;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background-color: #f8f9fa;
}

.search-input:focus {
    outline: none;
    border-color: #667eea;
    background-color: white;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.search-input::placeholder {
    color: #adb5bd;
}

.search-clear-btn {
    position: absolute;
    right: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #6c757d;
    font-size: 0.875rem;
    cursor: pointer;
    padding: 0.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 50%;
    transition: all 0.2s ease;
}

.search-clear-btn:hover {
    background-color: #e9ecef;
    color: #495057;
}

/* Modal */
.modal {
    display: block;
    z-index: 1055;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>

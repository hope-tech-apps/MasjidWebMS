<template>
    <div class="jl container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="mb-1">Jummah Lunch</h3>
                <p class="text-muted mb-0">Set this week's menu and watch orders come in.</p>
            </div>
            <button class="btn btn-success" @click="openCreateMenu">+ New menu</button>
        </div>

        <!-- Menus list -->
        <div v-if="!currentMenu">
            <div v-if="loading" class="text-muted">Loading…</div>
            <div v-else-if="menus.length === 0" class="text-center text-muted py-5 border rounded">
                No menus yet. Create one to start taking Jummah lunch orders.
            </div>
            <div v-else class="row g-3">
                <div v-for="m in menus" :key="m.id" class="col-md-6 col-lg-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <h5 class="card-title mb-1">{{ m.title }}</h5>
                                <span class="badge" :class="statusClass(m.status)">{{ m.status }}</span>
                            </div>
                            <div class="text-muted small mb-2">{{ formatDate(m.service_date) }}</div>
                            <div class="small mb-3">
                                {{ m.items_count ?? 0 }} item(s) · {{ m.orders_count ?? 0 }} order(s)
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-primary" @click="manageMenu(m.id)">Manage</button>
                                <button class="btn btn-sm btn-outline-secondary" @click="openEditMenu(m)">Edit</button>
                                <button v-if="!isLunchStaff" class="btn btn-sm btn-outline-danger" @click="removeMenu(m)">Delete</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Menu detail -->
        <div v-else class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <button class="btn btn-sm btn-link px-0 text-decoration-none" @click="closeMenu">← All menus</button>
                    <h5 class="mb-0">{{ currentMenu.title }} <span class="text-muted small">· {{ formatDate(currentMenu.service_date) }}</span></h5>
                </div>
                <div class="btn-group">
                    <button v-for="s in ['draft','open','closed']" :key="s" class="btn btn-sm"
                        :class="currentMenu.status === s ? statusBtn(s) : 'btn-outline-secondary'"
                        @click="setStatus(s)">{{ s }}</button>
                </div>
            </div>

            <div class="card-body">
                <p v-if="currentMenu.status === 'open'" class="alert alert-success py-2 small">
                    ✅ This menu is <strong>OPEN</strong> — the public order page is live at
                    <code>/jummah-lunch/{{ masjidId }}</code>.
                </p>

                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item"><a class="nav-link" :class="{ active: tab === 'items' }" href="#" @click.prevent="tab = 'items'">Menu items</a></li>
                    <li class="nav-item"><a class="nav-link" :class="{ active: tab === 'orders' }" href="#" @click.prevent="switchToOrders">Orders <span v-if="summary" class="badge bg-secondary">{{ summary.orders }}</span></a></li>
                </ul>

                <!-- Items tab -->
                <div v-if="tab === 'items'">
                    <div class="d-flex justify-content-end mb-2">
                        <button class="btn btn-sm btn-success" @click="openAddItem">+ Add item</button>
                    </div>
                    <div v-if="!currentMenu.items || currentMenu.items.length === 0" class="text-muted text-center py-4">
                        No items yet. Add the first plate.
                    </div>
                    <table v-else class="table align-middle">
                        <thead><tr><th>Item</th><th>Price</th><th>Available</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="it in currentMenu.items" :key="it.id">
                                <td>
                                    <div class="fw-semibold">{{ it.name }}</div>
                                    <div class="text-muted small" v-if="it.description">{{ it.description }}</div>
                                </td>
                                <td>{{ money(it.price_minor) }}</td>
                                <td><span class="badge" :class="it.is_available ? 'bg-success' : 'bg-secondary'">{{ it.is_available ? 'Yes' : 'No' }}</span></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-secondary me-1" @click="openEditItem(it)">Edit</button>
                                    <button class="btn btn-sm btn-outline-danger" @click="removeItem(it)">×</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Orders tab -->
                <div v-else>
                    <!-- Staff order entry: the table after Jummah, a phone call, someone without the link. -->
                    <div class="d-flex justify-content-end mb-2">
                        <button class="btn btn-sm btn-success" :disabled="!orderableItems.length" @click="openAddOrder">+ Add order</button>
                    </div>
                    <!-- Two tiles per row on a phone (volunteers use the board at the table), one row from tablet width. -->
                    <div v-if="summary" class="row g-2 mb-2">
                        <div class="col-6 col-md"><div class="stat"><div class="stat-n">{{ summary.orders }}</div><div class="stat-l">Orders</div></div></div>
                        <div class="col-6 col-md"><div class="stat"><div class="stat-n">{{ summary.items_ordered ?? 0 }}</div><div class="stat-l">Items ordered</div></div></div>
                        <div class="col-6 col-md"><div class="stat"><div class="stat-n">{{ summary.paid_orders }}</div><div class="stat-l">Paid</div></div></div>
                        <div class="col-6 col-md"><div class="stat"><div class="stat-n">{{ money(summary.revenue_paid_minor) }}</div><div class="stat-l">Collected</div></div></div>
                        <div class="col-6 col-md"><div class="stat"><div class="stat-n">{{ money(summary.expected_total_minor) }}</div><div class="stat-l">Expected</div></div></div>
                        <!-- Only worth a tile once someone has actually added something. -->
                        <div class="col-6 col-md" v-if="Number(summary.donations_expected_minor) > 0"><div class="stat"><div class="stat-n">{{ money(summary.donations_paid_minor) }}</div><div class="stat-l">Extra collected</div></div></div>
                    </div>
                    <!-- What the kitchen makes: each item's count on live orders (cancelled excluded). -->
                    <p v-if="summary?.items_by_item?.length" class="small text-muted mb-3" aria-label="Items ordered by item">
                        <template v-for="(it, i) in summary.items_by_item" :key="it.meal_menu_item_id">
                            <span v-if="i" aria-hidden="true"> · </span><span class="text-nowrap"><strong class="text-body">{{ it.quantity }}</strong> × {{ it.item_name }}</span>
                        </template>
                    </p>
                    <div v-if="orders.length === 0" class="text-muted text-center py-4">No orders yet.</div>
                    <div v-else class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>#</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="o in orders" :key="o.id">
                                    <td class="fw-bold">{{ o.order_number }}</td>
                                    <td>
                                        <div>
                                            {{ o.customer_name }}
                                            <span v-if="o.source === 'staff'" class="badge bg-info-subtle text-info-emphasis ms-1"
                                                :title="o.entered_by?.name ? 'Entered by ' + o.entered_by.name : 'Entered on the board'">staff</span>
                                        </div>
                                        <div class="text-muted small">{{ o.customer_phone }}</div>
                                    </td>
                                    <td class="small">{{ itemsLabel(o) }}</td>
                                    <td>
                                        {{ money(o.total_minor) }}
                                        <span v-if="Number(o.donation_minor) > 0" class="badge bg-success-subtle text-success-emphasis ms-1" :title="'Includes ' + money(o.donation_minor) + ' extra'">+{{ money(o.donation_minor) }}</span>
                                        <span v-if="Number(o.fee_covered_minor) > 0" class="badge bg-secondary-subtle text-secondary-emphasis ms-1" :title="'Customer covered ' + money(o.fee_covered_minor) + ' of card fees'">+fee</span>
                                    </td>
                                    <td>
                                        <span class="badge" :class="o.payment_status === 'paid' ? 'bg-success' : 'bg-warning text-dark'">{{ o.payment_status }}</span>
                                        <div class="text-muted small">{{ o.payment_method === 'online' ? 'online' : 'at pickup' }}</div>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm" :value="o.status" @change="setOrderStatus(o, ($event.target as HTMLSelectElement).value)">
                                            <option v-for="s in ['pending','confirmed','ready','picked_up','cancelled']" :key="s" :value="s">{{ s }}</option>
                                        </select>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button v-if="o.payment_status === 'unpaid' && o.status !== 'cancelled' && currentMenu?.allow_online_payment"
                                            class="btn btn-sm btn-outline-primary me-1" @click="openPayLink(o)">Payment link</button>
                                        <button v-if="o.payment_method === 'pickup' && o.payment_status === 'unpaid'"
                                            class="btn btn-sm btn-success" @click="markPaid(o)">Mark paid</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lunch-only logins. Admin surface: a LunchStaff never sees this,
             and the server refuses them the endpoints behind it. -->
        <div v-if="!currentMenu && !isLunchStaff" class="card shadow-sm mt-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong>Who can run the lunch</strong>
                    <div class="text-muted small">
                        These logins reach this board and nothing else — no donations, no member
                        directory, no masjid settings. They can't add or remove each other.
                    </div>
                </div>
                <button class="btn btn-sm btn-success" @click="openAddStaff">+ Give someone access</button>
            </div>
            <div class="card-body">
                <div v-if="staff.length === 0" class="text-muted small">
                    Nobody yet. The people you add here get an email to set their own password.
                </div>
                <table v-else class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Name</th><th>Email</th><th>Phone</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in staff" :key="p.id">
                            <td>
                                {{ p.name }}
                                <span v-if="p.invited" class="badge bg-warning-subtle text-warning-emphasis ms-1"
                                      title="Created, but they haven't signed in yet">Invited</span>
                            </td>
                            <td class="text-muted">{{ p.email }}</td>
                            <td class="text-muted">{{ p.phone ?? '—' }}</td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-secondary me-1" @click="openEditStaff(p)">Edit</button>
                                <button class="btn btn-sm btn-outline-secondary me-1" @click="resendInvite(p)">Re-send invite</button>
                                <button class="btn btn-sm btn-outline-danger" @click="revokeStaff(p)">Remove</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Staff modal -->
        <div v-if="staffModal.show" class="jl-modal">
            <div class="card shadow-lg" style="max-width: 460px; width: 100%;">
                <div class="card-header">{{ staffModal.isEdit ? 'Edit access' : 'Give someone lunch access' }}</div>
                <div class="card-body">
                    <div class="mb-2">
                        <label class="form-label">Name</label>
                        <input v-model="staffModal.form.name" class="form-control" maxlength="120" />
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Email</label>
                        <input v-model="staffModal.form.email" type="email" class="form-control" maxlength="190"
                               :disabled="staffModal.isEdit" />
                        <div class="text-muted small">
                            <template v-if="staffModal.isEdit">
                                The email can't be changed — it's what their sign-in and invite are tied to.
                                Remove the access and issue it again instead.
                            </template>
                            <template v-else>
                                They'll get an email here to set their own password. You never see it.
                            </template>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Phone <span class="text-muted small">(optional)</span></label>
                        <input v-model="staffModal.form.phone" class="form-control" maxlength="32" />
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <button class="btn btn-light" @click="staffModal.show = false">Cancel</button>
                    <button class="btn btn-success" :disabled="savingStaff" @click="saveStaff">
                        {{ savingStaff ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Menu modal -->
        <div v-if="menuModal.show" class="jl-modal">
            <div class="jl-dialog card">
                <div class="card-header"><h5 class="mb-0">{{ menuModal.isEdit ? 'Edit menu' : 'New menu' }}</h5></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label">Title</label><input v-model="menuModal.form.title" class="form-control" maxlength="120" /></div>
                    <div class="mb-2"><label class="form-label">Title — Arabic <span class="text-muted small">(optional)</span></label><input v-model="menuModal.form.title_ar" class="form-control" dir="rtl" maxlength="120" placeholder="العنوان بالعربية" /></div>
                    <div class="mb-2"><label class="form-label">Service date (Friday)</label><input v-model="menuModal.form.service_date" type="date" class="form-control" /></div>
                    <div class="mb-2"><label class="form-label">Ordering closes at <span class="text-muted small">(optional)</span></label><input v-model="menuModal.form.ordering_closes_at_local" type="datetime-local" class="form-control" /><div class="form-text">{{ menuTimezoneLabel }}</div></div>
                    <div class="mb-2"><label class="form-label">Pickup instructions</label><input v-model="menuModal.form.pickup_instructions" class="form-control" maxlength="255" /></div>
                    <div class="mb-2"><label class="form-label">Pickup instructions — Arabic <span class="text-muted small">(optional)</span></label><input v-model="menuModal.form.pickup_instructions_ar" class="form-control" dir="rtl" maxlength="255" placeholder="تعليمات الاستلام بالعربية" /></div>
                    <div class="mb-2">
                        <label class="form-label">Flyer image <span class="text-muted small">(optional)</span></label>
                        <div v-if="menuModal.form.flyer_image_url" class="mb-2 d-flex align-items-center gap-2">
                            <img :src="menuModal.form.flyer_image_url" alt="flyer" style="max-height:120px;border-radius:8px;border:1px solid #eee" />
                            <button type="button" class="btn btn-sm btn-outline-danger" @click="menuModal.form.flyer_image_url = ''">Remove</button>
                        </div>
                        <input type="file" accept="image/*" class="form-control" @change="onFlyerFile" :disabled="uploadingFlyer" />
                        <div v-if="uploadingFlyer" class="text-muted small mt-1">Uploading…</div>
                    </div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" v-model="menuModal.form.allow_online_payment" id="jlaop" /><label class="form-check-label" for="jlaop">Allow pay online (Stripe)</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" v-model="menuModal.form.allow_pay_at_pickup" id="jlapp" /><label class="form-check-label" for="jlapp">Allow pay at pickup</label></div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" v-model="menuModal.form.collect_customer_email" id="jlcce" />
                        <label class="form-check-label" for="jlcce">Ask for an email address</label>
                        <div class="text-muted small">Untick to drop the email field from the order form. Nothing emails customers from it, and paying online still collects an address for the Stripe receipt.</div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" v-model="menuModal.form.allow_donation" id="jlad" />
                        <label class="form-check-label" for="jlad">Offer an optional extra donation</label>
                        <div class="text-muted small">Lets a customer add any amount on top of the food, up to $1,000. It settles on the same payment and is reported separately below — it is not a receipted donation against a fund.</div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" v-model="menuModal.form.allow_fee_coverage" id="jlafc" />
                        <label class="form-check-label" for="jlafc">Offer to cover the card processing fee</label>
                        <div class="text-muted small">Stripe takes 2.9% + 30&cent; out of your balance on an online order — an $8 plate settles at $7.47. This offers the customer the choice to add it so you receive the full amount. Online orders only; pay-at-pickup never touches Stripe.</div>
                    </div>

                    <hr v-if="!isLunchStaff" class="my-3" />
                    <div v-if="!isLunchStaff" class="mb-2">
                        <label class="form-label">Text subscribers when this opens</label>
                        <select v-model="menuModal.form.notify_service_id" class="form-select">
                            <option :value="null">Don't send a text</option>
                            <option v-for="s in services" :key="s.id" :value="s.id">{{ s.title }}</option>
                        </select>
                        <div class="text-muted small">Picks the service people subscribe to on the order form. The text goes out once, the first time this menu becomes Open — reopening it later never sends a second one.</div>
                    </div>
                    <div v-if="!isLunchStaff" class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" v-model="menuModal.form.allow_sms_optin" id="jlsms" :disabled="!menuModal.form.notify_service_id" />
                        <label class="form-check-label" for="jlsms">Ask customers if they want a text next week</label>
                        <div class="text-muted small">
                            Adds an unticked opt-in box to the order form with the required consent wording. Choose a service above first — without one there is nobody to subscribe them to.
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <button class="btn btn-outline-secondary" @click="menuModal.show = false">Cancel</button>
                    <button class="btn btn-success" :disabled="savingMenu" @click="saveMenu">{{ savingMenu ? 'Saving…' : 'Save' }}</button>
                </div>
            </div>
        </div>

        <!-- Add-order modal: an order taken by staff, priced by the server. -->
        <div v-if="orderModal.show" class="jl-modal">
            <div class="jl-dialog card">
                <div class="card-header"><h5 class="mb-0">Add an order</h5></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label" for="jlo-name">Customer name</label><input id="jlo-name" v-model="orderModal.form.customer_name" class="form-control" maxlength="120" /></div>
                    <div class="row g-2 mb-2">
                        <div class="col-sm-6"><label class="form-label" for="jlo-phone">Phone <span class="text-muted small">(optional)</span></label><input id="jlo-phone" v-model="orderModal.form.customer_phone" type="tel" class="form-control" maxlength="32" /></div>
                        <div class="col-sm-6"><label class="form-label" for="jlo-email">Email <span class="text-muted small">(optional)</span></label><input id="jlo-email" v-model="orderModal.form.customer_email" type="email" class="form-control" maxlength="190" /></div>
                    </div>
                    <div class="mb-2">
                        <div class="form-label mb-1">Items</div>
                        <div v-for="it in orderableItems" :key="it.id" class="d-flex align-items-center justify-content-between gap-2 py-2 border-bottom">
                            <div>
                                <div>{{ it.name }}</div>
                                <div class="text-muted small">{{ money(it.price_minor) }}<span v-if="it.max_quantity"> · max {{ it.max_quantity }} per order</span></div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" :aria-label="`One fewer ${it.name}`" :disabled="!(orderModal.qty[it.id] > 0)" @click="bump(it, -1)">−</button>
                                <span class="jlo-qty" aria-live="polite">{{ orderModal.qty[it.id] || 0 }}</span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" :aria-label="`One more ${it.name}`" :disabled="!!it.max_quantity && (orderModal.qty[it.id] || 0) >= Number(it.max_quantity)" @click="bump(it, 1)">+</button>
                            </div>
                        </div>
                    </div>
                    <div class="mb-2"><label class="form-label" for="jlo-notes">Notes <span class="text-muted small">(optional)</span></label><input id="jlo-notes" v-model="orderModal.form.customer_notes" class="form-control" maxlength="500" /></div>
                    <div class="mb-2 small">
                        <div class="form-label fs-6 mb-1">Payment</div>
                        <div v-if="currentMenu?.allow_online_payment" class="text-muted">
                            Paid by card through Stripe, like any online order. Next you can open the payment page on this device or send the link to the customer. The order is marked paid when Stripe confirms it.
                        </div>
                        <div v-else class="alert alert-warning py-2 mb-0" role="alert">
                            Orders added here are paid online through Stripe, and online payment is switched off for this lunch. Switch it on under Edit menu first.
                        </div>
                    </div>
                    <div v-if="allowExtra && orderSubtotal > 0" class="mb-2">
                        <div class="form-label mb-1">Extra on top <span class="text-muted small">(optional; goes to the masjid)</span></div>
                        <div class="d-flex flex-wrap gap-1 mb-1">
                            <button v-for="p in extraPresets" :key="p" type="button" class="btn btn-sm" :class="orderExtraMinor === p ? 'btn-primary' : 'btn-outline-secondary'" @click="setExtra(p)">{{ money(p) }}</button>
                            <button type="button" class="btn btn-sm" :class="orderExtraMinor === 0 ? 'btn-primary' : 'btn-outline-secondary'" @click="setExtra(0)">No extra</button>
                        </div>
                        <div class="input-group input-group-sm jlo-extra">
                            <span class="input-group-text">$</span>
                            <input id="jlo-extra" v-model="orderModal.extraInput" type="number" min="0" :max="maxExtraMinor / 100" step="0.01" inputmode="decimal" class="form-control" placeholder="Other amount" aria-label="Extra on top, in dollars" />
                        </div>
                        <div v-if="extraCapped" class="text-warning small mt-1">The most that can be added here is {{ money(maxExtraMinor) }}.</div>
                    </div>
                    <div v-if="showFeeOffer" class="form-check mb-2">
                        <input id="jlo-fee" v-model="orderModal.coverFees" class="form-check-input" type="checkbox" />
                        <label class="form-check-label" for="jlo-fee">Add {{ money(orderFeeOfferMinor) }} to cover the card processing fee, so the masjid receives the full amount</label>
                    </div>
                    <div v-if="orderExtraMinor > 0 || orderFeeMinor > 0" class="small text-muted mt-2">
                        <div class="d-flex justify-content-between"><span>Food</span><span>{{ money(orderSubtotal) }}</span></div>
                        <div v-if="orderExtraMinor > 0" class="d-flex justify-content-between"><span>Extra</span><span>{{ money(orderExtraMinor) }}</span></div>
                        <div v-if="orderFeeMinor > 0" class="d-flex justify-content-between"><span>Processing fee</span><span>{{ money(orderFeeMinor) }}</span></div>
                    </div>
                    <div class="d-flex justify-content-between fw-semibold mt-2"><span>Total</span><span>{{ money(orderTotal) }}</span></div>
                    <div v-if="orderError" class="alert alert-danger py-2 mt-2 mb-0" role="alert">{{ orderError }}</div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <button class="btn btn-outline-secondary" @click="orderModal.show = false">Cancel</button>
                    <button class="btn btn-success" :disabled="savingOrder || !orderSubtotal || !currentMenu?.allow_online_payment" @click="saveOrder">{{ savingOrder ? 'Adding…' : 'Add order and get payment link' }}</button>
                </div>
            </div>
        </div>

        <!-- Payment page for an order: open it here, or send the link to the customer. -->
        <div v-if="payModal.show" class="jl-modal">
            <div class="jl-dialog card">
                <div class="card-header"><h5 class="mb-0">Payment for order #{{ payModal.orderNumber }}</h5></div>
                <div class="card-body">
                    <p class="mb-2">{{ money(payModal.total) }} for {{ payModal.name }}. Stripe marks the order paid as soon as they pay. The link works for 24 hours; after that, press "Payment link" on the order for a new one.</p>
                    <div class="input-group input-group-sm mb-2">
                        <input class="form-control" :value="payModal.url" readonly aria-label="Payment link" @focus="($event.target as HTMLInputElement).select()" />
                        <button class="btn btn-outline-secondary" type="button" @click="copyPayLink">{{ payModal.copied ? 'Copied' : 'Copy link' }}</button>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <button class="btn btn-outline-secondary" @click="payModal.show = false">Done</button>
                    <a class="btn btn-success" :href="payModal.url" target="_blank" rel="noopener">Open payment page</a>
                </div>
            </div>
        </div>

        <!-- Item modal -->
        <div v-if="itemModal.show" class="jl-modal">
            <div class="jl-dialog card">
                <div class="card-header"><h5 class="mb-0">{{ itemModal.isEdit ? 'Edit item' : 'Add item' }}</h5></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label">Name</label><input v-model="itemModal.form.name" class="form-control" maxlength="120" /></div>
                    <div class="mb-2"><label class="form-label">Name — Arabic <span class="text-muted small">(optional)</span></label><input v-model="itemModal.form.name_ar" class="form-control" dir="rtl" maxlength="120" placeholder="الاسم بالعربية" /></div>
                    <div class="mb-2"><label class="form-label">Description <span class="text-muted small">(optional)</span></label><input v-model="itemModal.form.description" class="form-control" maxlength="500" /></div>
                    <div class="mb-2"><label class="form-label">Description — Arabic <span class="text-muted small">(optional)</span></label><input v-model="itemModal.form.description_ar" class="form-control" dir="rtl" maxlength="500" placeholder="الوصف بالعربية" /></div>
                    <div class="mb-2"><label class="form-label">Price ($)</label><input v-model="itemModal.form.price" type="number" min="0" step="0.01" class="form-control" placeholder="8.00" /></div>
                    <div class="mb-2"><label class="form-label">Max per order <span class="text-muted small">(optional)</span></label><input v-model="itemModal.form.max_quantity" type="number" min="1" class="form-control" /></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" v-model="itemModal.form.is_available" id="jlavail" /><label class="form-check-label" for="jlavail">Available to order</label></div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <button class="btn btn-outline-secondary" @click="itemModal.show = false">Cancel</button>
                    <button class="btn btn-success" :disabled="savingItem" @click="saveItem">{{ savingItem ? 'Saving…' : 'Save' }}</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeMount, reactive, ref } from "vue";
import Swal from "sweetalert2";
import { useJummahLunchStore } from "@/stores/masjid/jummahLunchStore";
import { useMasjidStore } from "@/stores/masjidStore";

const store = useJummahLunchStore();
const masjidStore = useMasjidStore();

const loading = ref(false);
const savingMenu = ref(false);
const savingItem = ref(false);
const uploadingFlyer = ref(false);
const tab = ref<"items" | "orders">("items");

const masjidId = computed(() => masjidStore.masjid?.id);
const menus = computed(() => store.menus);
// Every menu row carries the masjid's timezone; a masjid with no menus yet has
// nothing to read it from, so the label stays generic until the first save.
const menuTimezoneLabel = computed(() => {
    const tz = menus.value.find((m: any) => m.timezone)?.timezone;
    return tz ? `Times are in ${tz.replace(/_/g, " ")}` : "Times are in the masjid's local timezone";
});
const currentMenu = computed(() => store.currentMenu);
const orders = computed(() => store.orders);
const summary = computed(() => store.orderSummary);
const services = computed(() => store.services);
// A LunchStaff reaches the same board through their own realm. Two controls are
// hidden from them because the server will not serve them either: deleting a
// menu is not in their routes at all, and the notify-subscribers picker reads an
// admin-only services endpoint. A button that 401s is worse than no button.
const isLunchStaff = computed(() => store.isLunchStaff());

// ---------------------------------------------------------- lunch-only logins
const staff = computed(() => store.staff);
const savingStaff = ref(false);
const staffModal = reactive<{ show: boolean; isEdit: boolean; id: number | null; form: any }>({
    show: false, isEdit: false, id: null,
    form: { name: "", email: "", phone: "" },
});

function openAddStaff() {
    staffModal.isEdit = false; staffModal.id = null;
    staffModal.form = { name: "", email: "", phone: "" };
    staffModal.show = true;
}
function openEditStaff(p: any) {
    staffModal.isEdit = true; staffModal.id = p.id;
    staffModal.form = { name: p.name, email: p.email, phone: p.phone ?? "" };
    staffModal.show = true;
}
async function saveStaff() {
    savingStaff.value = true;
    try {
        if (staffModal.isEdit && staffModal.id) await store.updateStaff(staffModal.id, staffModal.form);
        else await store.createStaff(staffModal.form);
        staffModal.show = false;
        toast(staffModal.isEdit ? "Access updated" : "Access granted — invitation sent");
    } catch (e) { toastError(e); } finally { savingStaff.value = false; }
}
async function resendInvite(p: any) {
    try { await store.inviteStaff(p.id); toast("Invitation re-sent to " + p.email); }
    catch (e) { toastError(e); }
}
async function revokeStaff(p: any) {
    // Named consequence, not "Are you sure?" — this signs them out immediately.
    if (!confirm(`Remove ${p.name}'s lunch access? They'll be signed out straight away.`)) return;
    try { await store.removeStaff(p.id); toast("Access removed"); }
    catch (e) { toastError(e); }
}

// ------------------------------------------------------- staff-entered orders
// Admins, SuperAdmins and lunch volunteers can add an order here without the
// public link. The total shown is for the person at the table only — the server
// prices the order from the menu and never reads a price from this form.
const savingOrder = ref(false);
const orderError = ref("");
const orderModal = reactive<{ show: boolean; form: any; qty: Record<number, number>; extraInput: string | number; coverFees: boolean }>(
    { show: false, form: {}, qty: {}, extraInput: "", coverFees: false });
const orderableItems = computed(() => (currentMenu.value?.items || []).filter((i: any) => i.is_available));
// What the FOOD costs. Save gates on this, so an extra with no food is never orderable.
const orderSubtotal = computed(() => orderableItems.value.reduce(
    (sum: number, i: any) => sum + (orderModal.qty[i.id] || 0) * Number(i.price_minor || 0), 0));

// The optional extra and the covered card fee, priced exactly as the server will
// (LunchOrderExtras) from the menu's own ceiling and published rate. Only the
// extra in cents and a yes/no are sent; the server recomputes the fee either way.
const allowExtra = computed<boolean>(() => currentMenu.value?.allow_donation !== false);
const maxExtraMinor = computed<number>(() => Number(currentMenu.value?.max_donation_minor ?? 100000));
const extraPresets = [100, 200, 500];
// Rounded, not truncated, so "1.005" cannot silently become 100; "-5" is inert.
// Zero while there is no food: its controls are hidden then, so a figure left
// over from before the plates were removed must not show up in the total.
const orderExtraMinor = computed<number>(() => {
    if (!allowExtra.value || orderSubtotal.value <= 0) return 0;
    const parsed = Number.parseFloat(String(orderModal.extraInput ?? ""));
    if (!Number.isFinite(parsed) || parsed <= 0) return 0;
    return Math.min(Math.round(parsed * 100), maxExtraMinor.value);
});
const extraCapped = computed<boolean>(() => {
    const parsed = Number.parseFloat(String(orderModal.extraInput ?? ""));
    return Number.isFinite(parsed) && Math.round(parsed * 100) > maxExtraMinor.value;
});
function setExtra(minor: number) {
    orderModal.extraInput = minor > 0 ? (minor / 100).toFixed(2) : "";
}
const showFeeOffer = computed<boolean>(() => currentMenu.value?.allow_fee_coverage !== false && orderSubtotal.value > 0);
// What covering the fee WOULD cost, named before anyone ticks the box: the same
// gross-up of food + extra that the server runs.
const orderFeeOfferMinor = computed<number>(() => {
    if (!showFeeOffer.value) return 0;
    const intended = orderSubtotal.value + orderExtraMinor.value;
    if (intended <= 0) return 0;
    const pct = Number(currentMenu.value?.stripe_fee_percentage ?? 0.029);
    const fixed = Number(currentMenu.value?.stripe_fee_fixed_minor ?? 30);
    return Math.max(0, Math.round((intended + fixed) / (1 - pct)) - intended);
});
const orderFeeMinor = computed<number>(() => (orderModal.coverFees ? orderFeeOfferMinor.value : 0));
const orderTotal = computed<number>(() => orderSubtotal.value + orderExtraMinor.value + orderFeeMinor.value);

function openAddOrder() {
    orderModal.form = { customer_name: "", customer_phone: "", customer_email: "", customer_notes: "" };
    orderModal.qty = {};
    orderModal.extraInput = "";
    orderModal.coverFees = false;
    orderError.value = "";
    orderModal.show = true;
}
function bump(it: any, delta: number) {
    const next = Math.max(0, (orderModal.qty[it.id] || 0) + delta);
    orderModal.qty[it.id] = it.max_quantity ? Math.min(next, Number(it.max_quantity)) : next;
}
const payModal = reactive({ show: false, url: "", orderNumber: "", name: "", total: 0, copied: false });
function showPayLink(order: any, url: string) {
    Object.assign(payModal, {
        show: true, url, copied: false,
        orderNumber: order?.order_number ?? "", name: order?.customer_name ?? "", total: Number(order?.total_minor || 0),
    });
}
async function openPayLink(o: any) {
    if (!currentMenu.value) return;
    let url: string;
    try {
        url = await store.paymentLink(currentMenu.value.id, o.id);
    } catch (e: any) {
        Swal.fire({ icon: "warning", title: "No payment page", text: serverReason(e, "Could not create the payment page.") });
        return;
    }
    showPayLink(o, url);
    await refreshOrders();
}
// The server's own reason (already paid, cancelled, went online on another
// device), never axios's "Request failed with status code 422".
function serverReason(e: any, fallback: string): string {
    // The app's own refusals carry the reason in `data`; Laravel's abort() and
    // middleware refusals (e.g. a capability that is switched off) in `message`.
    const body = e?.response?.data;
    if (typeof body?.data === "string" && body.data) return body.data;
    if (typeof body?.message === "string" && body.message) return body.message;
    return e?.message || fallback;
}
// A refresh failure is only that: the action it follows already happened.
async function refreshOrders() {
    if (!currentMenu.value) return;
    try { await store.fetchOrders(currentMenu.value.id); }
    catch { toastError({ message: "Couldn't refresh the orders. Reload the page to see the latest." }); }
}
async function copyPayLink() {
    try { await navigator.clipboard.writeText(payModal.url); payModal.copied = true; }
    catch { payModal.copied = false; }
}
function orderErrorText(e: any): string {
    const data = e?.response?.data?.data;
    if (typeof data === "string") return data;
    if (data && typeof data === "object") return Object.values(data).flat().join(" ");
    return e?.message || "Could not add the order.";
}
async function saveOrder() {
    if (!currentMenu.value) return;
    orderError.value = "";
    if (!String(orderModal.form.customer_name || "").trim()) { orderError.value = "Enter the customer's name."; return; }
    const items = Object.entries(orderModal.qty)
        .filter(([, q]) => Number(q) > 0)
        .map(([id, q]) => ({ item_id: Number(id), quantity: Number(q) }));
    if (!items.length) { orderError.value = "Add at least one item."; return; }
    savingOrder.value = true;
    try {
        const res = await store.createOrder(currentMenu.value.id, {
            ...orderModal.form, items,
            donation_minor: orderExtraMinor.value,
            cover_fees: showFeeOffer.value && orderModal.coverFees,
        });
        orderModal.show = false;
        // Hand over the payment page FIRST. The order exists now; a board refresh
        // that fails on a weak connection must not lose the link.
        if (res?.checkout_url) {
            showPayLink(res.data, res.checkout_url);
            await refreshOrders();
        } else {
            await refreshOrders();
            Swal.fire({ icon: "warning", title: "Order added", text: res?.message || "The payment page could not be created." });
        }
    } catch (e) {
        orderError.value = orderErrorText(e);
    } finally { savingOrder.value = false; }
}

const menuModal = reactive({
    show: false, isEdit: false, id: null as number | null,
    form: emptyMenuForm(),
});
const itemModal = reactive({
    show: false, isEdit: false, id: null as number | null,
    form: emptyItemForm(),
});

function emptyMenuForm() {
    return {
        title: "Jummah Lunch", title_ar: "", service_date: "", ordering_closes_at_local: "",
        pickup_instructions: "Pick up after Jummah in the main hall.", pickup_instructions_ar: "", flyer_image_url: "",
        allow_online_payment: true, allow_pay_at_pickup: true, collect_customer_email: true, allow_donation: true, allow_fee_coverage: true,
        notify_service_id: null, allow_sms_optin: false,
    };
}
function emptyItemForm() {
    return { name: "", name_ar: "", description: "", description_ar: "", price: "", max_quantity: "", is_available: true };
}

function money(minor: number): string {
    return "$" + (Number(minor || 0) / 100).toFixed(2);
}
function formatDate(d: string): string {
    if (!d) return "";
    try {
        return new Date(String(d).slice(0, 10) + "T00:00:00").toLocaleDateString(undefined, { weekday: "short", month: "short", day: "numeric" });
    } catch { return d; }
}
function statusClass(s: string): string {
    return s === "open" ? "bg-success" : s === "closed" ? "bg-secondary" : "bg-warning text-dark";
}
function statusBtn(s: string): string {
    return s === "open" ? "btn-success" : s === "closed" ? "btn-secondary" : "btn-warning";
}
function itemsLabel(o: any): string {
    return (o.items || []).map((i: any) => `${i.quantity}× ${i.item_name}`).join(", ");
}

async function load() {
    loading.value = true;
    try { await store.fetchMenus(); } catch (e) { toastError(e); }
    // The picker's options. Never fatal — fetchServices swallows its own errors.
    store.fetchServices();
    // Admin-only; the store no-ops for a LunchStaff.
    if (!isLunchStaff.value) {
        try { await store.fetchStaff(); } catch { /* the board matters more */ }
    }
    loading.value = false;
}

function openCreateMenu() {
    menuModal.isEdit = false; menuModal.id = null; menuModal.form = emptyMenuForm(); menuModal.show = true;
}
function openEditMenu(m: any) {
    menuModal.isEdit = true; menuModal.id = m.id;
    menuModal.form = {
        title: m.title, title_ar: m.title_ar ?? "", service_date: String(m.service_date ?? "").slice(0, 10),
        // The API's *_local twin, already in the masjid's timezone. Never slice
        // the UTC column here: it renders 3 PM for a menu that closes at 11 AM.
        ordering_closes_at_local: m.ordering_closes_at_local ?? "",
        pickup_instructions: m.pickup_instructions ?? "", pickup_instructions_ar: m.pickup_instructions_ar ?? "", flyer_image_url: m.flyer_image_url ?? "",
        allow_online_payment: !!m.allow_online_payment, allow_pay_at_pickup: !!m.allow_pay_at_pickup,
        // `!== false` so a menu row from before the column existed edits as ON,
        // which is what the database default gives it.
        collect_customer_email: m.collect_customer_email !== false,
        allow_donation: m.allow_donation !== false,
        allow_fee_coverage: m.allow_fee_coverage !== false,
        notify_service_id: m.notify_service_id ?? null,
        allow_sms_optin: m.allow_sms_optin === true,
    };
    menuModal.show = true;
}
async function saveMenu() {
    savingMenu.value = true;
    try {
        // The server reads a naive datetime as the masjid's wall clock and stores
        // UTC, so the local value goes out under the real column name.
        const { ordering_closes_at_local, ...rest } = menuModal.form as any;
        const payload = { ...rest, ordering_closes_at: ordering_closes_at_local || null };
        if (menuModal.isEdit && menuModal.id) await store.updateMenu(menuModal.id, payload);
        else await store.createMenu(payload);
        menuModal.show = false;
        await load();
        if (currentMenu.value && menuModal.id === currentMenu.value.id) await store.fetchMenu(menuModal.id);
        toast("Menu saved");
    } catch (e) { toastError(e); } finally { savingMenu.value = false; }
}
async function onFlyerFile(e: Event) {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    uploadingFlyer.value = true;
    try {
        menuModal.form.flyer_image_url = await store.uploadFlyer(file);
        toast("Flyer uploaded");
    } catch (err) {
        toastError(err);
    } finally {
        uploadingFlyer.value = false;
        input.value = "";
    }
}

async function removeMenu(m: any) {
    const ok = await confirmDelete(`Delete "${m.title}"?`);
    if (!ok) return;
    try { await store.deleteMenu(m.id); await load(); toast("Menu deleted"); } catch (e) { toastError(e); }
}

async function manageMenu(id: number) {
    tab.value = "items";
    try { await store.fetchMenu(id); } catch (e) { toastError(e); }
}
function closeMenu() { store.currentMenu = null as any; }
async function setStatus(s: string) {
    if (!currentMenu.value) return;
    try { await store.updateMenu(currentMenu.value.id, { status: s }); await store.fetchMenu(currentMenu.value.id); await load(); } catch (e) { toastError(e); }
}
async function switchToOrders() {
    tab.value = "orders";
    if (currentMenu.value) { try { await store.fetchOrders(currentMenu.value.id); } catch (e) { toastError(e); } }
}

function openAddItem() { itemModal.isEdit = false; itemModal.id = null; itemModal.form = emptyItemForm(); itemModal.show = true; }
function openEditItem(it: any) {
    itemModal.isEdit = true; itemModal.id = it.id;
    itemModal.form = { name: it.name, name_ar: it.name_ar ?? "", description: it.description ?? "", description_ar: it.description_ar ?? "", price: (Number(it.price_minor) / 100).toFixed(2), max_quantity: it.max_quantity ?? "", is_available: !!it.is_available };
    itemModal.show = true;
}
async function saveItem() {
    if (!currentMenu.value) return;
    savingItem.value = true;
    const payload = {
        name: itemModal.form.name,
        name_ar: itemModal.form.name_ar,
        description: itemModal.form.description,
        description_ar: itemModal.form.description_ar,
        price_minor: Math.round(Number(itemModal.form.price || 0) * 100),
        max_quantity: itemModal.form.max_quantity === "" ? null : Number(itemModal.form.max_quantity),
        is_available: itemModal.form.is_available,
    };
    try {
        if (itemModal.isEdit && itemModal.id) await store.updateItem(currentMenu.value.id, itemModal.id, payload);
        else await store.addItem(currentMenu.value.id, payload);
        itemModal.show = false;
        await store.fetchMenu(currentMenu.value.id);
        toast("Item saved");
    } catch (e) { toastError(e); } finally { savingItem.value = false; }
}
async function removeItem(it: any) {
    if (!currentMenu.value) return;
    const ok = await confirmDelete(`Remove "${it.name}"?`);
    if (!ok) return;
    try { await store.deleteItem(currentMenu.value.id, it.id); await store.fetchMenu(currentMenu.value.id); toast("Item removed"); } catch (e) { toastError(e); }
}

async function markPaid(o: any) {
    if (!currentMenu.value) return;
    try {
        await store.markOrderPaid(currentMenu.value.id, o.id);
        toast("Marked paid");
    } catch (e: any) {
        Swal.fire({ icon: "warning", title: "Not marked paid", text: serverReason(e, "Could not mark it paid.") });
    }
    // Either way, show the order's real state: a refused button may be stale.
    await refreshOrders();
}
async function setOrderStatus(o: any, status: string) {
    if (!currentMenu.value || status === o.status) return;
    try { await store.updateOrderStatus(currentMenu.value.id, o.id, status); await store.fetchOrders(currentMenu.value.id); } catch (e) { toastError(e); }
}

function toast(title: string) {
    Swal.fire({ toast: true, position: "top-end", icon: "success", title, showConfirmButton: false, timer: 1800 });
}
function toastError(e: any) {
    // The server's own words (e.g. "Friday lunch ordering is not switched on for
    // this organisation."), not axios's "Request failed with status code 403".
    Swal.fire({ toast: true, position: "top-end", icon: "error", title: serverReason(e, "Something went wrong"), showConfirmButton: false, timer: 4000 });
}
async function confirmDelete(text: string): Promise<boolean> {
    const r = await Swal.fire({ title: text, icon: "warning", showCancelButton: true, confirmButtonText: "Delete", confirmButtonColor: "#c0392b" });
    return r.isConfirmed;
}

onBeforeMount(load);
</script>

<style scoped>
/* A dialog taller than the window must still reach its Save button: the
   dialog is capped to the viewport with its header and footer pinned, and the
   body scrolls. The overlay scrolls too, as a fallback on very short screens. */
.jl-modal { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: flex-start; justify-content: center; padding: 4vh 12px; z-index: 1080; overflow-y: auto; }
.jl-dialog { width: 100%; max-width: 460px; max-height: 92vh; max-height: calc(100dvh - 8vh); display: flex; flex-direction: column; }
.jl-dialog > .card-body { overflow-y: auto; min-height: 0; }
.jlo-extra { max-width: 180px; }
.stat { background: #f6f8fa; border-radius: 10px; padding: 12px; text-align: center; }
.stat-n { font-size: 20px; font-weight: 700; color: #0c3d2b; }
.stat-l { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: .03em; }
.nav-tabs .nav-link { cursor: pointer; }

.jlo-qty { min-width: 1.75rem; text-align: center; font-weight: 600; font-variant-numeric: tabular-nums; }
</style>

// Sign-up forms and their submissions. Mirrors App\Models\Form and
// App\Models\FormResponse plus the hand-built serializers in FormsController /
// FormResponsesController.
//
// MONEY WARNING: `amount_due` is a decimal:2 column, so Laravel serializes it as a
// DOLLAR string ("150.00") — it is NOT integer minor units like Donation's *_amount
// fields. Never run it through formatCents / never divide it by 100.

/** Workflow states an admin can move a response through (FormResponse::STATUSES). */
export type FormResponseStatus = 'new' | 'confirmed' | 'waitlisted' | 'cancelled';

/**
 * How a registration was paid (FormResponse::METHODS): `online` is hosted Stripe
 * Checkout, which only the signed webhook marks paid; `cash` is a staff code at the gate
 * or an admin taking cash at the table; `external` is paid somewhere else (the Wix
 * fallback), marked by an admin.
 */
export type FormPaymentMethod = 'online' | 'cash' | 'external';

/** payment_status on a row with a money leg (FormResponse::PAYMENT_STATUSES). */
export type FormPaymentStatus = 'unpaid' | 'paid';

/**
 * The list's payment filter (IndexFormResponsesRequest::PAYMENT_FILTERS). paid / unpaid /
 * settled read the way FormResponse::isSettled() does; the rest are a METHOD, so `online`
 * is every card registration, paid or not.
 */
export type FormPaymentFilter = 'paid' | 'unpaid' | 'settled' | FormPaymentMethod;

export type FormCollectedFilter = 'yes' | 'no';

/** Who did something to a row, as the API names them: an id and a name, never more. */
export type FormResponsePerson = { id: number; name: string | null };

/** Columns the API will sort on (IndexFormResponsesRequest::SORTABLE). */
export type FormResponseSortColumn =
    'submitted_at' |
    'respondent_name' |
    'respondent_email' |
    'status' |
    'entry_count' |
    'amount_due';

export type FormResponseSortDirection = 'asc' | 'desc';

/** GET /forms/options — the lightweight payload behind the form picker. */
export type FormOption = {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    response_count: number;
};

/**
 * One question from the form's schema, as the server flattened it for tabular output.
 *
 * A repeatable section becomes ONE column per field rather than one per entry (the
 * admin table cannot grow a column per attendee); for those, `key` is
 * `<sectionId>.<fieldName>` and `section` carries the section's title.
 */
export type FormResponseColumn = {
    key: string;
    label: string;
    section: string | null;
    repeatable: boolean;
    field: string;
};

/**
 * A row in the responses list.
 *
 * Deliberately carries NO `data`: the full submission is only sent for a single
 * response, so a 300-row page is not megabytes of PII. Use FormResponseDetail.
 */
export type FormResponseRow = {
    id: number;
    form_id: number;
    respondent_name: string | null;
    respondent_email: string | null;
    respondent_phone: string | null;
    entry_count: number;
    amount_due: string | null;
    status: FormResponseStatus;
    admin_notes: string | null;
    submitted_at: string | null;

    // The money leg and the door (DECISIONS.md 2026-09-11), FormResponsesController::
    // serialize(). payment_state and settled are null on a form not set up to take
    // payment (meta.payment.enabled false), and the screen shows no payment column there.
    uuid?: string | null;
    payment_method?: FormPaymentMethod | null;
    payment_status?: FormPaymentStatus | null;
    /** 'paid'; 'unpaid' (a Wix-fallback row with no money leg included); null when nothing was owed. */
    payment_state?: FormPaymentStatus | null;
    /** Paid, or nothing was ever owed: what "Mark collected" needs. */
    settled?: boolean | null;
    currency?: string | null;
    /** Integer CENTS, unlike amount_due. */
    amount_due_minor?: number | null;
    fee_covered_minor?: number;
    total_minor?: number | null;
    paid_at?: string | null;
    /** An unpaid card registration whose Stripe page has been opened: it may still be paid. */
    card_page_opened?: boolean;
    /** Whose cash this is. Never the code itself. */
    staff_code?: { id: number; holder_name: string; code_hint: string } | null;
    marked_paid_by?: FormResponsePerson | null;
    collected_at?: string | null;
    collected_by?: FormResponsePerson | null;
    status_changed_at?: string | null;
    status_changed_by?: FormResponsePerson | null;
};

/**
 * A file uploaded with a submission (a careers form's résumé).
 *
 * There is NO public URL for one. `download_url` points back at the authenticated
 * admin endpoint, which re-checks the tenant before streaming anything off the
 * private disk — so it must be fetched with the bearer token, never dropped into an
 * <a href>.
 */
export type FormResponseAttachment = {
    id: number;
    field: string;
    file_name: string;
    mime_type: string;
    size_bytes: number;
    uploaded_at: string | null;
    download_url: string;
};

/** The single-response payload: the row plus the full submitted answers. */
export type FormResponseDetail = FormResponseRow & {
    data: Record<string, any> | null;
    /** Absent on a form with no file questions, which is most of them. */
    attachments?: FormResponseAttachment[];
};

/** The `meta` block served alongside the paginated list. */
export type FormResponsesMeta = {
    form: {
        id: number;
        name: string;
        response_count: number;
        capacity: number | null;
    };
    columns: FormResponseColumn[];
    statuses: FormResponseStatus[];
    sortable: FormResponseSortColumn[];
    /** The door's filters. Absent from an API older than the festival build. */
    payment_filters?: FormPaymentFilter[];
    collected_filters?: FormCollectedFilter[];
    payment?: FormResponsesPaymentMeta;
};

/** meta.payment: whether this form shows a money leg at all, and its codes for the filter. */
export type FormResponsesPaymentMeta = {
    /** Form::hasPaymentSettings(). The payment columns, filters and actions show only when true. */
    enabled: boolean;
    charges_fee: boolean;
    online: boolean;
    staff_codes: boolean;
    codes: { id: number; holder_name: string; code_hint: string; revoked: boolean }[];
};

/** One column of the attendee roster (FormRoster::columns()). */
export type FormRosterColumn = { key: string; label: string; type: string };

/** The roster's head count (FormRoster::summary()). */
export type FormRosterSummary = {
    people: number;
    submissions: number;
    breakdowns: { field: string; label: string; options: { value: string; label: string; count: number }[] }[];
};

/**
 * The `meta` block served alongside the attendee roster (FormResponsesController::roster()).
 * It carries the same door filters and payment block as the list's, for the form it was
 * asked about, so the roster never borrows them from a list read for another form.
 */
export type FormRosterMeta = {
    form: { id: number; name: string; capacity: number | null };
    columns: FormRosterColumn[];
    summary: FormRosterSummary;
    statuses: FormResponseStatus[];
    sortable: string[];
    /** Absent from an API older than the festival build. */
    payment_filters?: FormPaymentFilter[];
    collected_filters?: FormCollectedFilter[];
    payment?: FormResponsesPaymentMeta;
};

/**
 * Every server-side filter the list honours. The CSV export is handed the exact same
 * object, so what the admin sees is what they download.
 */
export type FormResponseFilters = {
    q: string;
    status: FormResponseStatus | '';
    from: string;
    to: string;
    /** The door's filters; '' means any. Offered only when meta.payment.enabled. */
    payment: FormPaymentFilter | '';
    collected: FormCollectedFilter | '';
    staff_code_id: number | '';
    sort: FormResponseSortColumn;
    direction: FormResponseSortDirection;
};

/**
 * The only two editable fields. The submission itself is a record of what somebody
 * actually agreed to (waivers, medical authorisations) and the API refuses to rewrite
 * it — corrections belong in admin_notes.
 */
export type FormResponseUpdatePayload = {
    /**
     * Sent only when it changes. Saying "cancelled" again is not a no-op: the server asks
     * Stripe about the card payment page again, closes it if it is still open, and answers
     * with `card_page`.
     */
    status?: FormResponseStatus;
    admin_notes?: string;
};

/**
 * What saying "cancelled" did about a registration's card payment page
 * (FormResponsesController::closePageOfCancelled()):
 *  - closed:         the page was open, and is now closed.
 *  - paid_on_stripe: the card has paid for it, or had just paid; cancelling refunds nothing.
 *  - unconfirmed:    Stripe did not confirm the page is closed, so it may still take a payment.
 *  - none:           no card payment page is on record for it.
 *  - unchecked:      a page is on record, but the organisation has no Stripe account on
 *                    record to ask about it. The server sends no message; the screen words it.
 */
export const FORM_CARD_PAGES = ['closed', 'paid_on_stripe', 'unconfirmed', 'none', 'unchecked'] as const;
export type FormCardPage = typeof FORM_CARD_PAGES[number];

/**
 * What a triage save or a door action answers: the row as it now stands, and the
 * server's `message` and whether it is a `warning` the admin must act on. A triage save
 * carries a message only when there is something to say (a cancelled card registration
 * whose page was, or could not be, closed, or one the card has paid for).
 */
export type FormResponseActionResult = {
    data: FormResponseDetail;
    message: string | null;
    warning: boolean;
    /**
     * Only on the answer to a triage save saying "cancelled"; null on every other answer.
     * The screen words a cancel's answer from this, never from what it remembers.
     */
    card_page: FormCardPage | null;
};

/** One group's figures in the cash totals: integer CENTS and counts. */
export type FormCashFigures = {
    submissions: number;
    people: number;
    cash_minor: number;
    cancelled_submissions: number;
    cancelled_cash_minor: number;
    /** cash_minor + cancelled_cash_minor: it does not move when a row is cancelled. */
    taken_minor: number;
};

export type FormCashHolder = FormCashFigures & {
    /** code: entries made at the gate with a staff code; admin: cash taken at the table. */
    kind: 'code' | 'admin' | 'unattributed';
    staff_code_id: number | null;
    user_id: number | null;
    holder_name: string;
    code_hint: string | null;
    revoked: boolean;
    /** The code's lifetime use count, which ignores the filters. Null for an admin's line. */
    use_count: number | null;
};

export type FormOtherPaidFigures = {
    submissions: number;
    people: number;
    total_minor: number;
    cancelled_submissions: number;
    cancelled_total_minor: number;
    taken_minor: number;
};

/** GET …/responses/cash-totals → data (App\Support\FormCashTotals::for()), over the list's filters. */
export type FormCashTotals = {
    currency: string;
    holders: FormCashHolder[];
    totals: FormCashFigures;
    other_paid: { online: FormOtherPaidFigures; external: FormOtherPaidFigures };
};

/**
 * One staff code, as the codes panel shows it (FormStaffCodesController::serialize()).
 * Never the code, its digest, or the whole id of the phone it is bound to.
 */
export type FormStaffCode = {
    id: number;
    form_id: number;
    holder_name: string;
    /** The code's last two characters, for telling codes apart. */
    code_hint: string;
    /** ISO 8601 on the masjid's clock, with its offset. */
    expires_at: string | null;
    timezone: string;
    timezone_assumed: boolean;
    expired: boolean;
    revoked_at: string | null;
    revoked_by: FormResponsePerson | null;
    usable: boolean;
    device_bound: boolean;
    /** The last four characters of the bound phone's id, and only for a long id. */
    bound_device_hint: string | null;
    bound_at: string | null;
    /**
     * How many phones have claimed the code: each claim counts one, including the
     * claim that follows a reset-device release. A release itself does not count.
     */
    binding_count: number;
    binding_released_at: string | null;
    binding_released_by: FormResponsePerson | null;
    use_count: number;
    last_used_at: string | null;
    created_at: string | null;
    created_by: FormResponsePerson | null;
    submissions: number;
    people: number;
    cash_minor: number;
    cancelled_submissions: number;
    cancelled_cash_minor: number;
};

/** The ONE answer that carries the plaintext: store()'s 201. Show it once, then drop it. */
export type FormStaffCodeIssued = FormStaffCode & { code: string };

export type FormStaffCodesMeta = {
    /** The IANA zone every instant in the panel is stated in. */
    timezone: string;
    /** True when the masjid never set one and America/New_York was assumed. */
    timezone_assumed: boolean;
    /** settings.payment.eventDate as saved, or null when the form names no event day. */
    event_date: string | null;
    /** What a code added now would expire at. Null means the admin must choose a day. */
    default_expires_at: string | null;
    /** Form::takesStaffCodes(): switched on AND the form has a price. */
    staff_codes_enabled: boolean;
};

// ============================================================================
// The form DEFINITION — what the builder edits.
//
// Everything below mirrors App\Support\FormSchema::FIELD_TYPES and
// App\Rules\ValidFormSchema. A schema that does not satisfy these shapes is rejected
// when the form is saved, so the builder mirrors the same rules client-side and shows
// them as warnings before an admin ever hits Save.
// ============================================================================

export type FormFieldType =
    'text' |
    'email' |
    'tel' |
    'number' |
    'date' |
    'textarea' |
    'select' |
    'radio' |
    'checkbox' |
    'checkboxGroup' |
    'file';

/** Types whose answer must be one of the field's declared options. */
export const CHOICE_FIELD_TYPES: FormFieldType[] = ['select', 'radio', 'checkboxGroup'];

/**
 * A field name becomes a key in the submitted payload and an input id, so the backend
 * (ValidFormSchema::NAME_PATTERN) accepts only this shape. Section ids share it.
 */
export const FORM_IDENTIFIER_PATTERN = /^[A-Za-z][A-Za-z0-9_]*$/;

export type FormFieldOption = {
    value: string;
    label: string;
    detail?: string | null;
};

/**
 * The one conditional the backend understands: require this field when ANY row of a
 * repeatable section holds a number below `value` — the camp form's "guardian name is
 * required if any attendee is under 18".
 */
export type FormFieldConditional = {
    rule: 'anyEntryUnder';
    section: string;
    field: string;
    value: number;
};

export type FormField = {
    name: string;
    label: string;
    type: FormFieldType;
    required?: boolean;
    help?: string | null;
    placeholder?: string | null;
    autocomplete?: string | null;
    min?: number | null;
    max?: number | null;
    options?: FormFieldOption[];
    /** Long legal copy the renderer hides behind a "read full text" disclosure. */
    bodyText?: string | null;
    requiredIf?: FormFieldConditional | null;
};

export type FormSchemaSection = {
    id: string;
    title?: string | null;
    description?: string | null;
    /**
     * At most ONE section per form may repeat — entry counting, capacity and the fee
     * total are all defined in terms of that single section.
     */
    repeatable?: boolean;
    minEntries?: number | null;
    maxEntries?: number | null;
    addButtonLabel?: string | null;
    fields: FormField[];
};

export type FormSchemaDefinition = {
    sections: FormSchemaSection[];
};

/**
 * Which question feeds each searchable column on form_responses. The backend rejects a
 * slot naming a question the form does not have, so the builder only offers real ones.
 */
export type FormIdentityMap = {
    /** One question, or several joined with a space (first + last name). */
    name?: string | string[] | null;
    email?: string | string[] | null;
    phone?: string | string[] | null;
};

/** A date-stepped price: early bird, then standard. */
export type FormFeeTier = {
    amount: number;
    /** INCLUSIVE, zero-padded 'YYYY-MM-DD'. Absent on the last, open-ended tier. */
    until?: string | null;
    label?: string | null;
    /** Stored only when StoreFormRequest::settingsRules() names it (see FormSettings). */
    [key: string]: unknown;
};

/**
 * `perEntryOfSection` must name a REPEATABLE section; the amount is then multiplied by
 * the number of rows submitted. Null means one flat fee per submission.
 *
 * `amount` is optional when `tiers` carry the price (the festival form has no flat
 * amount at all); Form::feeRule() takes today's tier first and the amount only when no
 * tier applies.
 */
export type FormFeeRule = {
    amount?: number | null;
    currency?: string | null;
    perEntryOfSection?: string | null;
    tiers?: FormFeeTier[] | null;
    /** Stored only when StoreFormRequest::settingsRules() names it (see FormSettings). */
    [key: string]: unknown;
};

/**
 * settings.payment (DECISIONS.md 2026-09-11). ABSENT on every form whose admin never set
 * up payment, and it must stay absent there: its presence alone
 * (Form::hasPaymentSettings()) puts the payment columns in both CSVs and the payment
 * badge on the list. So the builder writes it only once card payment or staff codes is
 * switched on (an Event date alone never does), and keeps it on a form that loaded with it.
 */
export type FormPaymentSettings = {
    online?: boolean;
    staffCodes?: boolean;
    allowFeeCoverage?: boolean;
    /** 'YYYY-MM-DD'. Staff codes default to expiring at midnight at the end of it. */
    eventDate?: string | null;
    /** Stored only when StoreFormRequest::settingsRules() names it (see FormSettings). */
    [key: string]: unknown;
};

/** Form::WHATSAPP_URL_PATTERN, the one definition of a group link: a chat.whatsapp.com invite. */
export const FORM_WHATSAPP_URL_PATTERN = /^https:\/\/chat\.whatsapp\.com\/[A-Za-z0-9]{10,64}$/;

export type FormSettings = {
    submitButtonLabel?: string | null;
    successTitle?: string | null;
    successBody?: string | null;
    successNextSteps?: string[];
    notifyEmails?: string[];
    /** Absent means on — the submitter gets a copy of what they sent. */
    confirmationEmail?: boolean;
    /** Shown beside the total on that copy: when payment is due, card surcharges. */
    paymentNote?: string | null;
    intro?: string | null;
    identity?: FormIdentityMap;
    fee?: FormFeeRule | null;
    payment?: FormPaymentSettings | null;
    /** A chat.whatsapp.com invite, handed out only once a registration is settled. */
    whatsappUrl?: string | null;
    whatsappLabel?: string | null;
    /**
     * A key this SPA does not edit is sent back by a save as it was loaded, and stored only
     * when StoreFormRequest::settingsRules() names it. The builder's PUT and form:import
     * both store the validated settings, so a key no rule names survives neither door, and
     * the save still reports success. A key that must survive needs a rule there.
     */
    [key: string]: unknown;
};

/** The admin shape of a form — FormsController::serialize(). */
export type Form = {
    id: number;
    masjid_id: number;
    name: string;
    slug: string;
    description: string | null;
    schema: FormSchemaDefinition;
    settings: FormSettings | null;
    is_active: boolean;
    opens_at: string | null;
    closes_at: string | null;
    capacity: number | null;
    response_count: number;
    /** Server-computed: whether the form is taking submissions right now, and why not. */
    accepting: boolean;
    closed_reason: string | null;
    created_at: string;
    updated_at: string;
};

/**
 * What the builder submits. masjid_id is stamped from the route, never sent, and
 * response_count is guarded server-side.
 */
export type FormPayload = {
    name: string;
    slug: string;
    description: string | null;
    schema: FormSchemaDefinition;
    settings: FormSettings;
    is_active: boolean;
    opens_at: string | null;
    closes_at: string | null;
    capacity: number | null;
};

/** One entry of the builder's palette — GET /forms/field-types. */
export type FormFieldTypeInfo = {
    value: FormFieldType;
    label: string;
    has_options: boolean;
    /**
     * Present only on `file`. The server is the authority on what may be uploaded
     * (config('forms.attachments')), so the builder reads the limits rather than
     * restating them — a stale copy here would promise something the submit
     * endpoint then rejects.
     */
    upload?: {
        mime_types: string[];
        max_size_kb: number;
        /** A file question needs one upload per row, which the payload cannot carry. */
        allowed_in_repeatable: boolean;
    } | null;
};

/**
 * Fallback palette so the builder still works when /forms/field-types is unreachable.
 * Same vocabulary and labels the endpoint serves (FormsController::fieldTypes).
 */
export const FORM_FIELD_TYPES: FormFieldTypeInfo[] = [
    { value: 'text', label: 'Short text', has_options: false },
    { value: 'email', label: 'Email address', has_options: false },
    { value: 'tel', label: 'Phone number', has_options: false },
    { value: 'number', label: 'Number', has_options: false },
    { value: 'date', label: 'Date', has_options: false },
    { value: 'textarea', label: 'Long text', has_options: false },
    { value: 'select', label: 'Dropdown', has_options: true },
    { value: 'radio', label: 'Choose one', has_options: true },
    { value: 'checkbox', label: 'Single checkbox', has_options: false },
    { value: 'checkboxGroup', label: 'Choose any', has_options: true },
    { value: 'file', label: 'File upload', has_options: false },
];

/**
 * Turn a human label into an identifier the backend accepts ("Full name" -> fullName).
 *
 * The builder keeps this in sync with the label until an admin edits the identifier by
 * hand — it stays editable because renaming a question after responses exist would
 * orphan every answer already stored under the old key.
 */
export function deriveFormIdentifier(label: string, fallback = 'field'): string {
    const words = (label || '')
        .normalize('NFKD')
        .replace(/[^A-Za-z0-9]+/g, ' ')
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    if (words.length === 0) {
        return fallback;
    }

    const camel = words
        .map((word, index) => index === 0
            ? word.charAt(0).toLowerCase() + word.slice(1)
            : word.charAt(0).toUpperCase() + word.slice(1))
        .join('');

    // A leading digit is not a legal identifier — prefix rather than drop the word.
    return FORM_IDENTIFIER_PATTERN.test(camel)
        ? camel
        : fallback + camel.charAt(0).toUpperCase() + camel.slice(1);
}

/** `candidate`, suffixed 2, 3, ... until it no longer collides with `taken`. */
export function uniqueFormIdentifier(candidate: string, taken: string[]): string {
    if (!taken.includes(candidate)) {
        return candidate;
    }

    let suffix = 2;
    while (taken.includes(`${candidate}${suffix}`)) {
        suffix++;
    }

    return `${candidate}${suffix}`;
}

/**
 * Integer cents as money ("$25.00"). Only for the *_minor fields: amount_due is a DOLLAR
 * string and goes through its own formatter.
 */
export function formatMinorAmount(minor: number | null | undefined, currency: string | null = 'usd'): string {
    if (minor === null || minor === undefined || Number.isNaN(Number(minor))) return '—';

    const value = Number(minor) / 100;

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: (currency || 'usd').toUpperCase()
        }).format(value);
    } catch (e) {
        return `$${value.toFixed(2)}`;
    }
}

/** Slug for the form's address. Mirrors the backend regex ^[a-z0-9]+(?:-[a-z0-9]+)*$. */
export function deriveFormSlug(name: string): string {
    return (name || '')
        .normalize('NFKD')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

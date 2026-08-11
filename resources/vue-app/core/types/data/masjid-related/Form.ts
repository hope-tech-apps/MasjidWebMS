// Sign-up forms and their submissions. Mirrors App\Models\Form and
// App\Models\FormResponse plus the hand-built serializers in FormsController /
// FormResponsesController.
//
// MONEY WARNING: `amount_due` is a decimal:2 column, so Laravel serializes it as a
// DOLLAR string ("150.00") — it is NOT integer minor units like Donation's *_amount
// fields. Never run it through formatCents / never divide it by 100.

/** Workflow states an admin can move a response through (FormResponse::STATUSES). */
export type FormResponseStatus = 'new' | 'confirmed' | 'waitlisted' | 'cancelled';

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
    sort: FormResponseSortColumn;
    direction: FormResponseSortDirection;
};

/**
 * The only two editable fields. The submission itself is a record of what somebody
 * actually agreed to (waivers, medical authorisations) and the API refuses to rewrite
 * it — corrections belong in admin_notes.
 */
export type FormResponseUpdatePayload = {
    status: FormResponseStatus;
    admin_notes: string;
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
    name?: string | null;
    email?: string | null;
    phone?: string | null;
};

/**
 * `perEntryOfSection` must name a REPEATABLE section; the amount is then multiplied by
 * the number of rows submitted. Null means one flat fee per submission.
 */
export type FormFeeRule = {
    amount: number;
    currency?: string | null;
    perEntryOfSection?: string | null;
};

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

/** Slug for the form's address. Mirrors the backend regex ^[a-z0-9]+(?:-[a-z0-9]+)*$. */
export function deriveFormSlug(name: string): string {
    return (name || '')
        .normalize('NFKD')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

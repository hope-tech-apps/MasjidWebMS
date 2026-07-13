export type Contact = {
    id: number;
    masjid_id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    notes: string | null;
    created_at: string;
    updated_at: string;
};

// Shape submitted by the create/edit form (server stamps masjid_id + timestamps).
export type ContactPayload = {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    notes: string;
};

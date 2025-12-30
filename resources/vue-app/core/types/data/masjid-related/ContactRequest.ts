export type ContactRequest = {
    id: number;
    contact_us_account_id: number;
    contact_us_reason_id: number | null;
    message: string;
    created_at: string;
    updated_at: string;
    contacter: {
        id: number;
        mobile_app_user_id: number;
        email: string;
        name: string;
        phone: string | null;
    };
    reason: {
        id: number;
        text: string;
    } | null;
};


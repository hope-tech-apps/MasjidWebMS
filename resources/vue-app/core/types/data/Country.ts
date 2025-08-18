export type Country = {
    id: number;
    name: string;
    code: string;
    created_at: string;
    updated_at: string;
}

export type City = {
    id: number;
    name: string;
    country_id: number;
    created_at: string;
    updated_at: string;
}
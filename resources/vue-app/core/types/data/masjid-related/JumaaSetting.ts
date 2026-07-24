export type JumaaShift = {
    time: string;
    khateeb_name: string | null;
    khateeb_title: string | null;
    khutbah_title: string | null;
}

export type JumaaSetting = {
    id: number;
    masjid_id: number;
    iqama: string;
    athans: string[];
    shifts?: JumaaShift[] | null;
    created_at: string;
    updated_at: string;
}

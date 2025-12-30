export type IqamaType = 'minutes_after_adhan' | 'specific_time_ranges';

export type IqamaTimeRange = {
    id: number;
    iqama_time_setting_id: number;
    salah: 'fajr' | 'dhuhr' | 'asr' | 'maghrib' | 'isha';
    start_date: string;
    end_date: string;
    specific_time: string;
    created_at: string;
    updated_at: string;
};

export type IqamaTimeSetting = {
    id: number;
    masjid_id: number;
    iqama_type: IqamaType;
    show_iqama_times?: boolean;
    fajr: number;
    dhuhr: number;
    asr: number;
    maghrib: number;
    isha: number;
    time_ranges?: IqamaTimeRange[];
    created_at: string;
    updated_at: string;
}

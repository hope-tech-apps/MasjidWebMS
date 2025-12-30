export type PrayerCalculationMethod = 
    | 'MuslimWorldLeague'
    | 'Egyptian'
    | 'Karachi'
    | 'UmmAlQura'
    | 'Dubai'
    | 'MoonsightingCommittee'
    | 'NorthAmerica'
    | 'Kuwait'
    | 'Qatar'
    | 'Singapore'
    | 'Tehran'
    | 'Turkey';

export type Madhab = 'Shafi' | 'Hanafi';

export type HighLatitudeRule = 
    | 'MiddleOfTheNight'
    | 'SeventhOfTheNight'
    | 'TwilightAngle';

export type PrayerCalculationSetting = {
    id: number;
    masjid_id: number;
    method: PrayerCalculationMethod;
    madhab: Madhab;
    high_latitude_rule: HighLatitudeRule;
    created_at: string;
    updated_at: string;
}

export type PrayerCalculationOption = {
    value: string;
    label: string;
}

export type PrayerCalculationOptions = {
    methods: PrayerCalculationOption[];
    madhabs: PrayerCalculationOption[];
    high_latitude_rules: PrayerCalculationOption[];
}


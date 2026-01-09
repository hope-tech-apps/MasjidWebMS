import { Zikr } from "@/core/types/data/Azkar";
import { Hadith } from "@/core/types/data/Hadith";
import { Announcement } from "@/core/types/data/masjid-related/Announcement";
import { MasjidAboutUs } from "@/core/types/data/masjid-related/MasjidAboutUs";
import { Service } from "@/core/types/data/masjid-related/Service";
import { SocialMediaLink } from "@/core/types/data/masjid-related/SocialMediaLink";
import { Tasbih } from "@/core/types/data/Tasabih";
import { MasjidDashboardRoute, SuperDashboardRoute } from "../../config/SystemRoutes";
import { User } from "@/core/types/data/User";
import { Masjid } from "@/core/types/data/Masjid";

export type DashboardSearchResultData = {
    masjidAbout?: MasjidAboutUs[];
    socialMediaLinks?: SocialMediaLink[];
    announcements?: Announcement[];
    services?: Service[];
    azkar?: Zikr[];
    hadith?: Hadith[];
    tasbih?: Tasbih[];
    users?: User[];
    masjids?: Masjid[];
};

export type DashboardSearchResultRecord = {
    data_id?: number;
    url: MasjidDashboardRoute | SuperDashboardRoute;
    title: string;
    data?: MasjidAboutUs | SocialMediaLink | Announcement | Service | Zikr | Hadith | Tasbih | User | Masjid;
}

export const RESULT_TITLE_MAP = {
    masjidAbout: 'About Us',
    socialMediaLinks: 'Social Media Links',
    announcements: 'Announcement',
    services: 'Service',
    azkar: 'Adhkar',
    hadith: 'Hadith',
    tasbih: 'Tasbih',
    users: 'User',
    masjids: 'Masjid'
};

export const DATA_TO_SHOW_KEYS: (keyof DashboardSearchResultData)[] = ['announcements', 'services', 'azkar', 'hadith', 'tasbih', 'users', 'masjids'];
export const DATA_GENERAL_KEYS: (keyof DashboardSearchResultData)[] = ['masjidAbout', 'socialMediaLinks'];

// For front-end dashboard pages links search
export const MASJID_DASHBOARD_ROUTES_RESULTS: DashboardSearchResultRecord[] = [
    {
        url: '/masjid',
        title: 'Home - Page'
    },
    {
        url: '/masjid/details',
        title: 'Masjid Details - Page'
    },
    {
        url: '/masjid/announcements',
        title: 'Masjid Announcements - Page'
    },
    {
        url: '/masjid/services',
        title: 'Masjid Services - Page'
    },
    {
        url: '/masjid/donation',
        title: 'Masjid Donation Link - Page'
    },
    {
        url: '/masjid/about',
        title: 'Masjid About Us - Page'
    },
    {
        url: '/masjid/gallery',
        title: 'Masjid Photos & Images Gallery - Page'
    },
    {
        url: '/masjid/iqama',
        title: 'Masjid Iqama Time Settings - Page'
    },
    {
        url: '/masjid/notifications',
        title: 'Masjid Notifications Management - Page'
    },
    {
        url: '/masjid/mobile-features',
        title: 'Masjid Mobile App Features Control - Page'
    },
    {
        url: '/masjid/mobile-features',
        title: 'Masjid Mobile App Features Control - Page'
    },
    {
        url: '/masjid/admin/profile',
        title: 'Masjid Admin Profile - Page'
    }
];

export const GENERAL_DASHBOARD_ROUTES_RESULTS: DashboardSearchResultRecord[] = [
    {
        url: '/hadith',
        title: 'Hadith List - Page'
    },
    {
        url: '/azkar',
        title: 'Adhkar List - Page'
    },
    {
        url: '/tasabih',
        title: 'Tasabih List - Page'
    }
];

export const SUPER_DASHBOARD_ROUTES_RESULTS: DashboardSearchResultRecord[] = [
    {
        url: '/dashboard/super/users',
        title: 'Users Management - Page'
    },
    {
        url: '/dashboard/super/masjids',
        title: 'Masjids Management - Page'
    },
    {
        url: '/dashboard/super/profile',
        title: 'My Profile - Page'
    }
];

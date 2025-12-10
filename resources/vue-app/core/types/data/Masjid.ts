import { Admin } from "@/core/types/data/Admin"
import { Media } from "./Media"
import { City, Country } from "./Country"

export type Masjid = {
    id: number;
    user_id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    phone: string;
    phone_verified_at: string | null;
    country_id: number;
    city_id: number;
    address: string;
    latitude: number;
    longitude: number;
    created_by: number | null;
    updated_by: number | null;
    deleted_by: number | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    admin: Admin | null;
    logo: Media | null;
    footer_logo: Media | null;
    country: Country | null;
    city: City | null;
    website_link: string;
}

export const masjid: Masjid = {
    id: 1,
    user_id: 1,
    name: "Al Fathih Mosque",
    email: "test@email.com",
    email_verified_at: null,
    phone: "+123456789",
    phone_verified_at: null,
    country_id: 0,
    city_id: 0,
    address: "street address",
    latitude: 30.87605680,
    longitude: 29.74260400,
    created_by: 1,
    updated_by: 1,
    deleted_by: 1,
    created_at: '2025-03-25 15:25:00 PM',
    updated_at: '2025-03-25 15:25:00 PM',
    deleted_at: null,
    admin: null,
    logo: null,
    footer_logo: null,
    country: null,
    city: null,
    website_link: ''
}

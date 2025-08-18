export interface VueTelInputCountry {
    name: string;                // The name of the country
    iso2: string;                // The ISO 3166-1 alpha-2 code of the country
    dialCode: string;            // The international dialing code
    priority: number;            // The priority of the country in the dropdown list
    areaCodes?: string[];        // Optional: The area codes for the country
    flagClass: string;           // The CSS class for the country's flag icon
}

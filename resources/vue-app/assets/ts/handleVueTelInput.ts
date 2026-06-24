import { VueTelInputCountry } from "@/core/types/elements/VueTelInput"
import { AnyObjectInterface } from "@/core/types/interfaces/AnyObjectInterface"
import { Ref } from "vue"

// Returns the phone value with its leading dial code swapped for the newly
// selected country's dial code, preserving any subscriber digits the user typed.
// Used by the phone inputs so the country-code prefix reflects the chosen country.
export function applyCountryDialCode(currentPhone: string | undefined | null, country: VueTelInputCountry): string {
    if (!country?.dialCode) {
        return currentPhone ?? '';
    }
    const newPrefix = `+${country.dialCode}`;
    const value = (currentPhone ?? '').trim();

    // No existing prefix -> just set the new dial code.
    if (!value.startsWith('+')) {
        return `${newPrefix} `;
    }

    // Strip the existing "+<digits>" prefix and keep the rest (subscriber number).
    const match = value.match(/^\+\d+\s*(.*)$/);
    const rest = match ? match[1] : '';
    return rest ? `${newPrefix} ${rest}` : `${newPrefix} `;
}

// phone field - append country code
export function onVueTelInputCountryChanged(country:VueTelInputCountry, tempRef:Ref<AnyObjectInterface>, key:any=null) {
    console.log(country);
    
    if(country?.dialCode)  {
        if(key) {
            // if(Object.keys(tempRef.value).includes(key) && (typeof tempRef.value[key] !== 'string')) {
            //     tempRef.value[key] = String(tempRef.value[key])
            // }
            // if(tempRef.value?.[key]?.length < 6 || !tempRef.value?.[key]?.startsWith('+')){
            //     tempRef.value[key] = `+${country.dialCode} `
            // }
            tempRef.value[key] = `+${country.dialCode} `
        } else {
            if(tempRef?.value && typeof tempRef.value !== 'string') {
                tempRef.value.value = String(tempRef.value)
            }
            if(tempRef?.value?.length < 6 || !tempRef?.value?.startsWith('+')) {
                tempRef.value.value = `+${country.dialCode} `
            }
        }
    }
}
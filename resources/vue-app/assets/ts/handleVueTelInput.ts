import { VueTelInputCountry } from "@/core/types/elements/VueTelInput"
import { AnyObjectInterface } from "@/core/types/interfaces/AnyObjectInterface"
import { Ref } from "vue"

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
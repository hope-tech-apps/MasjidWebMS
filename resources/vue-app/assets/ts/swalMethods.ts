import { BackendResponseData } from "@/core/types/config/AxiosCustom";
import { AxiosError, AxiosResponse, isAxiosError } from "axios";

export const getMessageFromObj = (obj: AxiosError<BackendResponseData>|AxiosResponse<BackendResponseData>) => {
    let message = '';
    if(isAxiosError(obj)) {
        if(obj.response?.data)
            message = JSON.stringify(
                obj.response.data?.data
                ?? obj.response.data?.errors
                ?? obj.response.data?.message
                ?? 'Unexpected!'
            )
    } else {
        if(obj.data)
            message = JSON.stringify(
                obj.data?.data
                ?? obj.data?.errors
                ?? obj.data?.message
                ?? 'Unexpected!'
            )
    }
    return message;
}
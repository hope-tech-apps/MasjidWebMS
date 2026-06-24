import { BackendResponseData } from "@/core/types/config/AxiosCustom";
import { AxiosError, AxiosResponse, isAxiosError } from "axios";

// Flatten a payload (Laravel-style validation errors, plain object, array, or string)
// into a human-readable message instead of a raw JSON.stringify dump.
const flattenMessage = (payload: unknown): string => {
    if (payload === null || payload === undefined) {
        return '';
    }
    if (typeof payload === 'string') {
        return payload;
    }
    if (Array.isArray(payload)) {
        return payload.map(item => flattenMessage(item)).filter(Boolean).join('\n');
    }
    if (typeof payload === 'object') {
        // Laravel 422 shape: { field: ["message", ...], ... }
        return Object.values(payload as Record<string, unknown>)
            .map(value => flattenMessage(value))
            .filter(Boolean)
            .join('\n');
    }
    return String(payload);
}

export const getMessageFromObj = (obj: AxiosError<BackendResponseData>|AxiosResponse<BackendResponseData>) => {
    const uploadLimitMessage = 'Uploaded file is too large for server limits. Please use a smaller file or ask support to increase upload/post limits.';
    let message = '';
    if(isAxiosError(obj)) {
        if (obj.response?.status === 413) {
            return uploadLimitMessage;
        }
        if(obj.response?.data)
            message = flattenMessage(
                obj.response.data?.errors
                ?? obj.response.data?.data
                ?? obj.response.data?.message
                ?? 'Unexpected!'
            )
        else if (obj.message) {
            message = obj.message;
        }
    } else {
        if(obj.data)
            message = flattenMessage(
                obj.data?.errors
                ?? obj.data?.data
                ?? obj.data?.message
                ?? 'Unexpected!'
            )
    }
    return message;
}
import { User } from "@/core/types/data/User";

export type BackendResponseData = {
    status: string;
    data?: Object|[]|{user?:User, token?:string};
    errors?: Object|[];
    message?: string;
}
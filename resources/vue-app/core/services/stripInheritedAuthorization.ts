import type { AxiosInstance } from 'axios';

/**
 * Remove the bearer token the admin ApiService wrote onto axios's GLOBAL defaults from
 * a client made with axios.create().
 *
 * ApiService.setHeader() sets `axios.defaults.headers.common.Authorization` to the
 * STAFF token, and axios.create() copies whatever defaults it finds at that moment. A
 * portal client (the parent's, the child's) created on a device where an admin or
 * teacher has signed in is therefore born carrying that credential, and an interceptor
 * that only ever ADDS its own header leaves it on every request that has no token of
 * its own. Axios keeps default headers in `common` and one bucket per method (and
 * accepts a bare top-level key), so all of them are cleared.
 *
 * Deleting a key from the instance's own copy never touches `axios.defaults` (the copy
 * is deep), so the admin screens keep their token.
 */
export function stripInheritedAuthorization(client: AxiosInstance): void {
    const headers: any = client.defaults.headers;
    if (!headers) return;

    for (const bucket of [headers, headers.common, headers.get, headers.post, headers.put, headers.patch, headers.delete, headers.head]) {
        if (bucket && typeof bucket === 'object') {
            delete bucket.Authorization;
            delete bucket.authorization;
        }
    }
}

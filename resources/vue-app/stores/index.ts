import { createPinia } from 'pinia'
import { tenantStoreReset } from '@/stores/plugins/tenantStoreReset'

const pinia = createPinia()

/**
 * Registered here — before `app.use(pinia)` in main.ts, and therefore before any
 * store can be created — because the plugin snapshots each store's state at
 * creation to build the `$reset()` that switching organisation depends on. A
 * store created before the plugin is registered would get no snapshot and no
 * `$reset`, and would keep the previous organisation's rows through a switch.
 *
 * Inert until a switch calls it: it adds one unused method per store.
 */
pinia.use(tenantStoreReset)

export default pinia

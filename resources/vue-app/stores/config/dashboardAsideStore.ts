import { MASJID_DASHBOARD_ASIDE_MENU, SUPER_DASHBOARD_ASIDE_MENU } from "@/core/constants/dashboardAsideMenuItems";
import { AsideMenuItem } from "@/core/types/config/AsideMenuItem";
import { defineStore } from "pinia";
import { computed, ref, watch } from "vue";
import { useAuthStore } from "@/stores/authStore";

export const useDashboardAsideStore = defineStore('dashboardAsideStore', () => {

    const asideMenuItems = ref<AsideMenuItem[]>(MASJID_DASHBOARD_ASIDE_MENU);

    // Stores
    const authStore = useAuthStore();

    

    return { asideMenuItems };

});
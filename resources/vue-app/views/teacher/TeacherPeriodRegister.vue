<template>
    <section>
        <RouterLink to="/teacher/schedule" class="small d-inline-block mb-3">← My schedule</RouterLink>
        <h1 class="h4">{{ route.params.classId ? "Class Attendance" : "Period register" }}</h1>
        <GroupAttendanceTab :masjid-id="school" :group-id="Number(route.params.classId) || 0" :class-periods-only="!!route.params.classId" :meeting-id="Number(route.params.meetingId) || undefined" :date="date" realm="teacher" :transport="transport" />
    </section>
</template>
<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/authStore';
import TeacherApiService from '@/core/services/TeacherApiService';
import GroupAttendanceTab from '@/views/dashboard/groups/GroupAttendanceTab.vue';
const route = useRoute();
const school = computed(() => Number(useAuthStore().dashboardMasjidId));
const date = computed(() => typeof route.query.date === 'string' ? route.query.date : undefined);
const transport = { get: (url: string) => TeacherApiService.get(url), put: (url: string, body: any, _contentType?: string, headers?: any) => TeacherApiService.put(url, body, headers) };
</script>

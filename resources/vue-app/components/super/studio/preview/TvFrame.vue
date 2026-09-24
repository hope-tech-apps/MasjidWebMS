<template>
    <DeviceStage :width="1920" :height="1080" :max-scale="maxScale" :label="`${preview.tvos.header_title || 'Untitled draft'} TV board`">
        <div class="tv" :style="{ backgroundColor: TVOS_BACKGROUND, color: TVOS_HEADER_INK }">
            <header class="tv-header">
                <img v-if="logoUrl" :src="logoUrl" alt="" class="logo" />
                <span class="title">{{ preview.tvos.header_title || 'Untitled draft' }}</span>
            </header>

            <div class="tv-body">
                <section v-if="preview.tvos.show_prayer_panel" class="panel prayer" aria-label="Prayer times">
                    <table v-if="times" class="times">
                        <tbody>
                            <tr v-for="row in times" :key="row.key">
                                <th scope="row" class="text-capitalize">{{ row.key }}</th>
                                <td>{{ row.adhan }}</td>
                                <td class="iqama">{{ row.iqama ?? '' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="muted">Prayer times show once the location, timezone and calculation method are set.</p>
                </section>

                <section class="panel announcements" aria-label="Announcements">
                    <span class="panel-title">Announcements</span>
                    <p class="muted">The organisation's announcements, one at a time, every {{ preview.tvos.carousel_interval_seconds }} seconds.</p>
                </section>

                <section v-if="preview.tvos.show_qr" class="panel qr" aria-label="Donation code">
                    <i class="bi bi-qr-code" aria-hidden="true"></i>
                    <span>{{ preview.tvos.donate_caption }}</span>
                </section>
            </div>

            <p class="tv-note">Events calendar arrives with the tvOS template (W2)</p>
        </div>
    </DeviceStage>
</template>

<script setup lang="ts">
/**
 * The tvOS board mockup, 1920×1080 on the board's fixed #0F0F0F. What it shows
 * is the server's `tvos` block (StudioPreview, TvConfigController's own
 * constants): the header title, whether the prayer panel shows (a masjid's
 * board), the carousel interval, and the donation code with its caption when
 * the client gave a donation link.
 *
 * The prayer panel's times are worked out from the draft's own answers
 * (core/studio/mockPrayerTimes.ts), and say so when there is not enough to
 * work them out. The events calendar is not drawn: the board gets it with the
 * tvOS template in W2 (docs/manara-studio.md D11), and the note says so.
 */
import DeviceStage from '@/components/super/studio/preview/DeviceStage.vue';
import { TVOS_BACKGROUND, TVOS_HEADER_INK } from '@/core/studio/appLabels';
import { mockPrayerTimes } from '@/core/studio/mockPrayerTimes';
import { StudioAnswers, StudioPreview } from '@/core/types/data/Studio';
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    preview: StudioPreview;
    answers: StudioAnswers;
    logoUrl: string | null;
    maxScale?: number;
}>(), { maxScale: 1 });

const times = computed(() => mockPrayerTimes({
    latitude: props.answers.identity.latitude,
    longitude: props.answers.identity.longitude,
    timezone: props.answers.identity.timezone,
    method: props.answers.prayer.method,
    madhab: props.answers.prayer.madhab,
    high_latitude_rule: props.answers.prayer.high_latitude_rule,
    iqama: props.answers.prayer.iqama,
    iqama_given: props.answers.prayer.iqama_given,
}));
</script>

<style scoped>
.tv {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    padding: 48px 64px;
    gap: 36px;
    border: 14px solid #000;
    border-radius: 12px;
    font-size: 28px;
}

.tv-header {
    display: flex;
    align-items: center;
    gap: 28px;
}

.logo {
    height: 96px;
    max-width: 320px;
    object-fit: contain;
}

.title {
    font-size: 56px;
    font-weight: 700;
}

.tv-body {
    flex: 1;
    display: flex;
    gap: 36px;
    min-height: 0;
}

.panel {
    background: rgba(255, 255, 255, .06);
    border-radius: 24px;
    padding: 36px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.prayer {
    flex: 0 0 560px;
}

.announcements {
    flex: 1;
}

.qr {
    flex: 0 0 320px;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.qr .bi {
    font-size: 180px;
    line-height: 1;
}

.panel-title {
    font-size: 36px;
    font-weight: 700;
}

.times {
    width: 100%;
    color: inherit;
    font-size: 34px;
    border-collapse: collapse;
}

.times th,
.times td {
    padding: 12px 0;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
}

.times th {
    font-weight: 600;
}

.times td {
    text-align: right;
}

.times .iqama {
    opacity: .7;
    padding-left: 24px;
}

.muted {
    opacity: .65;
    margin: 0;
}

.tv-note {
    margin: 0;
    font-size: 24px;
    opacity: .65;
}
</style>

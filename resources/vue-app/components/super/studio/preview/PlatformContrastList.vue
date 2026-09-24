<template>
    <div class="contrast-list">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="list-title">Contrast on each platform</span>
            <span class="advisory">Advisory</span>
        </div>
        <p v-if="!rows" class="studio-hint mb-0">Shown once all four colours are chosen.</p>
        <template v-else>
            <p class="studio-hint mb-0">
                These colours are fixed in the apps or follow the brand colours, so a low ratio here is for you to know
                about and never blocks Generate. Only the Brand panel's contrast check does.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Where</th>
                            <th scope="col">Sample</th>
                            <th scope="col" class="text-end">Ratio</th>
                            <th scope="col">Reads as</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.key">
                            <td>{{ rowLabel(row.key) }}</td>
                            <td>
                                <span class="sample" :style="{ color: row.foreground, backgroundColor: row.background }">Aa</span>
                            </td>
                            <td class="text-end text-nowrap">{{ row.ratio.toFixed(2) }}:1</td>
                            <td>
                                <span class="result" :class="row.aa_normal ? 'pass' : row.aa_large ? 'large' : 'warn'">
                                    {{ row.aa_normal ? 'Any text' : row.aa_large ? 'Large text only' : 'Low' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
/**
 * The server's per-platform contrast rows (StudioPreview::platformContrast,
 * R16), shown as they come and labelled advisory. The iOS home header is
 * hard-coded white and Android's selected tab hard-coded green, so no brand
 * colour can fix a low row there; the rows inform and never gate. The ratios
 * and verdicts are the server's (WcagColor); only the names in English are here,
 * and a row this list has no name for shows its key.
 */
import { StudioPlatformContrastRow } from '@/core/types/data/Studio';

defineProps<{ rows: StudioPlatformContrastRow[] | null }>();

const ROW_LABELS: Record<string, string> = {
    'ios.home_header': 'iPhone home header text',
    'android.home_header': 'Android header text',
    'app.menu_band': 'App menu band text',
    'ios.selected_tab': 'iPhone selected tab',
    'android.selected_tab': 'Android selected tab',
    'web.primary_button': 'Website button text',
    'tvos.header': 'TV board header',
};

function rowLabel(key: string): string {
    return ROW_LABELS[key] ?? key;
}
</script>

<style scoped>
.contrast-list {
    display: flex;
    flex-direction: column;
    gap: .5rem;
}

.list-title {
    font-weight: 600;
    font-size: .95rem;
}

.advisory,
.result {
    border-radius: 2rem;
    padding: .1rem .6rem;
    font-size: .8rem;
    font-weight: 600;
}

.advisory {
    background: rgba(108, 117, 125, .12);
    color: #495057;
}

.studio-hint {
    color: #6c757d;
    font-size: .8rem;
}

.pass {
    background: rgba(1, 177, 81, .12);
    color: #0b6b35;
}

.large {
    background: rgba(240, 173, 78, .18);
    color: #7a4b00;
}

.warn {
    background: rgba(217, 83, 79, .12);
    color: #a02622;
}

.sample {
    display: inline-block;
    min-width: 2.5rem;
    text-align: center;
    padding: .15rem .5rem;
    border-radius: .3rem;
    border: 1px solid rgba(0, 0, 0, .1);
    font-weight: 600;
}
</style>

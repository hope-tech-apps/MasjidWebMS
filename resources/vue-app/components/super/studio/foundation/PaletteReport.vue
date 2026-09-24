<template>
    <div class="palette-report">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="report-title">Contrast check</span>
            <span v-if="report" class="verdict" :class="report.valid ? 'pass' : 'fail'">
                {{ report.valid ? 'Readable' : `${report.blocking_failures.length} to fix before Generate` }}
            </span>
            <span v-if="loading" class="spinner-border spinner-border-sm text-muted" role="status">
                <span class="visually-hidden">Checking…</span>
            </span>
        </div>

        <p v-if="error" class="studio-error">{{ error }}</p>
        <p v-if="!report" class="studio-hint">The check runs once all four colours are chosen.</p>

        <template v-else>
            <p class="studio-hint">
                Text pairs must reach 4.5:1 and block Generate until they do. The colours drawn on the background are
                advisory at 3:1.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Pair</th>
                            <th scope="col">Sample</th>
                            <th scope="col" class="text-end">Ratio</th>
                            <th scope="col">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="pair in report.pairs" :key="pair.key">
                            <td>
                                {{ pairLabel(pair.key) }}
                                <span v-if="pair.ink_source" class="d-block small text-muted">{{ inkSourceLabel(pair.ink_source) }}</span>
                            </td>
                            <td>
                                <span v-if="pair.foreground && pair.background" class="sample"
                                    :style="{ color: pair.foreground, backgroundColor: pair.background }">Aa</span>
                            </td>
                            <td class="text-end text-nowrap">
                                {{ pair.ratio === null ? '' : `${pair.ratio.toFixed(2)}:1` }}
                                <span class="d-block small text-muted">needs {{ pair.required }}:1</span>
                            </td>
                            <td>
                                <span class="result" :class="pair.passes ? 'pass' : pair.blocking ? 'fail' : 'warn'">
                                    {{ pair.passes ? 'Passes' : pair.blocking ? 'Fails' : 'Low (advisory)' }}
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
 * The server's palette report (PaletteContrast::report, R16), shown as it is.
 * The ratios are the server's, from the same WcagColor the gate at Step 3 uses,
 * so this screen never judges contrast itself and can never disagree with the
 * gate. The pair keys are the server's; only their names in English are here.
 */
import { StudioPaletteReport, StudioPalettePair } from '@/core/types/data/Studio';

defineProps<{ report: StudioPaletteReport | null; loading?: boolean; error?: string | null }>();

const PAIR_LABELS: Record<string, string> = {
    text_on_background: 'Body text on the background',
    on_primary: 'Text on the primary colour',
    on_secondary: 'Text on the secondary colour',
    on_accent: 'Text on the accent colour',
    primary_on_background: 'Primary colour on the background',
    accent_on_background: 'Accent colour on the background',
};

function pairLabel(key: string): string {
    return PAIR_LABELS[key] ?? key;
}

function inkSourceLabel(source: NonNullable<StudioPalettePair['ink_source']>): string {
    switch (source) {
        case 'manual': return 'Text colour set by hand';
        case 'auto': return 'Text colour switched for contrast';
        default: return 'Text colour from the theme';
    }
}
</script>

<style scoped>
.palette-report {
    display: flex;
    flex-direction: column;
    gap: .5rem;
}

.report-title {
    font-weight: 600;
    font-size: .95rem;
}

.verdict,
.result {
    border-radius: 2rem;
    padding: .1rem .6rem;
    font-size: .8rem;
    font-weight: 600;
}

.pass {
    background: rgba(1, 177, 81, .12);
    color: #0b6b35;
}

.fail {
    background: rgba(217, 83, 79, .12);
    color: #a02622;
}

.warn {
    background: rgba(240, 173, 78, .18);
    color: #7a4b00;
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

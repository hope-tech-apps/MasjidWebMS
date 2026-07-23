<template>
    <Form :validation-schema="validationSchema" @submit="onSubmit" class="card border-0 py-4 px-3 w-100">
        <div class="card-header bg-white border-0 d-flex align-items-start justify-content-between">
            <div>
                <div class="card-title fs-4 fw-semibold mb-1">
                    Brand Studio
                </div>
                <p class="text-muted mb-0 small">
                    Pick four base colors. We derive a full palette — surfaces, text, borders and
                    contrast-safe on-colors — that skins the masjid's website and every mobile app.
                </p>
            </div>
        </div>

        <div class="card-body w-100">
            <div class="row g-4">
                <!-- Base colors -->
                <div class="col-12 col-lg-6">
                    <div class="fw-semibold text-uppercase text-muted small mb-3">Base Colors</div>
                    <div class="row">
                        <!-- Primary -->
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label fw-semibold">Primary Color</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control form-control-color"
                                    :value="pickerValue(settingsModel.primary_color, DEFAULTS.primary)"
                                    @input="settingsModel.primary_color = ($event.target as HTMLInputElement).value.toUpperCase()" />
                                <Field name="primary_color" v-model="settingsModel.primary_color"
                                    class="dashboard-input" placeholder="#01b151" />
                            </div>
                            <ErrorMessage name="primary_color" class="error-message" />
                        </div>

                        <!-- Secondary -->
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label fw-semibold">Secondary Color</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control form-control-color"
                                    :value="pickerValue(settingsModel.secondary_color, DEFAULTS.secondary)"
                                    @input="settingsModel.secondary_color = ($event.target as HTMLInputElement).value.toUpperCase()" />
                                <Field name="secondary_color" v-model="settingsModel.secondary_color"
                                    class="dashboard-input" placeholder="#0b7a3b" />
                            </div>
                            <ErrorMessage name="secondary_color" class="error-message" />
                        </div>

                        <!-- Accent -->
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label fw-semibold">Accent Color</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control form-control-color"
                                    :value="pickerValue(settingsModel.accent_color, DEFAULTS.accent)"
                                    @input="settingsModel.accent_color = ($event.target as HTMLInputElement).value.toUpperCase()" />
                                <Field name="accent_color" v-model="settingsModel.accent_color"
                                    class="dashboard-input" placeholder="#f2b705" />
                            </div>
                            <ErrorMessage name="accent_color" class="error-message" />
                        </div>

                        <!-- Background -->
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label fw-semibold">Background Color</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control form-control-color"
                                    :value="pickerValue(settingsModel.background_color, DEFAULTS.background)"
                                    @input="settingsModel.background_color = ($event.target as HTMLInputElement).value.toUpperCase()" />
                                <Field name="background_color" v-model="settingsModel.background_color"
                                    class="dashboard-input" placeholder="#ffffff" />
                            </div>
                            <ErrorMessage name="background_color" class="error-message" />
                        </div>
                    </div>
                    <p class="text-muted small mb-0">
                        Leave a field blank to fall back to the app's built-in default.
                    </p>
                </div>

                <!-- Derived palette + preview -->
                <div class="col-12 col-lg-6">
                    <div class="fw-semibold text-uppercase text-muted small mb-3">Derived Palette (live preview)</div>

                    <!-- Brand pairs: fill + contrast-safe on-color -->
                    <div class="row g-2 mb-3">
                        <div class="col-4" v-for="pair in brandPairs" :key="pair.label">
                            <div class="swatch-pair" :style="{ backgroundColor: pair.fill, color: pair.on }">
                                <span class="swatch-aa">Aa</span>
                                <span class="swatch-name">{{ pair.label }}</span>
                                <span class="swatch-hex">{{ pair.fill }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Surface tokens previewed against the chosen background -->
                    <div class="surface-preview mb-3" :style="{ backgroundColor: derived.background }">
                        <div class="row g-2">
                            <div class="col-3" v-for="tok in surfaceTokens" :key="tok.label">
                                <div class="swatch-box" :style="{ backgroundColor: tok.value, borderColor: derived.border }"></div>
                                <div class="swatch-caption" :style="{ color: derived.text }">{{ tok.label }}</div>
                                <div class="swatch-caption-hex" :style="{ color: derived.textMuted }">{{ tok.value }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Status colors -->
                    <div class="row g-2 mb-3">
                        <div class="col-4" v-for="tok in statusTokens" :key="tok.label">
                            <div class="swatch-box swatch-box-sm" :style="{ backgroundColor: tok.value }"></div>
                            <div class="swatch-caption text-muted">{{ tok.label }}</div>
                        </div>
                    </div>

                    <!-- Realistic mock card -->
                    <div class="mock-card" :style="{ backgroundColor: derived.surface, borderColor: derived.border }">
                        <div class="mock-card-title" :style="{ color: derived.text }">Jumu'ah Prayer</div>
                        <div class="mock-card-body" :style="{ color: derived.textMuted }">
                            Doors open 30 minutes before the khutbah. Please arrive early.
                        </div>
                        <button type="button" class="mock-card-btn"
                            :style="{ backgroundColor: derived.primary, color: derived.onPrimary }">
                            View Times
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-footer bg-white border-0 d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" :disabled="isLoading">
                <span v-if="isLoading" class="spinner-border spinner-border-sm me-2"></span>
                Save Settings
            </button>
        </div>
    </Form>
</template>

<script setup lang="ts">
import { ref, onBeforeMount, computed } from 'vue';
import { Form, Field, ErrorMessage } from 'vee-validate';
import { object, string } from 'yup';
import { useMasjidStore } from '@/stores/masjidStore';
import ApiService from '@/core/services/ApiService';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import type { AxiosError } from 'axios';
import type { BackendResponseData } from '@/core/types/config/AxiosCustom';
import type { ThemeSetting } from '@/core/types/data/masjid-related/ThemeSetting';

// Stores
const masjidStore = useMasjidStore();

// App-wide fallbacks — mirror App\Support\DesignTokens::DEFAULTS (historical Burlington green).
const DEFAULTS = {
    primary: '#01B151',
    secondary: '#0B7A3B',
    accent: '#F2B705',
    background: '#FFFFFF'
} as const;

// State
const isLoading = ref<boolean>(false);
const settingsModel = ref({
    primary_color: '',
    secondary_color: '',
    accent_color: '',
    background_color: ''
});

// Validation — a hex color (#RGB, #RRGGBB or #RRGGBBAA), or empty to fall back.
const hexRule = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/;
const validationSchema = computed(() => {
    return object().shape({
        primary_color: string().nullable().matches(hexRule, { excludeEmptyString: true, message: 'Must be a hex color, e.g. #01b151' }).label('Primary Color'),
        secondary_color: string().nullable().matches(hexRule, { excludeEmptyString: true, message: 'Must be a hex color, e.g. #0b7a3b' }).label('Secondary Color'),
        accent_color: string().nullable().matches(hexRule, { excludeEmptyString: true, message: 'Must be a hex color, e.g. #f2b705' }).label('Accent Color'),
        background_color: string().nullable().matches(hexRule, { excludeEmptyString: true, message: 'Must be a hex color, e.g. #ffffff' }).label('Background Color')
    });
});

/* ------------------------------------------------------------------ *
 * Token derivation — a faithful TS port of App\Support\DesignTokens.  *
 * Same inputs produce the same palette the backend serves to clients, *
 * so the admin sees the real result while picking.                    *
 * ------------------------------------------------------------------ */

/** Normalize a hex string to #RRGGBB (uppercase), or null when blank/invalid. */
function norm(hex: string | null | undefined): string | null {
    const v = (hex ?? '').trim();
    if (v === '') return null;
    if (!hexRule.test(v)) return null;
    // Expand #RGB -> #RRGGBB.
    if (v.length === 4) {
        return ('#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3]).toUpperCase();
    }
    return v.toUpperCase();
}

/** First three byte-pairs (RGB) of a #RRGGBB[AA] color. */
function rgb(hex: string): [number, number, number] {
    const h = hex.replace(/^#/, '');
    return [
        parseInt(h.substring(0, 2), 16),
        parseInt(h.substring(2, 4), 16),
        parseInt(h.substring(4, 6), 16)
    ];
}

/** WCAG relative luminance (0=black .. 1=white). */
function luminance(hex: string): number {
    const [r, g, b] = rgb(hex);
    const lin = (c: number) => (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
    return 0.2126 * lin(r / 255) + 0.7152 * lin(g / 255) + 0.0722 * lin(b / 255);
}

const isDark = (hex: string) => luminance(hex) < 0.5;

/** Contrast-safe text color: dark ink on light fills, white on dark fills. */
const contrastText = (hex: string) => (luminance(hex) > 0.55 ? '#111827' : '#FFFFFF');

/** Mix a color toward white by amount (0..1). */
function lighten(hex: string, amount: number): string {
    const [r, g, b] = rgb(hex);
    const mix = (c: number) => Math.round(c + (255 - c) * amount);
    const hx = (c: number) => mix(c).toString(16).padStart(2, '0').toUpperCase();
    return '#' + hx(r) + hx(g) + hx(b);
}

/** The full resolved color tree for the currently-picked base colors. */
const derived = computed(() => {
    const primary = norm(settingsModel.value.primary_color) ?? DEFAULTS.primary;
    const secondary = norm(settingsModel.value.secondary_color) ?? DEFAULTS.secondary;
    const accent = norm(settingsModel.value.accent_color) ?? DEFAULTS.accent;
    const background = norm(settingsModel.value.background_color) ?? DEFAULTS.background;

    const darkBg = isDark(background);

    return {
        primary,
        secondary,
        accent,
        background,
        surface: darkBg ? lighten(background, 0.06) : '#FFFFFF',
        text: darkBg ? '#F5F5F5' : '#111827',
        textMuted: darkBg ? '#B8B8B8' : '#6B7280',
        border: darkBg ? lighten(background, 0.14) : '#E5E7EB',
        onPrimary: contrastText(primary),
        onSecondary: contrastText(secondary),
        onAccent: contrastText(accent),
        success: '#16A34A',
        warning: '#D97706',
        error: '#DC2626'
    };
});

// Preview groupings
const brandPairs = computed(() => [
    { label: 'primary', fill: derived.value.primary, on: derived.value.onPrimary },
    { label: 'secondary', fill: derived.value.secondary, on: derived.value.onSecondary },
    { label: 'accent', fill: derived.value.accent, on: derived.value.onAccent }
]);

const surfaceTokens = computed(() => [
    { label: 'surface', value: derived.value.surface },
    { label: 'text', value: derived.value.text },
    { label: 'muted', value: derived.value.textMuted },
    { label: 'border', value: derived.value.border }
]);

const statusTokens = computed(() => [
    { label: 'success', value: derived.value.success },
    { label: 'warning', value: derived.value.warning },
    { label: 'error', value: derived.value.error }
]);

/** Valid #RRGGBB for the native picker, falling back to the brand default. */
function pickerValue(current: string, fallback: string): string {
    return norm(current) ?? fallback;
}

// Lifecycle
onBeforeMount(async () => {
    await fetchSettings();
});

// Methods
const fetchSettings = async () => {
    try {
        const response = await ApiService.get(`/api/admin/masjids/${masjidStore.masjid?.id}/theme`);
        if (response.data.status === 'success' && response.data.data) {
            const data: ThemeSetting = response.data.data;
            settingsModel.value.primary_color = data.primary_color ?? '';
            settingsModel.value.secondary_color = data.secondary_color ?? '';
            settingsModel.value.accent_color = data.accent_color ?? '';
            settingsModel.value.background_color = data.background_color ?? '';
        }
    } catch (error) {
        console.error('Error fetching settings:', error);
    }
};

const onSubmit = async () => {
    isLoading.value = true;
    QSwal.fire("Question", 'Save theme settings?', 'question')
        .then(async (result) => {
            if (result.isConfirmed) {
                await ApiService.post(`/api/admin/masjids/${masjidStore.masjid?.id}/theme`, settingsModel.value)
                    .then(async res => {
                        if (res.data.status === 'success') {
                            QSwal.fire("Success", "Theme settings saved successfully.", "success");
                            await fetchSettings();
                        } else {
                            QSwal.fire("Sorry", getMessageFromObj(res), "warning");
                        }
                    })
                    .catch((e: AxiosError<BackendResponseData>) => {
                        QSwal.fire(e.message, getMessageFromObj(e), "error");
                    })
                    .finally(() => {
                        isLoading.value = false;
                    });
            } else {
                isLoading.value = false;
            }
        });
};
</script>

<style scoped>
/* Brand-pair swatch: shows a fill with its contrast-safe on-color. */
.swatch-pair {
    border-radius: 10px;
    padding: 0.6rem 0.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    min-height: 78px;
    border: 1px solid rgba(0, 0, 0, 0.08);
}

.swatch-aa {
    font-size: 1.15rem;
    font-weight: 700;
    line-height: 1.1;
}

.swatch-name {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: capitalize;
    opacity: 0.9;
}

.swatch-hex {
    font-size: 0.62rem;
    font-family: monospace;
    opacity: 0.8;
}

/* Surface tokens previewed on the chosen background. */
.surface-preview {
    border-radius: 10px;
    padding: 0.75rem;
    border: 1px solid rgba(0, 0, 0, 0.08);
}

.swatch-box {
    width: 100%;
    height: 34px;
    border-radius: 8px;
    border: 1px solid rgba(0, 0, 0, 0.15);
}

.swatch-box-sm {
    height: 24px;
}

.swatch-caption {
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: capitalize;
    margin-top: 0.25rem;
}

.swatch-caption-hex {
    font-size: 0.6rem;
    font-family: monospace;
}

/* Realistic mock card using surface/text/primary/onPrimary. */
.mock-card {
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    padding: 1rem;
}

.mock-card-title {
    font-size: 1.05rem;
    font-weight: 700;
    margin-bottom: 0.35rem;
}

.mock-card-body {
    font-size: 0.85rem;
    margin-bottom: 0.85rem;
}

.mock-card-btn {
    border: none;
    border-radius: 999px;
    padding: 0.45rem 1.1rem;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
}
</style>

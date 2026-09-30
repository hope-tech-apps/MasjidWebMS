<template>
    <!--
        The frame every staff sign-in screen shares: sign-in, the second factor,
        forgot password and set password. It is the first thing a school, masjid,
        community group or business sees after they buy Manara, so it carries the
        same brand as the marketing site they came from (manara.hopetechapps.com):
        the navy-to-emerald hero, the star lattice, Figtree, and the four products.

        Presentation only. Every behaviour lives in the views that fill the slot.
    -->
    <div class="auth-shell">
        <aside class="auth-stage" aria-label="About Manara">
            <div class="auth-stage__glow" aria-hidden="true"></div>
            <!-- Manara means lighthouse: a faint beam sweeps slowly from the mark. -->
            <div class="auth-stage__beam" aria-hidden="true"></div>
            <div class="auth-stage__lattice" aria-hidden="true"></div>

            <div class="auth-stage__inner">
                <div class="auth-brand">
                    <span class="auth-brand__mark">
                        <img :src="'/manara-icon.svg'" width="44" height="44" alt="" />
                    </span>
                    <span class="auth-brand__name">Manara</span>
                </div>

                <div class="auth-stage__copy">
                    <p class="auth-stage__eyebrow">Manara by Hope Tech</p>
                    <p class="auth-stage__title">Made for the places your community gathers.</p>
                    <p class="auth-stage__lede">
                        One home for your masjid, school, community organization or business,
                        with a team at Hope Tech behind it.
                    </p>
                </div>

                <ul class="auth-products" aria-label="Manara products">
                    <li v-for="product in products" :key="product.name" class="auth-product"
                        :style="{ '--tint': product.tint }">
                        <span class="auth-product__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                                stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <path v-for="(d, i) in product.icon" :key="i" :d="d" />
                            </svg>
                        </span>
                        <span class="auth-product__text">
                            <span class="auth-product__name">{{ product.name }}</span>
                            <span class="auth-product__line">{{ product.line }}</span>
                        </span>
                    </li>
                </ul>

                <div class="auth-stage__foot">
                    <span>&copy; {{ year }} Hope Tech</span>
                    <span class="auth-stage__trust">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="5" y="11" width="14" height="10" rx="2" />
                            <path d="M8 11V8a4 4 0 0 1 8 0v3" />
                        </svg>
                        Encrypted connection
                    </span>
                    <span class="auth-stage__trust">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 3l7 3v5c0 4.5-3 8.2-7 10-4-1.8-7-5.5-7-10V6z" />
                            <path d="M9 12l2 2 4-4" />
                        </svg>
                        Two-step sign-in
                    </span>
                </div>
            </div>
        </aside>

        <main class="auth-main">
            <div class="auth-main__body">
                <slot />
            </div>
            <div v-if="$slots.foot" class="auth-main__foot">
                <slot name="foot" />
            </div>
        </main>
    </div>
</template>

<script setup lang="ts">
/*
 * The four products, in the order the marketing site lists them. Each line says
 * only what the product does today (the marketing pages are the source), and the
 * tints are each product's accent from the marketing theme, lifted for a dark
 * background.
 */
const products = [
    {
        name: 'Masjids',
        line: 'Prayer times, giving, events',
        tint: '#f0cd7c',
        icon: ['M4 21h16', 'M6 21v-7a6 6 0 0 1 12 0v7', 'M12 8V5', 'M12 5a1.5 1.5 0 1 0 0-.01', 'M10 21v-4a2 2 0 0 1 4 0v4'],
    },
    {
        name: 'Schools',
        line: 'Classes, families, report cards',
        tint: '#a9b6ff',
        icon: ['M3 6.5A2.5 2.5 0 0 1 5.5 4H11v15H5.5A2.5 2.5 0 0 0 3 21.5z', 'M21 6.5A2.5 2.5 0 0 0 18.5 4H13v15h5.5a2.5 2.5 0 0 1 2.5 2.5z'],
    },
    {
        name: 'Community',
        line: 'Members, programs, sign-ups',
        tint: '#7fd8cf',
        icon: ['M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', 'M3 20a6 6 0 0 1 12 0', 'M16 5.3a3 3 0 0 1 0 5.4', 'M18 14.5a6 6 0 0 1 3 5.5'],
    },
    {
        name: 'Businesses',
        line: 'Events, sign-ups, payments',
        tint: '#f5a8c8',
        icon: ['M4 10v10h16V10', 'M3 10l2-6h14l2 6', 'M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0', 'M10 20v-5h4v5'],
    },
];

const year = new Date().getFullYear();
</script>

<style>
/*
 * Not scoped: the views inside the shell share these classes (auth-card,
 * auth-input, auth-button and friends), and every rule is namespaced under
 * .auth-shell so nothing leaks into the dashboard.
 *
 * Figtree is the marketing site's face. The variable file is self-hosted (one
 * request covers every weight, and CSP font-src allows 'self'), under its own
 * family name so it never competes with the static Figtree the index page loads.
 */
@font-face {
    font-family: 'Manara Figtree';
    src: url('/fonts/figtree/figtree-latin-wght-normal.woff2') format('woff2-variations'),
         url('/fonts/figtree/figtree-latin-wght-normal.woff2') format('woff2');
    font-weight: 300 900;
    font-style: normal;
    font-display: swap;
}

.auth-shell {
    --a-brand: #01b151;
    --a-brand-strong: #007a38;
    --a-ink: #111827;
    --a-ink-soft: #374151;
    --a-muted: #5d6275;
    --a-line: #d8dee6;
    --a-field: #7b8594;
    --a-surface: #ffffff;
    --a-bg: #f5f7fa;
    --a-danger: #b42318;
    --a-danger-soft: #fef3f2;
    --a-ok: #067647;
    --a-ok-soft: #ecfdf3;
    --a-warn: #93370d;
    --a-warn-soft: #fffaeb;
    --a-ring: 0 0 0 4px rgb(1 177 81 / 18%);
    --a-night: #07131f;
    --a-navy: #0b2340;
    --a-gold: #f3cf7a;

    display: grid;
    grid-template-columns: 1fr;
    min-height: 100vh;
    min-height: 100dvh;
    background: var(--a-bg);
    color: var(--a-ink);
    font-family: 'Manara Figtree', 'Figtree', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;
}

/* ---------- the brand stage ---------- */
.auth-shell .auth-stage {
    position: relative;
    overflow: hidden;
    isolation: isolate;
    color: #fff;
    background:
        radial-gradient(120% 90% at 0% 0%, #0f3b2e 0%, transparent 55%),
        linear-gradient(160deg, var(--a-navy) 0%, #0a1b31 48%, var(--a-night) 100%);
}

.auth-shell .auth-stage__glow {
    position: absolute;
    inset: -20% -10% auto auto;
    width: 70%;
    aspect-ratio: 1;
    border-radius: 50%;
    background: radial-gradient(circle, rgb(1 177 81 / 34%) 0%, rgb(1 177 81 / 10%) 38%, transparent 68%);
    filter: blur(8px);
    z-index: -1;
    animation: auth-breathe 9s ease-in-out infinite;
}

.auth-shell .auth-stage__glow::after {
    content: '';
    position: absolute;
    left: -70%;
    top: 95%;
    width: 80%;
    aspect-ratio: 1;
    border-radius: 50%;
    background: radial-gradient(circle, rgb(110 139 239 / 22%) 0%, transparent 65%);
}

/*
 * The beam: a narrow wedge of warm light whose apex sits on the brand mark,
 * swinging through a small arc. Kept faint enough to read as atmosphere, and
 * still under prefers-reduced-motion.
 */
.auth-shell .auth-stage__beam {
    position: absolute;
    left: 2.6rem;
    top: 2.4rem;
    width: 170vmax;
    height: 170vmax;
    z-index: -1;
    transform-origin: 0 0;
    transform: rotate(18deg);
    background: conic-gradient(from 90deg at 0 0,
        rgb(255 236 190 / 0%) 0deg,
        rgb(255 236 190 / 10%) 5deg,
        rgb(255 236 190 / 4%) 11deg,
        rgb(255 236 190 / 0%) 17deg,
        rgb(255 236 190 / 0%) 360deg);
    -webkit-mask-image: radial-gradient(circle at 0 0, #000 0%, rgb(0 0 0 / 55%) 30%, transparent 62%);
    mask-image: radial-gradient(circle at 0 0, #000 0%, rgb(0 0 0 / 55%) 30%, transparent 62%);
    mix-blend-mode: screen;
    pointer-events: none;
    animation: auth-sweep 16s ease-in-out infinite alternate;
}

/* The masjids motif from the marketing site, faded out toward the form. */
.auth-shell .auth-stage__lattice {
    position: absolute;
    inset: 0;
    z-index: -1;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='64' height='64' viewBox='0 0 64 64'%3E%3Cg fill='none' stroke='rgba(255,255,255,0.11)' stroke-width='1'%3E%3Cpath d='M18 18h28v28H18z'/%3E%3Cpath d='M32 12.2 51.8 32 32 51.8 12.2 32z'/%3E%3Cpath d='M32 0v12.2M32 51.8V64M0 32h12.2M51.8 32H64'/%3E%3Cpath d='M0 0l8 8M64 0l-8 8M0 64l8-8M64 64l-8-8'/%3E%3C/g%3E%3C/svg%3E");
    background-size: 64px 64px;
    -webkit-mask-image: radial-gradient(130% 110% at 0% 100%, #000 10%, transparent 72%);
    mask-image: radial-gradient(130% 110% at 0% 100%, #000 10%, transparent 72%);
}

.auth-shell .auth-stage__inner {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    padding: 1.5rem 1.25rem 4.5rem;
}

.auth-shell .auth-brand {
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
}

.auth-shell .auth-brand__mark {
    position: relative;
    display: inline-flex;
    border-radius: 12px;
    box-shadow: 0 0 0 1px rgb(255 255 255 / 16%), 0 8px 24px rgb(0 0 0 / 35%);
}

.auth-shell .auth-brand__mark::before {
    content: '';
    position: absolute;
    inset: -14px;
    z-index: -1;
    border-radius: 50%;
    background: radial-gradient(circle, rgb(255 226 160 / 28%) 0%, transparent 70%);
}

.auth-shell .auth-brand__mark img {
    display: block;
    width: 40px;
    height: 40px;
    border-radius: 12px;
}

.auth-shell .auth-brand__name {
    font-size: 1.4rem;
    font-weight: 720;
    letter-spacing: -0.02em;
}

.auth-shell .auth-stage__eyebrow {
    margin: 0 0 0.75rem;
    color: var(--a-gold);
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
}

.auth-shell .auth-stage__title {
    margin: 0;
    max-width: 16ch;
    font-size: clamp(1.6rem, 1.2rem + 1.6vw, 3.4rem);
    font-weight: 780;
    line-height: 1.05;
    letter-spacing: -0.03em;
    text-wrap: balance;
}

.auth-shell .auth-stage__lede {
    margin: 1rem 0 0;
    max-width: 44ch;
    color: #cdd6e1;
    font-size: 1.05rem;
    line-height: 1.55;
    font-weight: 420;
}

.auth-shell .auth-products,
.auth-shell .auth-stage__lede,
.auth-shell .auth-stage__foot {
    display: none;
}

.auth-shell .auth-products {
    list-style: none;
    margin: 0;
    padding: 0;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
    max-width: 38rem;
}

.auth-shell .auth-product {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 0.85rem 0.95rem;
    border-radius: 16px;
    background: linear-gradient(180deg, rgb(255 255 255 / 7%), rgb(255 255 255 / 3%));
    box-shadow: inset 0 0 0 1px rgb(255 255 255 / 9%);
    -webkit-backdrop-filter: blur(6px);
    backdrop-filter: blur(6px);
}

.auth-shell .auth-product__icon {
    flex: none;
    display: inline-grid;
    place-items: center;
    width: 38px;
    height: 38px;
    border-radius: 11px;
    color: var(--tint);
    background: color-mix(in srgb, var(--tint) 16%, transparent);
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--tint) 28%, transparent);
}

.auth-shell .auth-product__text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.auth-shell .auth-product__name {
    font-weight: 650;
    font-size: 0.98rem;
}

.auth-shell .auth-product__line {
    color: #b9c4d2;
    font-size: 0.82rem;
    line-height: 1.35;
}

.auth-shell .auth-stage__foot {
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 1.25rem;
    color: #9aa7b8;
    font-size: 0.82rem;
}

.auth-shell .auth-stage__trust {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}

/* ---------- the form side ---------- */
.auth-shell .auth-main {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0 1rem 2rem;
    margin-top: -3rem;
}

.auth-shell .auth-main__body {
    width: 100%;
    max-width: 27rem;
}

.auth-shell .auth-main__foot {
    width: 100%;
    max-width: 27rem;
    margin-top: 1.25rem;
    text-align: center;
    color: var(--a-muted);
    font-size: 0.92rem;
}

.auth-shell .auth-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    padding: 2rem 1.5rem;
    border-radius: 22px;
    background: var(--a-surface);
    box-shadow: 0 1px 2px rgb(16 24 40 / 6%), 0 12px 40px rgb(16 24 40 / 12%), 0 0 0 1px rgb(16 24 40 / 5%);
    animation: auth-rise 0.5s cubic-bezier(0.2, 0.7, 0.2, 1) both;
}

.auth-shell .auth-card__head {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.auth-shell .auth-badge {
    display: inline-grid;
    place-items: center;
    width: 44px;
    height: 44px;
    margin-bottom: 0.5rem;
    border-radius: 13px;
    color: var(--a-brand-strong);
    background: #e7f6ee;
    box-shadow: inset 0 0 0 1px rgb(1 177 81 / 22%);
}

.auth-shell .auth-title {
    margin: 0;
    font-size: 1.75rem;
    font-weight: 750;
    letter-spacing: -0.025em;
    line-height: 1.15;
    color: var(--a-ink);
}

.auth-shell .auth-sub {
    margin: 0;
    color: var(--a-muted);
    font-size: 1rem;
    line-height: 1.5;
}

.auth-shell .auth-fields {
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

.auth-shell .auth-field {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
}

.auth-shell .auth-field--split {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    column-gap: 1rem;
    align-items: baseline;
}

.auth-shell .auth-field--split > * {
    grid-column: 1 / -1;
}

.auth-shell .auth-field--split > .auth-label {
    grid-column: 1;
    grid-row: 1;
}

.auth-shell .auth-field--split > .auth-field__aside {
    grid-column: 2;
    grid-row: 1;
    justify-self: end;
}

.auth-shell .auth-label {
    margin: 0;
    color: var(--a-ink-soft);
    font-size: 0.92rem;
    font-weight: 600;
}

.auth-shell .auth-control {
    position: relative;
    display: flex;
    align-items: center;
}

.auth-shell .auth-control__icon {
    position: absolute;
    z-index: 2;
    left: 0.95rem;
    color: #8a94a3;
    pointer-events: none;
    transition: color 0.15s ease;
}

.auth-shell .auth-control:focus-within .auth-control__icon {
    color: var(--a-brand-strong);
}

.auth-shell .auth-input,
.auth-shell .auth-control .password-input-wrapper input {
    width: 100%;
    height: 3.1rem;
    padding: 0 1rem 0 2.75rem;
    border: 1px solid var(--a-field);
    border-radius: 12px;
    background: #fff;
    color: var(--a-ink);
    font: inherit;
    font-size: 1rem;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.auth-shell .auth-control .password-input-wrapper input {
    padding-right: 3rem;
}

.auth-shell .auth-input::placeholder,
.auth-shell .auth-control .password-input-wrapper input::placeholder {
    color: #98a2b3;
}

.auth-shell .auth-input:hover,
.auth-shell .auth-control .password-input-wrapper input:hover {
    border-color: #5d6675;
}

.auth-shell .auth-input:focus,
.auth-shell .auth-control .password-input-wrapper input:focus {
    border-color: var(--a-brand-strong);
    box-shadow: var(--a-ring);
}

.auth-shell .auth-input[aria-invalid='true'],
.auth-shell .auth-control--invalid .password-input-wrapper input {
    border-color: var(--a-danger);
}

.auth-shell .auth-input[aria-invalid='true']:focus,
.auth-shell .auth-control--invalid .password-input-wrapper input:focus {
    box-shadow: 0 0 0 4px rgb(180 35 24 / 14%);
}

.auth-shell .auth-control .password-toggle {
    right: 0.6rem;
    width: 2.25rem;
    height: 2.25rem;
    justify-content: center;
    border-radius: 9px;
    color: #667085;
    font-size: 1.05rem;
}

.auth-shell .auth-control .password-toggle:hover {
    color: var(--a-ink);
    background: #f2f4f7;
}

.auth-shell .auth-control .password-toggle:focus-visible {
    outline: none;
    box-shadow: var(--a-ring);
}

.auth-shell .auth-input--code {
    height: 3.75rem;
    padding: 0 1rem;
    text-align: center;
    font-size: 1.6rem;
    font-weight: 650;
    letter-spacing: 0.45em;
    font-variant-numeric: tabular-nums;
    text-indent: 0.45em;
}

.auth-shell .auth-input--recovery {
    height: 3.4rem;
    padding: 0 1rem;
    text-align: center;
    font-size: 1.15rem;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.auth-shell .auth-hint,
.auth-shell .auth-error-text {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.86rem;
    line-height: 1.4;
}

.auth-shell .auth-hint { color: var(--a-muted); }
.auth-shell .auth-hint--warn { color: var(--a-warn); font-weight: 600; }
.auth-shell .auth-error-text { color: var(--a-danger); font-weight: 500; }

.auth-shell .auth-alert {
    display: flex;
    gap: 0.7rem;
    align-items: flex-start;
    margin: 0;
    padding: 0.85rem 1rem;
    border-radius: 12px;
    font-size: 0.94rem;
    line-height: 1.45;
}

.auth-shell .auth-alert svg { flex: none; margin-top: 0.1rem; }
.auth-shell .auth-alert--error { color: #7a271a; background: var(--a-danger-soft); box-shadow: inset 0 0 0 1px #fecdca; }
.auth-shell .auth-alert--ok { color: #05603a; background: var(--a-ok-soft); box-shadow: inset 0 0 0 1px #abefc6; }
.auth-shell .auth-alert--warn { color: var(--a-warn); background: var(--a-warn-soft); box-shadow: inset 0 0 0 1px #fedf89; }

.auth-shell .auth-note {
    margin: 0;
    color: var(--a-muted);
    font-size: 0.9rem;
    line-height: 1.5;
}

.auth-shell .auth-actions {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
}

.auth-shell .auth-button {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.55rem;
    width: 100%;
    height: 3.1rem;
    padding: 0 1.25rem;
    border: 0;
    border-radius: 12px;
    color: #fff;
    font: inherit;
    font-size: 1.02rem;
    font-weight: 650;
    letter-spacing: 0.005em;
    background: linear-gradient(180deg, #03a24c 0%, var(--a-brand-strong) 100%);
    box-shadow: 0 1px 0 rgb(255 255 255 / 18%) inset, 0 1px 2px rgb(16 24 40 / 10%), 0 6px 16px rgb(0 122 56 / 24%);
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
}

.auth-shell .auth-button:hover:not(:disabled) {
    filter: brightness(1.05);
    box-shadow: 0 1px 0 rgb(255 255 255 / 18%) inset, 0 2px 4px rgb(16 24 40 / 12%), 0 10px 24px rgb(0 122 56 / 30%);
    transform: translateY(-1px);
}

.auth-shell .auth-button:active:not(:disabled) {
    transform: translateY(0);
    filter: brightness(0.97);
}

.auth-shell .auth-button:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px #fff, 0 0 0 6px rgb(0 122 56 / 55%);
}

.auth-shell .auth-button:disabled {
    cursor: progress;
    opacity: 0.85;
}

.auth-shell a.auth-button--link,
.auth-shell a.auth-button--link:hover {
    color: #fff;
    text-decoration: none;
}

.auth-shell .auth-button__arrow {
    transition: transform 0.2s ease;
}

.auth-shell .auth-button:hover:not(:disabled) .auth-button__arrow {
    transform: translateX(3px);
}

.auth-shell .auth-spinner {
    width: 1.1rem;
    height: 1.1rem;
    border: 2px solid rgb(255 255 255 / 40%);
    border-top-color: #fff;
    border-radius: 50%;
    animation: auth-spin 0.7s linear infinite;
}

.auth-shell .auth-link {
    color: var(--a-brand-strong);
    font-weight: 600;
    text-decoration: none;
    border-radius: 4px;
}

.auth-shell .auth-link:hover {
    color: #005c2a;
    text-decoration: underline;
    text-underline-offset: 3px;
}

.auth-shell .auth-link:focus-visible {
    outline: none;
    box-shadow: var(--a-ring);
}

.auth-shell .auth-link--small { font-size: 0.88rem; }

.auth-shell .auth-link--quiet {
    color: var(--a-muted);
    font-weight: 500;
}

.auth-shell .auth-link--center {
    align-self: center;
    text-align: center;
}

.auth-shell .auth-divider {
    height: 1px;
    margin: 0.25rem 0;
    background: #eaecf0;
}

.auth-shell .auth-family {
    display: flex;
    gap: 0.65rem;
    align-items: flex-start;
    color: var(--a-muted);
    font-size: 0.88rem;
    line-height: 1.45;
}

.auth-shell .auth-family svg {
    flex: none;
    margin-top: 0.1rem;
    color: #98a2b3;
}

@keyframes auth-rise {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: none; }
}

@keyframes auth-breathe {
    0%, 100% { opacity: 0.85; transform: scale(1); }
    50% { opacity: 1; transform: scale(1.04); }
}

@keyframes auth-sweep {
    from { transform: rotate(10deg); }
    to { transform: rotate(52deg); }
}

@keyframes auth-spin {
    to { transform: rotate(360deg); }
}

/* ---------- tablet and up: the stage earns its space ---------- */
@media (min-width: 640px) {
    .auth-shell .auth-stage__inner { padding: 2rem 2rem 5.5rem; }
    .auth-shell .auth-stage__lede { display: block; }
    .auth-shell .auth-card { padding: 2.5rem 2.5rem; }
}

@media (min-width: 992px) {
    .auth-shell {
        grid-template-columns: minmax(0, 1.08fr) minmax(0, 1fr);
    }

    .auth-shell .auth-stage {
        position: sticky;
        top: 0;
        height: 100vh;
        height: 100dvh;
    }

    .auth-shell .auth-stage__inner {
        height: 100%;
        justify-content: space-between;
        gap: 2.5rem;
        padding: clamp(2rem, 4vw, 3.5rem);
    }

    .auth-shell .auth-stage__copy { margin-top: auto; }
    .auth-shell .auth-products { display: grid; }
    .auth-shell .auth-stage__foot { display: flex; margin-top: auto; }

    .auth-shell .auth-main {
        justify-content: center;
        margin-top: 0;
        padding: 3rem clamp(1.5rem, 4vw, 4rem);
        background:
            radial-gradient(60% 50% at 100% 0%, rgb(1 177 81 / 6%), transparent 70%),
            var(--a-bg);
    }

    .auth-shell .auth-card {
        padding: 2.75rem;
        box-shadow: 0 1px 2px rgb(16 24 40 / 5%), 0 18px 50px rgb(16 24 40 / 10%), 0 0 0 1px rgb(16 24 40 / 5%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .auth-shell .auth-card,
    .auth-shell .auth-stage__glow,
    .auth-shell .auth-stage__beam,
    .auth-shell .auth-spinner {
        animation: none;
    }

    .auth-shell .auth-button,
    .auth-shell .auth-button__arrow {
        transition: none;
    }
}
</style>

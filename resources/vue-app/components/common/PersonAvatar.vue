<template>
    <span class="person-avatar" :style="wrapStyle" :title="name || undefined">
        <img v-if="url" :src="url" :style="imgStyle" alt="" loading="lazy">
        <span v-else class="person-avatar__initials" :style="initialsStyle">{{ initials }}</span>
    </span>
</template>

<script setup lang="ts">
import { computed } from 'vue';

/**
 * One person's face, wherever a person appears.
 *
 * Falls back to INITIALS rather than a stock silhouette when no avatar has been
 * chosen: 486 contacts predate this feature and were never asked, and a shared
 * default face would make thirty children look like the same child.
 *
 * ## The head crop
 *
 * The artwork is a full-body chibi (3:4). Shrunk into a 32px circle the child
 * is an unreadable speck, and a centred crop lands on the thobe — so the image
 * is scaled until the HEAD fills the circle and then shifted up.
 *
 * The two fractions are MEASURED from the drawings and differ per character on
 * purpose: Amir's kufi sits on top of his head, while Amira's hijab wraps below
 * her chin, so her head reads taller and lower in the frame. One shared number
 * visibly clips one of the two.
 */
const props = withDefaults(defineProps<{
    avatar?: { character?: string; tone?: string; color?: string; url?: string } | null;
    firstName?: string | null;
    lastName?: string | null;
    size?: number;
    ring?: string | null;
}>(), { size: 36, ring: null });

const HEAD = {
    ameer: { height: 0.42, centerY: 0.30 },
    ameera: { height: 0.48, centerY: 0.35 },
} as const;

const url = computed(() => props.avatar?.url ?? null);
const name = computed(() => [props.firstName, props.lastName].filter(Boolean).join(' '));

const initials = computed(() => {
    const a = (props.firstName ?? '').trim().charAt(0);
    const b = (props.lastName ?? '').trim().charAt(0);
    return ((a + b) || '?').toUpperCase();
});

const head = computed(() => HEAD[(props.avatar?.character as keyof typeof HEAD)] ?? HEAD.ameer);

const wrapStyle = computed(() => ({
    width: `${props.size}px`,
    height: `${props.size}px`,
    borderRadius: '50%',
    overflow: 'hidden',
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    flex: '0 0 auto',
    background: '#eef1f4',
    boxShadow: props.ring ? `0 0 0 2px ${props.ring}` : 'none',
}));

const imgStyle = computed(() => {
    const h = props.size / head.value.height;         // scale so the head fills the circle
    const w = h * (900 / 1200);                       // the artwork's aspect

    // The flex container ALREADY centres the image, so a relative offset
    // compounds with that centring rather than replacing it. MEASURED: using
    // the absolute figure (size/2 − centerY·h) put the visible window on the
    // child's chin, because the item was sitting at (size − h)/2 before the
    // shift was applied. The nudge from centre is therefore:
    //     h·(0.5 − centerY)
    const top = h * (0.5 - head.value.centerY);

    return {
        width: `${w}px`,
        height: `${h}px`,
        maxWidth: 'none',
        // Without this the image is a flex item and shrinks to the circle's
        // width, undoing the zoom in narrow containers.
        flex: '0 0 auto',
        position: 'relative' as const,
        top: `${top}px`,
    };
});

const initialsStyle = computed(() => ({
    fontSize: `${Math.max(10, Math.round(props.size * 0.38))}px`,
    fontWeight: 600,
    color: '#5b6b7a',
    lineHeight: 1,
}));
</script>

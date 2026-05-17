<script setup lang="ts">
/**
 * SafeHtml — XSS-safe replacement for v-html in the admin SPA.
 *
 * Renders admin-authored HTML content (announcements, services, hadiths,
 * azkar, tasabih details) through DOMPurify before injecting it into the
 * DOM. Without this, a compromised MasjidAdmin or any future contributor
 * could persist payloads like
 *   <img src=x onerror="fetch('/api/admin/masjids/...', {credentials:'include'})">
 * that would execute in a SuperAdmin's browser when they view the same content.
 *
 * Usage:
 *   <SafeHtml :html="announcement.details" />
 *   <SafeHtml :html="zikr.text.en" tag="p" class="fs-6 m-0" />
 */
import { computed } from "vue";
import DOMPurify from "dompurify";

const props = withDefaults(defineProps<{ html: string | null | undefined; tag?: string }>(), {
  tag: "div",
});

// Tuned allowlist for admin-rendered Quran/hadith/announcement content.
// Excludes <script>, <iframe>, <object>, <embed>, <form>, <input>, <svg>, <math>,
// and every on* event attribute.
const ALLOWED_TAGS = [
  "p", "br", "hr",
  "strong", "b", "em", "i", "u", "s", "mark", "small", "sub", "sup",
  "ul", "ol", "li",
  "h1", "h2", "h3", "h4", "h5", "h6",
  "blockquote", "pre", "code",
  "a", "span", "div",
  "table", "thead", "tbody", "tr", "th", "td",
];
const ALLOWED_ATTR = ["href", "title", "target", "rel", "class", "style", "id"];

// Force-harden external links — DOMPurify hooks ensure target=_blank + rel=noopener.
DOMPurify.addHook("afterSanitizeAttributes", (node) => {
  if (node.tagName === "A") {
    node.setAttribute("target", "_blank");
    node.setAttribute("rel", "noopener noreferrer");
  }
});

const safe = computed(() => {
  if (!props.html) return "";
  return DOMPurify.sanitize(props.html, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    FORBID_TAGS: ["script", "style", "iframe", "object", "embed", "form", "input", "svg", "math"],
    FORBID_ATTR: ["onerror", "onload", "onclick", "onmouseover", "onfocus", "onblur", "onsubmit", "formaction"],
    KEEP_CONTENT: true,
  });
});
</script>

<template>
  <component :is="tag" v-html="safe" />
</template>

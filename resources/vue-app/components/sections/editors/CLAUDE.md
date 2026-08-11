# sections/editors — page-builder section editors

One `.vue` per `SectionType`. The full add-a-section-type checklist and the
reasoning behind the shapes live in `.claude/rules/section-types.md`; this file
is the local idiom.

## The contract every editor follows

- `defineProps<{ modelValue: <X>SectionContent }>()` +
  `defineEmits<{ 'update:modelValue': [value: <X>SectionContent] }>()`. Nothing
  else. The editor never talks to a store about the section, never saves, and
  never validates — `SectionFormModal` owns all three.
- Copy props into a `localContent` ref through a `normalize()` helper that
  defaults **every** field, `watch(() => props.modelValue, …, { deep: true })`
  re-normalizes, and every input calls `emitUpdate()`. Content authored before a
  field existed must not blow up a `v-for`; default it in `normalize()`.
- Images go through `ImageDraggableInput` + the injected
  `sectionImages` composable, never a bare `<input type="file">`.

## Registering a new editor

`SectionFormModal.vue` holds the import **and** the `editorMap` entry. The map is
`Record<SectionType, …>`, so a missing entry is a type error — but
`npm run build` is `vite build` with **no typecheck**, so it builds happily and
the admin gets a type in the dropdown with a blank editor pane. That has shipped
twice (`events`, `form`). Add both in the same change.

## Uploads: one array level, keyed by position

`sectionImages.addImageFile('members.0.photo_url', file)` — the FormData key is
literally that string; PHP turns the dots into underscores and the controller
maps it back through `getImageFieldsForSectionType`. Two consequences:

- **One wildcard only.** `handleArrayImageUploads` reads a single `(\d+)`, so a
  nested `a.*.b.*.c` pattern silently drops every file. Keep repeatable content
  one level deep — flat list + a grouping label beats a nested tree.
- **Reorder/delete MUST re-key the queue.** Files are queued by index before
  they are uploaded, so moving row 3 above row 2 without re-keying uploads the
  photo onto the wrong record. `CarouselSectionEditor` and
  `StaffDirectorySectionEditor` carry the `remap*Files` helper to copy: clear
  every pending entry first (so a swap cannot overwrite its own counterpart),
  then re-add at the new index; return `null` from the mapper to drop one.

## Arrays of plain strings

`programs[].highlights` and `tiers[].includes` are `string[]`. Bind `:value` and
write back by index from an `@input` handler
(`onHighlightInput`/`onIncludeInput`) rather than relying on `v-model` into an
array slot — it keeps the mutation and the `emitUpdate()` in one place.

## Import path for UploadedImageInfo

`UploadedImageInfo` lives in `@/core/types/elements/ImageInput` (which also
exports `DraggableImageType`). All editors now import it from there — fixed
2026-08-11. Eleven editors used to point at a nonexistent
`@/core/types/data/interfaces/UploadedImageInfo` path and only built because
esbuild elides type-only imports; that path must never come back, and a
typecheck step (vue-tsc) would now pass these files.

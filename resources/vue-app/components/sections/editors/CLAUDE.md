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
  photo onto the wrong record. `CarouselSectionEditor`,
  `StaffDirectorySectionEditor` and `ProvidersDirectorySectionEditor` carry the
  `remap*Files` helper to copy: clear
  every pending entry first (so a swap cannot overwrite its own counterpart),
  then re-add at the new index; return `null` from the mapper to drop one.

## A PDF is an address, not a queued file (the one exception to the uploads above)

Link Buttons, Programs & Curriculum and Call to Action carry `SectionDocumentUpload`
(`components/form/`) under their link field. It is the one place an editor's subtree
uses a bare `<input type="file">` and talks to a store, and it does both on purpose:

- **The file is sent at once** (`pagesStore.uploadPageDocument`), not on Save, and what
  comes back is an absolute address. The editor writes that string into its link field
  (`links[i].url`, `programs[i].link_url`, `button_link`) and calls `emitUpdate()`, as if
  the office had pasted it. Nothing is added to `sectionImages`, and a section's content
  never holds a `File`, a `blob:` or a `data:` value for a document.
- **So there is nothing to re-key**, but rows are still keyed by position: while an upload
  is in flight (`@busy`) the editor turns Add, Move and Remove off, or the address lands
  on the wrong row. Each control's `:key` carries a counter the editor bumps on every move
  and removal, so a refusal shown under one row does not stay behind under another.
- **Only blank companions are filled** (a Link Buttons label and icon, a program's Link
  Text). Words the office chose are theirs.
- The modal is not told an upload is running: Save is not held. A section saved before
  the upload ends is saved without the address, and the file stays online, linked from
  nowhere (see `App\Support\PageDocuments` for what is kept and what is deleted).
- Adding the control to another link field is a template line, an `onDocumentUploaded`
  and, in a list editor, the busy counter and the key. The server needs nothing.

## Arrays of plain strings

`programs[].highlights`, `tiers[].includes` and `eligibility.criteria` are
`string[]`. Bind `:value` and write back by index from an `@input` handler
(`onHighlightInput`/`onIncludeInput`/`onCriterionInput`) rather than relying on
`v-model` into an array slot — it keeps the mutation and the `emitUpdate()` in
one place.

## Import path for UploadedImageInfo

`UploadedImageInfo` lives in `@/core/types/elements/ImageInput` (which also
exports `DraggableImageType`). All editors now import it from there — fixed
2026-08-11. Eleven editors used to point at a nonexistent
`@/core/types/data/interfaces/UploadedImageInfo` path and only built because
esbuild elides type-only imports; that path must never come back, and a
typecheck step (vue-tsc) would now pass these files.

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
- **Copy every ROW, not only the list.** Fields are bound straight onto a row
  (`v-model="link.url"`), so a row that is still the caller's object is written
  into the caller's content as the office types, saved or not.
  `[...value.links]` copies the list and shares every link;
  `value.links.map((link) => ({ ...link }))` is the copy, in the initial value
  AND in the watch, and a list inside a row (`highlights`, `includes`) is copied
  again. `SectionFormModal` also hands every editor a deep copy of its own, so
  the section the page list holds cannot be reached from here; this rule is what
  keeps an editor honest wherever else it is mounted.
  `tests/section-editors-own-copy.test.ts` mounts every editor that has rows:
  add a new one to its list.
- Images go through `ImageDraggableInput` + the injected
  `sectionImages` composable, never a bare `<input type="file">`. (A file that
  is NOT an image has a bare input, because `ImageDraggableInput` decodes
  whatever it is given as a picture: the MP4 in `VideoSectionEditor`, still
  queued on `sectionImages`, and a PDF, below, which is not queued at all.)

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
(`components/form/`) under their link field. It is the one upload in an editor's
subtree that is NOT queued on `sectionImages`: it sends its file through a store
action at once. (It is not the only bare file input, which Video also has, nor the
only use of a store here: several editors read pages or forms from one. The rule
above is that an editor never talks to a store ABOUT THE SECTION, and it still does
not.)

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
  Text). Words the office chose are theirs. When the editor fills one it says so by
  calling `stored.filled('…one sentence…')` on the `uploaded` payload, and the control
  shows that sentence in its "Uploaded." note.
- **Save waits for an upload.** `SectionFormModal` provides a count,
  `sectionDocumentUploads`, beside `sectionImages`; the control raises it when a file
  goes and lowers it when the answer comes, or when the control is unmounted first.
  While it is above zero Create/Update Section is off, the footer says "A PDF is still
  uploading.", `handleSubmit` returns (Enter in a field submits the form and asks no
  button), and Cancel and the close button ask before closing. A NEW section's Section
  Type and its Create New / Attach Existing choice are held too: either takes the
  editor away as closing does, and neither asks. The editors do nothing for this:
  their own `uploadsInFlight` only holds their rows. The count is lowered ONCE for
  each upload (the control's `counted`): one whose control went mid-upload has been
  counted down already, and its late answer must not open Save for another.
- **Closing asks about a PDF that no save has linked.** When the form holds a page
  document the saved section does not (`sectionDocumentsNotSaved`: uploaded, or put
  in by hand, since it was opened), Cancel and the close button ask once, naming the
  file and giving its address, because the file is online already and this form is
  the last screen that shows where. With an upload also in flight it is still one
  question, and its advice is to wait and SAVE (waiting and closing leaves the same
  file online). Nothing is kept for this: it is read from the content when the
  office closes, as the footer's notes are.
- **What is true to say about taking a file offline depends on whether it is SAVED.** The
  server deletes a document when a save stops linking it, compared with the saved
  section, so a file uploaded since the last save is deleted by nothing. The modal
  provides `sectionSavedDocuments`, the page documents in `props.section.content`: the
  section as the page list holds it, which is what was saved when the list was last
  loaded. No editor can write into that object (the modal's form is a deep copy of it,
  made when the modal opens, and so is a new section's default content), so it stays
  the saved content however often the section is opened and cancelled. It used not to:
  Link Buttons edited the list's own link objects, Cancel does not reload the list, and
  the next modal read an abandoned upload as saved, or sent an abandoned clear with the
  next save.
  The control tells a saved document "clear the address and save", an unsaved one that
  clearing or replacing it now leaves it online, and, when an upload replaces an unsaved
  one, shows the replaced file's address, which is then in no field. The modal's footer
  names each saved document the content no longer links, in any field of any editor:
  "This file is taken offline when you save, unless another saved section still links
  it." Never word either of these so that it is false for the other kind of file.
- **Rows are named.** Pass the row's label as `:label` (a button's label, a program's
  name, else "Link 2"): it goes into the accessible names of the button and the Open
  link. The button is never `disabled` while it uploads (that drops the keyboard's
  focus to the page); it is `aria-disabled`, and a press does nothing.
- Adding the control to another link field is a template line, an `onDocumentUploaded`
  and, in a list editor, the busy counter, the key and the label. The modal and the
  server need nothing.

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

# Flyer Studio saved drafts

Flyer Studio starts with the organisation's saved drafts above the design picker.
The table shows title, template, last saved time and creator, with the same 15-row
server pagination and shared pager used by other admin lists. Created by is the
recorded creator; there is no last-editor field. Open fetches the saved row rather
than constructing a new one. Save draft updates that same row, including incomplete
drafts. Change design returns to the list. Delete asks for confirmation and refreshes
the list; Cancel sends no delete request.

The saved content and nonempty palette snapshot take precedence over defaults and
today's brand colours. The Colours control starts at Saved colours; choosing a named
palette or From our brand colours deliberately replaces that snapshot. Empty palette
snapshots (for example drafts created outside the browser) retain the existing fallback
to current brand colours. Templates and HTML are still the currently bundled assets;
there is no historical design markup snapshot and rendering/export code is unchanged.

An inactive server template can still reopen and save. If the bundled design has
disappeared, saved text and downloaded stored images are shown with an explanation;
preview and saving are unavailable and the stored row is retained. Removed slots are
shown as read-only values and unchanged values survive re-saving. New or changed
unknown slot keys continue to be rejected. A failed image download displays a warning
and does not remove its stored file.

Flyer media remains private on the local disk and is downloaded through authenticated
image endpoints. The schema records one subject photo and its cutout, not every image
slot. Logos and other local images were never stored by this editor; it now names
those fields explicitly and explains that they must be added again after reopening.
The original-versus-cutout toggle was never persisted; both saved files are fetched,
and the completed cutout is initially used, matching the model's composite choice.
Adding multi-image persistence or saving that toggle was not part of this change.

Delete checks all flyer rows, including other organisations and finished flyers, in
all three path columns before removing a stored image. Uploads and cutout jobs use
random flyer-specific paths; no other feature refers to these private paths. Existing
photo replacement/removal behaviour was not changed. The existing tenant middleware
and flyer_studio capability gate cover every operation. A different organisation's
flyer ID is a 404, and a different organisation in the route remains a 403.

Validation evidence and exact screen wording are in artifacts/. MySQL and a real
browser/deployed page were not exercised in this offline worktree. The SPA tests mount
the actual Studio, slot form and pager with the real store and a scripted transport;
they do not verify browser layout, fonts or PNG downloads.

# Flyer templates

The designs themselves — data plus plain HTML/CSS. No Blade, no Twig, no build
step: these are rendered **client-side in the admin SPA**, because the droplet
has no Node and no PHP rasteriser here can do flexbox, let alone Arabic shaping.

```
index.json           every template, with its manifest and html
palettes.json        named palettes (including Burlington's, restricted to masjid 1)
palette.js           theme_settings -> CSS custom properties, with the WCAG gate
food.{json,html}     the measured food-sale template
event-banner.*       )
event-invitation.*   ) four event archetypes — events have no single template
event-bulletin.*     )
event-photo.*        )
janazah.*            derived from constants; the corpus had no example
```

Each `.html` is self-contained: its own `<style>`, everything scoped under a
root class (`.flyer-food`, `.flyer-banner`, …) so it can be injected into the
Bootstrap admin SPA without leaking. The duplication between files is
deliberate — a template you can drop in a page on its own is worth more than a
shared stylesheet nobody can render without.

## Measured vs. derived

`food.html` is the only template reproduced from real flyers: ~124 of the 231
Burlington originals reuse one locked layout, made in Bazaart on a phone. Its
zone bands, type sizes and treatments are **measured**, so its zones are pinned
to exact percentages of the canvas. Changing a number there stops the output
looking like the masjid's own flyers.

The ~107 event flyers share no grid, type scale or palette — averaging them
produces something resembling none of them. So there is no event *template*,
there are four **archetypes**, and they use flow layouts rather than pinned
bands because there is no measured truth to pin them to. `janazah` had zero
examples and is derived from constants; every choice in it is a subtraction.

## The three treatments

They are never mixed. A line gets exactly one.

| Treatment | Looks like | Carries |
|---|---|---|
| `.sticker` | Black fill, **white outline behind the fill**, soft down-right shadow | Title, ingredients, price |
| `.pill` | Bold black on a white rounded rect (radius 10px) that hugs its text | The two operational lines: when, deadline |
| `.cta` | White on solid black, lighter weight, near-square corners | Always the phone number. Always last. |

The outline sits behind the fill via `-webkit-text-stroke` plus
`paint-order: stroke fill`. Without `paint-order` the stroke is painted over the
glyph and closes up the counters — the `a` and `e` fill in. The stroke width is
in `em`, so it stays proportional when a long line is shrunk to fit.

**Text inside a pill is never stroked**, and the bar is never stroked either.
Both reset `-webkit-text-stroke` explicitly; that is a rule, not defensive
noise.

**The disclaimer is the deliberate exception** to all three: no box, no outline,
weight 400. That makes it the only line on a food flyer whose legibility depends
on the background — which is what the palette contract below is protecting.

## Palette contract

No template hard-codes a colour it could take from the tenant. Every one reads
CSS custom properties, consumed as `var(--name, fallback)` and never re-declared
inside the template, so a value set on the flyer root **or any ancestor** wins.

| Variable | Used by |
|---|---|
| `--flyer-grad-0` … `--flyer-grad-3` | The vertical gradient ground (stops at 0%, 50%, 80%, 100%) |
| `--flyer-ink` | Unboxed text on the gradient — the food disclaimer, all of the invitation |
| `--flyer-field` / `--flyer-field-ink` | Solid brand ground: banner, bulletin header |
| `--flyer-ground` / `--flyer-ground-ink` | Solid dark ground: janazah |
| `--flyer-accent` | Rules, hairlines, kickers |
| `--flyer-pill-bg` / `--flyer-pill-ink` | The white pill |
| `--flyer-bar-bg` / `--flyer-bar-ink` | The black CTA bar |

Templates also expose per-slot size variables (`--flyer-title-size`, …) so a
shrink-to-fit pass can step a long line down without forking the stylesheet, and
`--flyer-photo-top` so the food template's elastic photo band can claim the
slack a short title leaves. Lowering that value grows the photo; 40.2% is the
floor.

### Burlington's purple is Burlington's

The gradient sampled off the exemplars —
`#A18CCF → #C5A7CB 50% → #E0BCC8 80% → #EDD2D1` — ships as the named palette
`burlington-purple` and is **the default for masjid 1 only**. It is a tenant's
brand, not the product's. Every other masjid defaults to a cool/neutral gradient
*derived from its own* `theme_settings`.

The derivation (`coolNeutralFrom` in `palette.js`) builds a light, low-chroma
ramp at the brand's hue, then pulls each stop halfway toward the neutral
default. The pull is an RGB mix, not a hue rotation: rotating a red brand toward
blue lands it on lavender — a colour the tenant never chose, and uncomfortably
close to Burlington's.

### The contrast gate

This codebase has already shipped a contrast bug once. `resolvePalette()` checks
every ink against the ground it will actually sit on and repairs it before it
reaches a template, so a hand-added palette in `palettes.json` cannot ship an
unreadable line either.

The gradient ink is sampled **where the unboxed text actually is**, not at the
top of the ramp: on the food flyer that is 84% down the canvas, where the
disclaimer sits and the ground is the interpolation of stops 2 and 3. Checking
against stop 0 is how you ship a flyer whose one unboxed line is invisible.

Each manifest names its own point in `palette.contrast_sample` (`food` 0.84,
`event-bulletin` 0.6, `event-invitation` 0.5) and it is **passed in**, not
assumed — the ramp runs dark-to-light downward, so a check at 0.84 is a check
against a lighter ground than the ink gets, and dark ink can pass there while
failing at 0.5. Pass the manifest (or the number) to `resolvePalette`; leaving it
out falls back to 0.84.

`enforceInk()` picks its direction from the two extremes rather than from a
lightness midpoint, because contrast is not linear in lightness: white stops
beating black at a relative luminance of **~0.179**, not 0.5. And when nothing
can clear the bar on a given ground — possible above AA — it **throws** rather
than hand back a colour that fails. Do not catch that and render anyway.

Colours are parsed strictly. A stop or an ink that will not parse throws where it
is still named, instead of becoming black and producing a plausible flyer with
the wrong ground.

`event-photo` is the exception it has to be — the background is user-supplied
and cannot be checked ahead of time. Its fixed dark scrim is the guarantee
instead, which is why the scrim is not decoration and should not be softened.

```js
import { resolvePalette, applyPalette, auditPalette } from './palette.js';

// `manifest` is read for palette.contrast_sample; `contrastSample: 0.5` also works.
// masjidId may be a number or a route-param string — it is normalised either way,
// and a palette with `restricted_to_masjid` is refused on every branch, whether it
// arrives as `palette`, as `named`, or as the masjid-1 default.
const palette = resolvePalette({ theme, masjidId, palettes, manifest });
applyPalette(flyerRootEl, palette);
auditPalette(palette); // [] — assert this in the Studio preview
```

## Slot conventions for the renderer

Substitution is a string/DOM pass over the template, in this order:

1. **`data-repeat="name"`** — the element is a prototype. Clone it once per item
   in the list and substitute the `{{ name.field }}` placeholders inside each
   clone. Only `event-bulletin` uses this today (`sessions`).
2. **`{{ slot }}`** — replace with the slot's value. Placeholders appear in text
   nodes and in `src` attributes.
3. **`data-optional="true"`** — remove the element entirely when its slot is
   empty. Without this an empty optional slot leaves its margins behind.

`data-slot="name"` marks the element that owns a slot, for the Studio's
click-to-edit and for shrink-to-fit measurement.

Escape values before substitution. The templates are trusted; the content is
not.

## Fonts

**Montserrat, self-hosted, weights 400 / 500 / 800 / 900.** Templates never
`@import` or `<link>` a font — the host loads it once. In this app that host is
`resources/views/vue-app-index.blade.php`, which declares a single `@font-face`
over `public/fonts/Montserrat-variable.ttf` with `font-weight: 100 900`; one
variable file covers every weight the templates ask for. It is a first-party
asset rather than a CDN one because the export rasterises what is on screen, and
a font that may or may not have arrived is a flyer that may or may not be right.

Use a weight this file declares. A template that reaches for something else
(`700`) will render — the axis is continuous — but the design system is the four
weights, and the next template will copy whatever it finds here.

Montserrat was confirmed by a glyph bake-off against the alternatives: Nunito's
terminals are too round, Arial Black is too wide and has the wrong `a` and `k`.
Falling back to Helvetica changes the look; it does not break the layout.

Arabic falls outside Montserrat's coverage and resolves to a system Naskh face
(`janazah`, `event-invitation`). If the export path cannot shape Arabic, render
the transliteration alone rather than shipping broken glyphs.

## Rendering and export

The canvas is fixed at **1200×1600 (3:4)** and everything is sized in px at that
reference. For preview, scale the root with a CSS `transform` — do not restyle
it. Export at scale 1 (or 2 for a retina asset).

One thing to know before choosing a rasteriser: `-webkit-text-stroke` and
`paint-order` are engine features, so an exporter that re-implements CSS
painting (html2canvas and friends) will drop the sticker outline — the single
most recognisable thing about these flyers. Prefer a path that lets the browser
do the painting.

That path has one catch worth knowing before you debug it: an SVG rendered **as
an image** — which is what `<foreignObject>` in a `data:` URI is — loads no
external resources, so the app shell's `@font-face` does not reach it however
correctly it is declared. Same-origin is not the issue; being a separate,
resource-blocked document is. The export must carry Montserrat with it, inlined
as a `data:` URI in the serialised markup, or the PNG comes out in Helvetica
while the screen looks right.

## Slots that must be asked for, every time

Three settled decisions the manifests encode as `"ask_always": true` and a
`null` default:

- **The phone number.** The food-order line and the events/RSVP line are not
  interchangeable. Ask; never infer one from a past flyer.
- **The food disclaimer.** It varies per flyer on purpose. Never carry the last
  one forward.
- **The name of the deceased.** Spell it as the family gives it.

## Adding a template

Add `<key>.json` and `<key>.html`, register it in `index.json`, and keep to the
contract: root class scoped, palette read from the variables above, no external
asset, no font import, `{{ slot }}` placeholders only. If it introduces a new
ground, add its ink to `auditPalette()` so the contrast gate covers it too.

## A template is not self-sufficient: it needs the fitter

Zones are absolutely positioned at measured percentages, and a zone is an ink
extent rather than a hard box — text centres in its band and is allowed to bleed
into the gap, which is how the hand-made originals behave. That is deliberate,
and it means **a template rendered on its own will overlap its neighbours as soon
as the text runs long.**

What keeps it correct is `fitText()` in
`resources/vue-app/components/flyer/FlyerPreview.vue`: it shrinks each slot's
`size_var` custom property in up to 8 steps of 0.96, floored at 0.72 of the
declared size. Verified against the longest real ingredient line in the corpus
(134 characters, the Falafel Sandwiches flyer): without the fitter the title,
ingredients and date pill collide; with it the line wraps to four lines and every
zone stays clear.

Two consequences worth knowing before you touch this directory:

- **Do not render a template outside the Studio and judge it.** You are looking
  at the unfitted layout. Export is fine — it rasterises the live, already-fitted
  DOM through `<foreignObject>`, so the fitted sizes carry.
- **`maxLength` is a design constraint, not a data one, and it must clear the
  longest value the masjid actually writes.** The first cut of these caps was
  invented rather than measured and rejected 7 of 9 real ingredient lines — the
  tool would have refused the majority of Burlington's own flyers. Every cap here
  is now derived from the corpus with headroom; the reasoning is recorded in each
  slot's `maxLengthNote`. If you tighten one, check it against a real flyer first.

<?php

namespace App\Http\Requests\Admin\Studio;

/**
 * Validates GET /api/admin/studio/layout-presets?org_type=…
 *
 * The same org_type rule, and the same blank-means-masjid normalisation, as
 * the catalogue: Step 1 and Step 2 read the vertical one way.
 */
class StudioLayoutPresetsRequest extends StudioCatalogueRequest
{
}

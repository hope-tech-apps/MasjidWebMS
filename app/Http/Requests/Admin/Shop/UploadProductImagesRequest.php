<?php

namespace App\Http\Requests\Admin\Shop;

use App\Http\Requests\BaseFormRequest;
use App\Models\Product;

/**
 * Upload pictures of a product (shop slice B2): `images[]`, one request, up to MAX_IMAGES files.
 *
 * Each file is held to the rule every image upload in the admin is held to
 * (ValidatesVideoSection::sectionUploadRules, the sections and pages writers): the BYTES must be
 * an image of an allowed kind (`mimes` reads the file, not the browser's claim) AND the client's
 * file NAME must carry a matching image extension (`extensions`), because the media library keeps
 * the client's file name on the public disk and the web server picks the Content-Type from the
 * extension: JPEG bytes uploaded as `x.html` would be served as a page on this app's own origin.
 * SVG is not on the list for the same reason (it carries script). The size is the library's own
 * ceiling (config/media-library.php `max_file_size`, 25 MB), as the gallery and the section images
 * have always had.
 *
 * The product's ceiling (Product::MAX_IMAGES in all, counting what it already has) cannot be
 * judged here, because the count is only true under the product's row lock:
 * ShopProductImagesController checks it there.
 */
class UploadProductImagesRequest extends BaseFormRequest
{
    /** 25 MB in kilobytes, Laravel's unit for `max` on a file. */
    public const MAX_KB = 25600;

    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:' . Product::MAX_IMAGES],
            'images.*' => [
                'required', 'file',
                'mimes:jpeg,png,jpg,gif,webp',
                'extensions:jpeg,jpg,png,gif,webp',
                'max:' . self::MAX_KB,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'images.required' => 'Choose at least one picture.',
            'images.max' => 'A product can have at most ' . Product::MAX_IMAGES . ' pictures.',
            'images.*.mimes' => 'A picture must be a JPEG, PNG, GIF or WebP image.',
            'images.*.extensions' => 'A picture\'s file name must end in .jpg, .jpeg, .png, .gif or .webp.',
            'images.*.max' => 'A picture can be at most 25 MB.',
            'images.*.file' => 'That is not a picture file.',
        ];
    }
}

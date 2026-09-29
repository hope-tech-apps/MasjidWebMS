<?php

namespace App\Support\Studio;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A logo LogoDerivatives refused to decode (fromFile and generate both) or the
 * Studio draft-logo upload refused to take: too many pixels on an edge, or too
 * many for the memory this process has left. GD holds a whole image in memory
 * at ~4 bytes a pixel and the derive chain costs up to about 11 in all
 * (measured), and a memory fatal cannot be caught, so the size is read from
 * the header and refused BEFORE anything is decoded.
 *
 * A ValidationException so the regenerate route answers the legacy 422
 * ({status:'failed', data:{logo:[...]}}) with no extra code, and its own type
 * so BrandAssets::afterLogoUpload can log the dimensions and skip. The sentence
 * is built here once, so the upload, the regenerate route and provisioning say
 * the same words.
 */
final class LogoTooLarge extends ValidationException
{
    public const EDGE = 'edge';

    public const MEMORY = 'memory';

    /**
     * @param  string  $limit  EDGE or MEMORY: which limit the logo went over
     * @param  int  $maxSide  the largest square, in pixels a side, that would be taken now:
     *                        the edge cap or what the memory left allows, whichever is less
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $limit,
        int $maxSide,
    ) {
        // Said so an admin can act on it alone (the owner, provisioning a
        // client): the logo's size, and the size to upload instead.
        $size = number_format($width) . ' × ' . number_format($height);
        $sentence = $maxSide >= 100
            ? "This logo is {$size} pixels. Upload one no larger than " . number_format($maxSide) . ' × ' . number_format($maxSide) . ' (a PNG or JPEG).'
            : "This logo is {$size} pixels, and there is not enough memory free to make its icons just now. Try again in a moment.";

        $validator = Validator::make([], []);
        $validator->errors()->add('logo', $sentence);

        parent::__construct($validator);
    }
}

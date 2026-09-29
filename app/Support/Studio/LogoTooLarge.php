<?php

namespace App\Support\Studio;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A logo that LogoDerivatives::fromFile refused to decode: too many pixels on
 * an edge, or too many for the memory this process has left. GD holds a whole
 * image in memory at ~4 bytes a pixel, and a memory fatal cannot be caught, so
 * the size is read from the header and refused BEFORE anything is decoded.
 *
 * A ValidationException so the regenerate route answers the legacy 422
 * ({status:'failed', data:{logo:[...]}}) with no extra code, and its own type
 * so BrandAssets::afterLogoUpload can log the dimensions and skip.
 */
final class LogoTooLarge extends ValidationException
{
    public const EDGE = 'edge';

    public const MEMORY = 'memory';

    /**
     * @param  string  $limit  EDGE or MEMORY: which limit the logo went over
     * @param  int  $maxSide  the longest side, in pixels, that would have been accepted
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $limit,
        int $maxSide,
    ) {
        $sentence = $maxSide >= 100
            ? "The logo is too large to make the icons from ({$width}×{$height}). Upload a smaller logo, at most {$maxSide} pixels on each side."
            : "The logo is too large to make the icons from ({$width}×{$height}) just now. Try again in a moment, or upload a much smaller logo.";

        $validator = Validator::make([], []);
        $validator->errors()->add('logo', $sentence);

        parent::__construct($validator);
    }
}

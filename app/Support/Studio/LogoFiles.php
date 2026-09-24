<?php

namespace App\Support\Studio;

/**
 * The client's logo and the three derivatives LogoDerivatives made from it, as
 * absolute paths in one temporary directory that StudioProvisioning deletes
 * whatever happens.
 */
final readonly class LogoFiles
{
    public function __construct(
        public string $directory,
        public string $logo,
        public string $favicon,
        public string $touchIcon,
        public string $shareImage,
    ) {}
}

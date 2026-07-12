<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * Thin wrapper over pragmarx/google2fa (TOTP) + bacon/bacon-qr-code (QR render).
 *
 * Shared by the enrollment controller and the login flow so secret generation
 * and code verification live in exactly one place. Deliberately NOT Laravel
 * Fortify — Fortify would restructure the existing Sanctum auth; this is an
 * additive, self-contained helper. See .claude/rules/auth-permissions.md.
 */
class TwoFactorService
{
    public function __construct(private Google2FA $engine)
    {
    }

    /** Generate a fresh base32 TOTP secret to hand to an enrolling user. */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * Build the standard otpauth:// URI (issuer + account label + secret) that
     * authenticator apps consume.
     */
    public function otpauthUri(string $holder, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            $this->issuer(),
            $holder,
            $secret,
        );
    }

    /**
     * Render the otpauth URI as an inline SVG data-URI (no external service, no
     * temp files) so the client can display the QR directly.
     */
    public function qrCodeDataUri(string $otpauthUri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200, 1),
            new SvgImageBackEnd(),
        );

        $svg = (new Writer($renderer))->writeString($otpauthUri);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Verify a submitted 6-digit code against the secret. verifyKey allows a
     * ±1 time-step window to tolerate small clock drift.
     */
    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code) !== false;
    }

    private function issuer(): string
    {
        return config('app.name', 'Masjid');
    }
}

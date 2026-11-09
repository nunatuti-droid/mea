<?php
declare(strict_types=1);

namespace Mea\Module\Auth;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use OTPHP\TOTP as OtpTotp;
use Psr\Clock\ClockInterface;

/**
 * TOTP nach RFC 6238 (Bibliothek spomky-labs/otphp). Zeitfenster ±30 Sekunden.
 * Gibt den verwendeten Zeitschritt zurück, damit ein Code nicht zweimal gilt.
 */
final class Totp
{
    public const PERIOD = 30;

    public function __construct(private ClockInterface $clock) {}

    public function newSecret(): string
    {
        return OtpTotp::generate($this->clock)->getSecret();
    }

    /** @return int|null akzeptierter Zeitschritt oder null */
    public function verify(string $secret, string $code, ?int $lastStep): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $totp = OtpTotp::createFromSecret($secret, $this->clock);
        $now = intdiv($this->clock->now()->getTimestamp(), self::PERIOD);
        foreach ([0, -1, 1] as $offset) {
            $step = $now + $offset;
            if ($lastStep !== null && $step <= $lastStep) {
                continue; // bereits verwendet
            }
            if (hash_equals($totp->at($step * self::PERIOD), $code)) {
                return $step;
            }
        }
        return null;
    }

    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $totp = OtpTotp::createFromSecret($secret, $this->clock);
        $totp->setLabel($account);
        $totp->setIssuer($issuer);
        return $totp->getProvisioningUri();
    }

    /** QR-Code als SVG-Data-URI (kein externer Dienst). */
    public function qrDataUri(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd()));
        return 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($uri));
    }
}

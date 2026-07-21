<?php
declare(strict_types=1);

namespace Mampf;

use RuntimeException;

final class ReweAccessException extends RuntimeException
{
    public static function cloudflareChallenge(): self
    {
        return new self(
            message: 'REWE blockiert den Zugriff mit einer Cloudflare-Menschprüfung (HTTP 403). ' .
                'Erneuere die REWE-Cookies wie unten beschrieben und starte den Lauf danach erneut.'
        );
    }
}

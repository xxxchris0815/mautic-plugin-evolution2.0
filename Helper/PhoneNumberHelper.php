<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Helper;

/**
 * Normalizes phone numbers for WhatsApp / Evolution API.
 */
class PhoneNumberHelper
{
    public static function normalize(string $phone, string $defaultCountryCode = '55'): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        $country = preg_replace('/\D+/', '', $defaultCountryCode) ?: '55';

        if (str_starts_with($digits, $country)) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
        }

        $length = strlen($digits);
        if ($length >= 8 && $length <= 12) {
            return $country.$digits;
        }

        return $digits;
    }

    public static function fromJid(string $jid): string
    {
        $user = preg_replace('/@.*$/', '', $jid) ?? '';

        return preg_replace('/\D+/', '', $user) ?? '';
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Helper;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\TokenHelper as LeadTokenHelper;

/**
 * Resolves Mautic contact tokens inside WhatsApp messages and maps.
 */
class TokenHelper
{
    /**
     * @param array<string, mixed> $leadData
     */
    public static function replace(string $text, array $leadData): string
    {
        $text = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '{contactfield=$1}', $text) ?? $text;

        return LeadTokenHelper::findLeadTokens($text, $leadData, true);
    }

    public static function replaceForLead(string $text, Lead $lead): string
    {
        return self::replace($text, $lead->getProfileFields());
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return array<string, mixed>
     */
    public static function replaceMap(array $map, Lead $lead, bool $keepStrings = true): array
    {
        $leadData = $lead->getProfileFields();
        $out = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }
            $resolved = is_string($value) ? self::replace($value, $leadData) : $value;
            $out[trim($key)] = $keepStrings ? $resolved : self::castScalar($resolved);
        }

        return $out;
    }

    public static function castScalar(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }
        $trim = trim($value);
        if (strcasecmp($trim, 'true') === 0) {
            return true;
        }
        if (strcasecmp($trim, 'false') === 0) {
            return false;
        }
        if (strcasecmp($trim, 'null') === 0) {
            return null;
        }
        if (is_numeric($trim)) {
            return str_contains($trim, '.') ? (float) $trim : (int) $trim;
        }

        return $value;
    }
}

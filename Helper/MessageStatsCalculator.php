<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Helper;

/**
 * Normalizes WhatsApp send funnel counts into rates used by campaign analysis,
 * template pages and reports.
 */
class MessageStatsCalculator
{
    /**
     * @return array{
     *     pending: int,
     *     sent: int,
     *     delivered: int,
     *     read: int,
     *     failed: int,
     *     replied: int,
     *     total: int,
     *     accepted: int,
     *     reached: int,
     *     delivery_rate: float,
     *     read_rate: float,
     *     reply_rate: float,
     *     fail_rate: float
     * }
     */
    public static function empty(): array
    {
        return self::withRates(self::zeroCounts());
    }

    /**
     * @param array<string, int|string> $counts
     *
     * @return array{
     *     pending: int,
     *     sent: int,
     *     delivered: int,
     *     read: int,
     *     failed: int,
     *     replied: int,
     *     total: int,
     *     accepted: int,
     *     reached: int,
     *     delivery_rate: float,
     *     read_rate: float,
     *     reply_rate: float,
     *     fail_rate: float
     * }
     */
    public static function withRates(array $counts): array
    {
        $pending = (int) ($counts['pending'] ?? 0);
        $sent = (int) ($counts['sent'] ?? 0);
        $delivered = (int) ($counts['delivered'] ?? 0);
        $read = (int) ($counts['read'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $replied = (int) ($counts['replied'] ?? 0);
        $total = (int) ($counts['total'] ?? ($pending + $sent + $delivered + $read + $failed));

        $accepted = $sent + $delivered + $read;
        $reached = $delivered + $read;
        $attempted = $accepted + $failed;

        return [
            'pending' => $pending,
            'sent' => $sent,
            'delivered' => $delivered,
            'read' => $read,
            'failed' => $failed,
            'replied' => $replied,
            'total' => $total,
            'accepted' => $accepted,
            'reached' => $reached,
            'delivery_rate' => self::rate($reached, $accepted),
            'read_rate' => self::rate($read, $accepted),
            'reply_rate' => self::rate($replied, $accepted),
            'fail_rate' => self::rate($failed, $attempted),
        ];
    }

    public static function formatPercent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'%';
    }

    /**
     * @return array{pending: int, sent: int, delivered: int, read: int, failed: int, replied: int, total: int}
     */
    private static function zeroCounts(): array
    {
        return [
            'pending' => 0,
            'sent' => 0,
            'delivered' => 0,
            'read' => 0,
            'failed' => 0,
            'replied' => 0,
            'total' => 0,
        ];
    }

    private static function rate(int $part, int $whole): float
    {
        if ($whole < 1) {
            return 0.0;
        }

        return round(($part / $whole) * 100, 2);
    }
}

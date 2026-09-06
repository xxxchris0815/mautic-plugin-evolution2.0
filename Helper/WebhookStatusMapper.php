<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Helper;

/**
 * Maps Evolution / WhatsApp ack statuses onto plugin message statuses.
 */
class WebhookStatusMapper
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';

    /**
     * @param array<string, mixed> $updateData
     */
    public static function extractMessageId(array $updateData): ?string
    {
        $candidates = [
            $updateData['keyId'] ?? null,
            $updateData['key']['id'] ?? null,
            $updateData['id'] ?? null,
            $updateData['messageId'] ?? null,
            $updateData['key']['messageId'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $updateData
     */
    public static function extractRawStatus(array $updateData): ?string
    {
        $status = $updateData['status'] ?? ($updateData['update']['status'] ?? null);
        if (is_int($status) || is_float($status)) {
            return (string) $status;
        }

        return is_string($status) && $status !== '' ? $status : null;
    }

    public static function map(string $rawStatus): ?string
    {
        $normalized = strtoupper(trim($rawStatus));

        return match ($normalized) {
            'PENDING', 'PENDING_ACK', '0' => self::STATUS_PENDING,
            'SERVER_ACK', 'SENT', '1' => self::STATUS_SENT,
            'DELIVERY_ACK', 'DELIVERED', '2' => self::STATUS_DELIVERED,
            'READ', 'READ_ACK', 'PLAYED', '3', '4' => self::STATUS_READ,
            'ERROR', 'FAILED', 'EXPIRED', 'DELETED' => self::STATUS_FAILED,
            default => null,
        };
    }

    public static function isTerminalUpgrade(string $current, string $incoming): bool
    {
        $rank = [
            self::STATUS_PENDING => 0,
            self::STATUS_SENT => 1,
            self::STATUS_DELIVERED => 2,
            self::STATUS_READ => 3,
            self::STATUS_FAILED => 4,
        ];

        if ($incoming === self::STATUS_FAILED) {
            return true;
        }

        return ($rank[$incoming] ?? -1) > ($rank[$current] ?? -1);
    }
}

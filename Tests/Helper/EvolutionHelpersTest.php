<?php

declare(strict_types=1);

use MauticPlugin\MauticEvolutionBundle\Helper\PhoneNumberHelper;
use MauticPlugin\MauticEvolutionBundle\Helper\TemplatePayloadBuilder;
use MauticPlugin\MauticEvolutionBundle\Helper\WebhookStatusMapper;
use PHPUnit\Framework\TestCase;

final class PhoneNumberHelperTest extends TestCase
{
    public function testNormalizePrependsCountryCode(): void
    {
        $this->assertSame('4915112345678', PhoneNumberHelper::normalize('015112345678', '49'));
        $this->assertSame('5511999999999', PhoneNumberHelper::normalize('(11) 99999-9999', '55'));
    }

    public function testNormalizeKeepsExistingCountryCode(): void
    {
        $this->assertSame('4915112345678', PhoneNumberHelper::normalize('+49 151 12345678', '49'));
    }

    public function testFromJid(): void
    {
        $this->assertSame('5511999999999', PhoneNumberHelper::fromJid('5511999999999@s.whatsapp.net'));
    }
}

final class WebhookStatusMapperTest extends TestCase
{
    public function testExtractMessageIdFromKeyId(): void
    {
        $this->assertSame('ABC', WebhookStatusMapper::extractMessageId(['keyId' => 'ABC']));
        $this->assertSame('DEF', WebhookStatusMapper::extractMessageId(['key' => ['id' => 'DEF']]));
    }

    /**
     * @dataProvider statusProvider
     */
    public function testMap(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, WebhookStatusMapper::map($raw));
    }

    public static function statusProvider(): array
    {
        return [
            ['SERVER_ACK', WebhookStatusMapper::STATUS_SENT],
            ['DELIVERY_ACK', WebhookStatusMapper::STATUS_DELIVERED],
            ['READ', WebhookStatusMapper::STATUS_READ],
            ['PLAYED', WebhookStatusMapper::STATUS_READ],
            ['ERROR', WebhookStatusMapper::STATUS_FAILED],
            ['2', WebhookStatusMapper::STATUS_DELIVERED],
            ['unknown', null],
        ];
    }

    public function testTerminalUpgrade(): void
    {
        $this->assertTrue(WebhookStatusMapper::isTerminalUpgrade('sent', 'delivered'));
        $this->assertFalse(WebhookStatusMapper::isTerminalUpgrade('read', 'delivered'));
        $this->assertTrue(WebhookStatusMapper::isTerminalUpgrade('read', 'failed'));
    }
}

final class TemplatePayloadBuilderTest extends TestCase
{
    public function testExtractPlaceholdersAndBody(): void
    {
        $components = [
            ['type' => 'BODY', 'text' => 'Hello {{1}}, your code is {{2}}'],
        ];

        $this->assertSame(['1', '2'], TemplatePayloadBuilder::extractBodyPlaceholders($components));
        $this->assertSame('Hello {{1}}, your code is {{2}}', TemplatePayloadBuilder::extractBodyText($components));
    }

    public function testBuildSendComponents(): void
    {
        $components = [
            ['type' => 'BODY', 'text' => 'Hello {{1}}'],
        ];
        $send = TemplatePayloadBuilder::buildSendComponents($components, ['1' => 'Anna']);

        $this->assertSame('body', $send[0]['type']);
        $this->assertSame('Anna', $send[0]['parameters'][0]['text']);
    }

    public function testNormalizeFindResponse(): void
    {
        $payload = [
            'data' => [
                [
                    'id' => '1',
                    'name' => 'welcome',
                    'language' => 'de',
                    'status' => 'APPROVED',
                    'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}']],
                ],
            ],
        ];

        $templates = TemplatePayloadBuilder::normalizeFindResponse($payload);
        $this->assertCount(1, $templates);
        $this->assertSame('welcome', $templates[0]['name']);
        $this->assertSame('APPROVED', $templates[0]['status']);
    }
}

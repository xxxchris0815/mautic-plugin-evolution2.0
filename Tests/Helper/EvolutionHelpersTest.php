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

    public function testBuildCreateComponentsIncludesExamplesAndButtons(): void
    {
        $components = TemplatePayloadBuilder::buildCreateComponents([
            'headerType' => 'TEXT',
            'headerText' => 'Order {{1}}',
            'headerExample' => '12345',
            'body' => 'Hello {{1}}, order {{2}} is ready.',
            'exampleValues' => ['Anna', '12345'],
            'footer' => 'Thanks',
            'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Help'],
                ['type' => 'URL', 'text' => 'Track', 'url' => 'https://example.com/{{1}}', 'example' => 'abc'],
            ],
        ]);

        $this->assertSame('HEADER', $components[0]['type']);
        $this->assertSame(['header_text' => ['12345']], $components[0]['example']);
        $this->assertSame('BODY', $components[1]['type']);
        $this->assertSame([['Anna', '12345']], $components[1]['example']['body_text']);
        $this->assertSame('FOOTER', $components[2]['type']);
        $this->assertSame('BUTTONS', $components[3]['type']);
        $this->assertSame('URL', $components[3]['buttons'][1]['type']);
        $this->assertSame(['abc'], $components[3]['buttons'][1]['example']);
    }

    public function testFromFormDataBuildsPayloadAndParameterFields(): void
    {
        $built = TemplatePayloadBuilder::fromFormData([
            'name' => 'Order Update',
            'language' => 'de',
            'category' => 'UTILITY',
            'allowCategoryChange' => true,
            'headerType' => 'NONE',
            'body' => 'Hallo {{1}}',
            'exampleValues' => "Max\n",
            'button1Type' => 'QUICK_REPLY',
            'button1Text' => 'OK',
            'button2Type' => 'NONE',
            'button3Type' => 'NONE',
            'paramField1' => 'firstname',
        ]);

        $this->assertSame('order_update', $built['payload']['name']);
        $this->assertSame('de', $built['payload']['language']);
        $this->assertSame('UTILITY', $built['payload']['category']);
        $this->assertTrue($built['payload']['allowCategoryChange']);
        $this->assertSame('{contactfield=firstname}', $built['parameterFields']['1']);
        $this->assertSame('QUICK_REPLY', $built['payload']['components'][1]['buttons'][0]['type']);
    }

    public function testExtractErrorMessageFromMetaEnvelope(): void
    {
        $this->assertSame(
            'Template name already exists',
            TemplatePayloadBuilder::extractErrorMessage([
                'error' => true,
                'response' => [
                    'message' => 'Template name already exists',
                ],
            ])
        );
        $this->assertSame(
            'Invalid parameter',
            TemplatePayloadBuilder::extractErrorMessage([
                'error' => [
                    'error_user_msg' => 'Invalid parameter',
                    'message' => 'invalid',
                ],
            ])
        );
        $this->assertNull(TemplatePayloadBuilder::extractErrorMessage(['success' => true]));
    }
}

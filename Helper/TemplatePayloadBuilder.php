<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Helper;

/**
 * Builds Evolution API WhatsApp Business template payloads and extracts variables.
 */
class TemplatePayloadBuilder
{
    /**
     * @param array<int, array<string, mixed>> $components
     *
     * @return list<string>
     */
    public static function extractBodyPlaceholders(array $components): array
    {
        $placeholders = [];

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            $type = strtoupper((string) ($component['type'] ?? ''));
            if ($type !== 'BODY') {
                continue;
            }
            $text = (string) ($component['text'] ?? '');
            if (preg_match_all('/\{\{(\d+)\}\}/', $text, $matches)) {
                foreach ($matches[1] as $index) {
                    $placeholders[] = (string) $index;
                }
            }
        }

        return array_values(array_unique($placeholders));
    }

    /**
     * Build sendTemplate components from contact values.
     *
     * @param array<int, array<string, mixed>> $templateComponents  Meta-style template definition
     * @param array<string, string>            $parameterValues     Map of "1" => "John", "header" => "https://..."
     *
     * @return list<array<string, mixed>>
     */
    public static function buildSendComponents(array $templateComponents, array $parameterValues): array
    {
        $send = [];

        foreach ($templateComponents as $component) {
            if (!is_array($component)) {
                continue;
            }
            $type = strtolower((string) ($component['type'] ?? ''));
            if ($type === '') {
                continue;
            }

            if ($type === 'header') {
                $headerComponent = self::buildHeaderComponent($component, $parameterValues);
                if ($headerComponent !== null) {
                    $send[] = $headerComponent;
                }
                continue;
            }

            if ($type === 'body') {
                $placeholders = [];
                $text = (string) ($component['text'] ?? '');
                if (preg_match_all('/\{\{(\d+)\}\}/', $text, $matches)) {
                    $placeholders = $matches[1];
                }
                $example = $component['example']['body_text'][0] ?? [];
                $count = max(count($placeholders), is_array($example) ? count($example) : 0);
                if ($count === 0 && isset($parameterValues['1'])) {
                    $count = self::maxNumericKey($parameterValues);
                }
                if ($count < 1) {
                    continue;
                }
                $parameters = [];
                for ($i = 1; $i <= $count; ++$i) {
                    $parameters[] = [
                        'type' => 'text',
                        'text' => (string) ($parameterValues[(string) $i] ?? $parameterValues[$i] ?? ''),
                    ];
                }
                $send[] = [
                    'type' => 'body',
                    'parameters' => $parameters,
                ];
                continue;
            }

            if ($type === 'button' || $type === 'buttons') {
                $buttons = $component['buttons'] ?? [$component];
                if (!is_array($buttons)) {
                    continue;
                }
                $index = 0;
                foreach ($buttons as $button) {
                    if (!is_array($button)) {
                        continue;
                    }
                    $subType = strtolower((string) ($button['type'] ?? $component['sub_type'] ?? ''));
                    if ($subType === 'url' && !empty($parameterValues['button_'.$index])) {
                        $send[] = [
                            'type' => 'button',
                            'sub_type' => 'url',
                            'index' => (string) $index,
                            'parameters' => [
                                [
                                    'type' => 'text',
                                    'text' => (string) $parameterValues['button_'.$index],
                                ],
                            ],
                        ];
                    }
                    ++$index;
                }
            }
        }

        if ($send === [] && self::maxNumericKey($parameterValues) > 0) {
            $parameters = [];
            $max = self::maxNumericKey($parameterValues);
            for ($i = 1; $i <= $max; ++$i) {
                $parameters[] = [
                    'type' => 'text',
                    'text' => (string) ($parameterValues[(string) $i] ?? $parameterValues[$i] ?? ''),
                ];
            }
            $send[] = [
                'type' => 'body',
                'parameters' => $parameters,
            ];
        }

        return $send;
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, string> $parameterValues
     *
     * @return array<string, mixed>|null
     */
    private static function buildHeaderComponent(array $header, array $parameterValues): ?array
    {
        $format = strtolower((string) ($header['format'] ?? 'TEXT'));
        $headerValue = $parameterValues['header'] ?? ($parameterValues['0'] ?? null);

        if ($format === 'text') {
            $text = (string) ($header['text'] ?? '');
            if (!preg_match('/\{\{1\}\}/', $text) && $headerValue === null) {
                return null;
            }

            return [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => 'text',
                        'text' => (string) ($headerValue ?? ''),
                    ],
                ],
            ];
        }

        if (in_array($format, ['image', 'video', 'document'], true) && is_string($headerValue) && $headerValue !== '') {
            return [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => $format,
                        $format => ['link' => $headerValue],
                    ],
                ],
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function maxNumericKey(array $values): int
    {
        $max = 0;
        foreach ($values as $key => $value) {
            if (is_numeric($key) && (int) $key > $max && $value !== '' && $value !== null) {
                $max = (int) $key;
            }
        }

        return $max;
    }

    /**
     * Normalize Evolution / Meta find-templates payloads into a list of templates.
     *
     * @param mixed $payload
     *
     * @return list<array<string, mixed>>
     */
    public static function normalizeFindResponse(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $lists = [];
        if (array_is_list($payload)) {
            $lists[] = $payload;
        }
        foreach (['data', 'templates', 'waba_templates', 'messageTemplates'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $lists[] = $payload[$key];
            }
        }
        if (isset($payload['data']['data']) && is_array($payload['data']['data'])) {
            $lists[] = $payload['data']['data'];
        }

        $templates = [];
        foreach ($lists as $list) {
            foreach ($list as $item) {
                if (!is_array($item) || empty($item['name'])) {
                    continue;
                }
                $templates[] = [
                    'id' => (string) ($item['id'] ?? $item['hsm_id'] ?? ''),
                    'name' => (string) $item['name'],
                    'language' => (string) ($item['language'] ?? $item['language_code'] ?? ''),
                    'status' => strtoupper((string) ($item['status'] ?? 'UNKNOWN')),
                    'category' => strtoupper((string) ($item['category'] ?? '')),
                    'components' => is_array($item['components'] ?? null) ? $item['components'] : [],
                    'rejectedReason' => $item['rejected_reason'] ?? ($item['rejectedReason'] ?? null),
                ];
            }
        }

        return $templates;
    }

    /**
     * Extract a preview body string from components.
     *
     * @param array<int, array<string, mixed>> $components
     */
    public static function extractBodyText(array $components): string
    {
        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                return (string) ($component['text'] ?? '');
            }
        }

        return '';
    }
}

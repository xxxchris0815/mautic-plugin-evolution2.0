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

    /**
     * Build the Evolution / Meta create-template payload from the Mautic form.
     *
     * @param array<string, mixed> $input
     *
     * @return array{payload: array<string, mixed>, parameterFields: array<string, string>}
     */
    public static function fromFormData(array $input): array
    {
        $examples = $input['exampleValues'] ?? [];
        if (is_string($examples)) {
            $input['exampleValues'] = self::linesToList($examples);
        }
        $input['buttons'] = self::buttonsFromForm($input);

        return [
            'payload' => self::buildCreatePayload($input),
            'parameterFields' => self::extractParameterFields($input),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public static function buildCreatePayload(array $input): array
    {
        $name = strtolower(trim((string) ($input['name'] ?? '')));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?? $name;
        $name = trim($name, '_');

        return [
            'name' => $name,
            'language' => (string) ($input['language'] ?? 'en_US'),
            'category' => strtoupper((string) ($input['category'] ?? 'UTILITY')),
            'allowCategoryChange' => (bool) ($input['allowCategoryChange'] ?? true),
            'components' => self::buildCreateComponents($input),
        ];
    }

    /**
     * Meta Cloud API create-template components (HEADER / BODY / FOOTER / BUTTONS).
     *
     * @param array<string, mixed> $input
     *
     * @return list<array<string, mixed>>
     */
    public static function buildCreateComponents(array $input): array
    {
        $components = [];
        $headerType = strtoupper((string) ($input['headerType'] ?? 'NONE'));

        if ($headerType === 'TEXT') {
            $headerText = trim((string) ($input['headerText'] ?? ''));
            if ($headerText !== '') {
                $header = [
                    'type' => 'HEADER',
                    'format' => 'TEXT',
                    'text' => $headerText,
                ];
                if (preg_match('/\{\{1\}\}/', $headerText) === 1) {
                    $example = trim((string) ($input['headerExample'] ?? ''));
                    $header['example'] = [
                        'header_text' => [$example !== '' ? $example : 'Example'],
                    ];
                }
                $components[] = $header;
            }
        } elseif (in_array($headerType, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $header = [
                'type' => 'HEADER',
                'format' => $headerType,
            ];
            $url = trim((string) ($input['headerMediaUrl'] ?? ''));
            if ($url !== '') {
                $header['example'] = [
                    'header_handle' => [$url],
                ];
            }
            $components[] = $header;
        }

        $body = trim((string) ($input['body'] ?? ''));
        $bodyComponent = [
            'type' => 'BODY',
            'text' => $body,
        ];
        $placeholders = [];
        if (preg_match_all('/\{\{(\d+)\}\}/', $body, $matches)) {
            $placeholders = array_map('intval', $matches[1]);
        }
        $examples = is_array($input['exampleValues'] ?? null)
            ? array_values($input['exampleValues'])
            : self::linesToList((string) ($input['exampleValues'] ?? ''));
        if ($placeholders !== []) {
            $max = max($placeholders);
            $bodyExamples = [];
            for ($i = 1; $i <= $max; ++$i) {
                $example = $examples[$i - 1] ?? null;
                $bodyExamples[] = is_string($example) && $example !== '' ? $example : ('Example'.$i);
            }
            $bodyComponent['example'] = [
                'body_text' => [$bodyExamples],
            ];
        }
        $components[] = $bodyComponent;

        $footer = trim((string) ($input['footer'] ?? ''));
        if ($footer !== '') {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $footer,
            ];
        }

        $buttons = self::normalizeButtons($input['buttons'] ?? []);
        if ($buttons !== []) {
            $components[] = [
                'type' => 'BUTTONS',
                'buttons' => $buttons,
            ];
        }

        return $components;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    public static function extractParameterFields(array $input): array
    {
        $fields = [];
        for ($i = 1; $i <= 10; ++$i) {
            $value = trim((string) ($input['paramField'.$i] ?? ''));
            if ($value === '') {
                continue;
            }
            $fields[(string) $i] = str_starts_with($value, '{')
                ? $value
                : '{contactfield='.$value.'}';
        }

        return $fields;
    }

    /**
     * Pull a human-readable error out of Evolution / Meta JSON error envelopes.
     */
    public static function extractErrorMessage(mixed $payload): ?string
    {
        if (is_string($payload)) {
            $trim = trim($payload);
            if ($trim === '' || strcasecmp($trim, 'HTTP error') === 0) {
                return null;
            }
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                return self::extractErrorMessage($decoded);
            }

            return $trim;
        }

        if (!is_array($payload)) {
            return null;
        }

        foreach (['error_user_msg', 'error_user_title', 'message', 'errorMessage', 'msg'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $value = trim($payload[$key]);
                if ($value !== '' && strcasecmp($value, 'HTTP error') !== 0) {
                    return $value;
                }
            }
        }

        if (isset($payload['error'])) {
            if (is_string($payload['error'])) {
                $value = trim($payload['error']);
                if ($value !== '' && !in_array(strtolower($value), ['true', 'false', '1', '0', 'http error'], true)) {
                    return $value;
                }
            } elseif (is_array($payload['error'])) {
                $nested = self::extractErrorMessage($payload['error']);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        foreach (['data', 'response', 'body', 'details'] as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                continue;
            }
            $nested = self::extractErrorMessage($payload[$key]);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * @param mixed $buttons
     *
     * @return list<array<string, mixed>>
     */
    public static function normalizeButtons(mixed $buttons): array
    {
        if (!is_array($buttons)) {
            return [];
        }

        $normalized = [];
        foreach ($buttons as $button) {
            if (!is_array($button)) {
                continue;
            }
            $type = strtoupper(trim((string) ($button['type'] ?? '')));
            if ($type === '' || $type === 'NONE') {
                continue;
            }
            $text = trim((string) ($button['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $row = [
                'type' => $type,
                'text' => $text,
            ];
            if ($type === 'URL') {
                $url = trim((string) ($button['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $row['url'] = $url;
                $example = trim((string) ($button['example'] ?? ''));
                if ($example !== '') {
                    $row['example'] = [$example];
                } elseif (str_contains($url, '{{')) {
                    $row['example'] = ['example'];
                }
            }
            if ($type === 'PHONE_NUMBER') {
                $phone = trim((string) ($button['phone_number'] ?? ($button['phone'] ?? '')));
                if ($phone === '') {
                    continue;
                }
                $row['phone_number'] = $phone;
            }
            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<array<string, mixed>>
     */
    private static function buttonsFromForm(array $input): array
    {
        if (isset($input['buttons']) && is_array($input['buttons']) && $input['buttons'] !== []) {
            return self::normalizeButtons($input['buttons']);
        }

        $buttons = [];
        for ($i = 1; $i <= 3; ++$i) {
            $buttons[] = [
                'type' => $input['button'.$i.'Type'] ?? 'NONE',
                'text' => $input['button'.$i.'Text'] ?? '',
                'url' => $input['button'.$i.'Url'] ?? '',
                'example' => $input['button'.$i.'UrlExample'] ?? '',
                'phone_number' => $input['button'.$i.'Phone'] ?? '',
            ];
        }

        return self::normalizeButtons($buttons);
    }

    /**
     * @return list<string>
     */
    private static function linesToList(string $value): array
    {
        $parts = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $list = [];
        foreach ($parts as $part) {
            $trim = trim((string) $part);
            if ($trim !== '') {
                $list[] = $trim;
            }
        }

        return $list;
    }
}

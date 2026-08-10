<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Helper;

/**
 * Parses Meta/WhatsApp Business templates returned by Evolution API v2
 * and builds sendTemplate components from mapped variables.
 */
class WhatsAppTemplateHelper
{
    /**
     * Normalize Evolution/Meta template list response into a flat array of templates.
     *
     * @return array<int, array<string, mixed>>
     */
    public function normalizeTemplateList(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        // Common shapes: { data: [...] }, { templates: [...] }, or a bare list
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        } elseif (isset($payload['templates']) && is_array($payload['templates'])) {
            $payload = $payload['templates'];
        }

        if ($this->isList($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        // Single template object
        if (isset($payload['name'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * Build dropdown choices: "Name (lang) [STATUS]" => "name|language"
     *
     * @param array<int, array<string, mixed>> $templates
     *
     * @return array<string, string>
     */
    public function toChoices(array $templates, bool $approvedOnly = true): array
    {
        $choices = [];
        foreach ($templates as $template) {
            $name = (string) ($template['name'] ?? '');
            $language = (string) ($template['language'] ?? ($template['language']['code'] ?? ''));
            if ($name === '' || $language === '') {
                continue;
            }

            $status = strtoupper((string) ($template['status'] ?? ''));
            if ($approvedOnly && $status !== '' && $status !== 'APPROVED') {
                continue;
            }

            $label = sprintf('%s (%s)', $name, $language);
            if ($status !== '') {
                $label .= ' [' . $status . ']';
            }

            $choices[$label] = $name . '|' . $language;
        }

        ksort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        return $choices;
    }

    /**
     * Extract variable placeholders for UI mapping.
     *
     * @return array<int, array{key: string, label: string, component: string, index: int, example?: string, button_sub_type?: string}>
     */
    public function extractVariables(array $template): array
    {
        $variables = [];
        $components = $template['components'] ?? [];
        if (!is_array($components)) {
            return [];
        }

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }

            $type = strtoupper((string) ($component['type'] ?? ''));

            if ($type === 'BODY' || $type === 'HEADER') {
                $text = (string) ($component['text'] ?? '');
                if ($text === '' || !preg_match_all('/\{\{(\d+)\}\}/', $text, $matches)) {
                    continue;
                }

                $examples = [];
                if ($type === 'BODY') {
                    $examples = $component['example']['body_text'][0] ?? [];
                } elseif ($type === 'HEADER') {
                    $examples = $component['example']['header_text'] ?? [];
                    if (isset($examples[0]) && is_array($examples[0])) {
                        $examples = $examples[0];
                    }
                }

                foreach ($matches[1] as $placeholder) {
                    $idx = (int) $placeholder;
                    $key = strtolower($type) . '.' . $idx;
                    $variables[$key] = [
                        'key' => $key,
                        'label' => sprintf('%s parameter {{%d}}', ucfirst(strtolower($type)), $idx),
                        'component' => strtolower($type),
                        'index' => $idx,
                        'example' => is_array($examples) ? (string) ($examples[$idx - 1] ?? '') : '',
                        'text' => $text,
                    ];
                }
            }

            if ($type === 'BUTTONS' && isset($component['buttons']) && is_array($component['buttons'])) {
                foreach ($component['buttons'] as $buttonIndex => $button) {
                    if (!is_array($button)) {
                        continue;
                    }
                    $buttonType = strtoupper((string) ($button['type'] ?? ''));
                    $url = (string) ($button['url'] ?? '');
                    if ($buttonType !== 'URL' || !str_contains($url, '{{')) {
                        continue;
                    }

                    $key = 'button.' . $buttonIndex;
                    $variables[$key] = [
                        'key' => $key,
                        'label' => sprintf('Button %d URL parameter', (int) $buttonIndex),
                        'component' => 'button',
                        'index' => (int) $buttonIndex,
                        'example' => (string) ($button['example'][0] ?? ''),
                        'button_sub_type' => 'url',
                        'text' => $url,
                    ];
                }
            }
        }

        return array_values($variables);
    }

    /**
     * Find one template by name + language.
     *
     * @param array<int, array<string, mixed>> $templates
     */
    public function findTemplate(array $templates, string $name, string $language): ?array
    {
        foreach ($templates as $template) {
            $tName = (string) ($template['name'] ?? '');
            $tLang = (string) ($template['language'] ?? ($template['language']['code'] ?? ''));
            if ($tName === $name && $tLang === $language) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Build Evolution sendTemplate components from mapped variable values.
     *
     * Expected map keys: body.1, header.1, button.0, ...
     *
     * @param array<string, string> $variableValues
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildComponents(array $variableValues, ?array $templateDefinition = null): array
    {
        $headerParams = [];
        $bodyParams = [];
        $buttonComponents = [];

        foreach ($variableValues as $key => $value) {
            $key = strtolower(trim((string) $key));
            $value = (string) $value;

            if (preg_match('/^header\.(\d+)$/', $key, $m)) {
                $headerParams[(int) $m[1]] = $value;
            } elseif (preg_match('/^body\.(\d+)$/', $key, $m)) {
                $bodyParams[(int) $m[1]] = $value;
            } elseif (preg_match('/^button\.(\d+)$/', $key, $m)) {
                $buttonComponents[(int) $m[1]] = [
                    'type' => 'button',
                    'sub_type' => $this->resolveButtonSubType((int) $m[1], $templateDefinition),
                    'index' => (string) $m[1],
                    'parameters' => [
                        ['type' => 'text', 'text' => $value],
                    ],
                ];
            }
        }

        $components = [];

        if (!empty($headerParams)) {
            ksort($headerParams);
            $components[] = [
                'type' => 'header',
                'parameters' => array_map(
                    static fn (string $text): array => ['type' => 'text', 'text' => $text],
                    array_values($headerParams)
                ),
            ];
        }

        if (!empty($bodyParams)) {
            ksort($bodyParams);
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    static fn (string $text): array => ['type' => 'text', 'text' => $text],
                    array_values($bodyParams)
                ),
            ];
        }

        if (!empty($buttonComponents)) {
            ksort($buttonComponents);
            foreach ($buttonComponents as $buttonComponent) {
                $components[] = $buttonComponent;
            }
        }

        return $components;
    }

    private function resolveButtonSubType(int $index, ?array $templateDefinition): string
    {
        if ($templateDefinition === null) {
            return 'url';
        }

        foreach (($templateDefinition['components'] ?? []) as $component) {
            if (!is_array($component) || strtoupper((string) ($component['type'] ?? '')) !== 'BUTTONS') {
                continue;
            }
            $button = $component['buttons'][$index] ?? null;
            if (!is_array($button)) {
                continue;
            }
            $type = strtolower((string) ($button['type'] ?? 'url'));

            return $type === 'url' ? 'url' : $type;
        }

        return 'url';
    }

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}

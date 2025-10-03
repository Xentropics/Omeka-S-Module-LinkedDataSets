<?php

declare(strict_types=1);

namespace LinkedDataSets\Core;

/**
 * Helper to build non-destructive update payloads for Custom Vocab.
 *
 * Rules:
 * - If item_set provided → prefer it and clear terms/uris.
 * - If uris provided → merge associative by key (URI), prefer incoming labels.
 * - If terms provided → append + dedupe (preserve order as much as possible).
 * - Never delete existing entries that are not overridden.
 * - Supports Omeka S v3 (string formats) and v4 (array formats).
 */
final class CustomVocabMergeHelper
{
    /**
     * Build a single API update payload for a Custom Vocab.
     *
     * @param \CustomVocab\Api\Representation\CustomVocabRepresentation $existing
     * @param array $incoming Keys may include: o:label, o:item_set, o:uris, o:terms
     * @return array Update payload suitable for $api->update('custom_vocabs', $id, $payload, [], ['isPartial' => true])
     */
    public function buildUpdatePayload($existing, array $incoming): array
    {
        $isV4 = version_compare(\Omeka\Module::VERSION, '4', '>=');

        $label   = $incoming['o:label']   ?? null;
        $itemSet = $incoming['o:item_set'] ?? null;
        $uris    = $incoming['o:uris']    ?? [];
        $terms   = $incoming['o:terms']   ?? [];

        // Normalize incoming to arrays (internal) first.
        $normIncoming = [
            'label'   => $label ?? $existing->label(),
            'itemSet' => $itemSet ? (int) $itemSet : null,
            'uris'    => $this->normalizeUrisToArray($uris),
            'terms'   => $this->normalizeTermsToArray($terms),
        ];

        // Normalize existing to arrays (internal).
        $normExisting = [
            'label'   => $existing->label(),
            'itemSet' => $existing->itemSet() ? (int) $existing->itemSet()->id() : null,
            'uris'    => $this->normalizeUrisToArray($existing->uris()),
            'terms'   => $this->normalizeTermsToArray($existing->terms()),
        ];

        // Decide which merge path to take.
        if ($normIncoming['itemSet']) {
            // Prefer item_set; clear terms/uris.
            return $isV4
                ? [
                    'o:label'    => $normIncoming['label'],
                    'o:item_set' => $normIncoming['itemSet'],
                    'o:terms'    => [],
                    'o:uris'     => [],
                ]
                : [
                    'o:label'    => $normIncoming['label'],
                    'o:item_set' => $normIncoming['itemSet'],
                    'o:terms'    => '',
                    'o:uris'     => '',
                ];
        }

        if (!empty($normIncoming['uris'])) {
            // Merge URIs by key; incoming labels override.
            $mergedUris = $this->mergeUris($normExisting['uris'], $normIncoming['uris']);
            return $isV4
                ? [
                    'o:label'    => $normIncoming['label'],
                    'o:item_set' => null,
                    'o:terms'    => [],
                    'o:uris'     => $mergedUris,
                ]
                : [
                    'o:label'    => $normIncoming['label'],
                    'o:item_set' => null,
                    'o:terms'    => '',
                    'o:uris'     => $this->urisArrayToString($mergedUris),
                ];
        }

        if (!empty($normIncoming['terms'])) {
            // Append + dedupe terms.
            $mergedTerms = $this->mergeTerms($normExisting['terms'], $normIncoming['terms']);
            return $isV4
                ? [
                    'o:label'    => $normIncoming['label'],
                    'o:item_set' => null,
                    'o:terms'    => $mergedTerms,
                    'o:uris'     => [],
                ]
                : [
                    'o:label'    => $normIncoming['label'],
                    'o:item_set' => null,
                    'o:terms'    => implode("\n", $mergedTerms),
                    'o:uris'     => '',
                ];
        }

        // Only label change or no-ops.
        return [
            'o:label' => $normIncoming['label'],
        ];
    }

    /**
     * Normalize URIs input (string|array) to an associative array: [uri => label].
     * Supported string format (v3): "uri = label" per line.
     *
     * @param mixed $uris
     * @return array<string,string>
     */
    public function normalizeUrisToArray($uris): array
    {
        if (is_array($uris)) {
            // Could be assoc or list ["uri = label", ...]; accept both.
            $assoc = [];
            foreach ($uris as $k => $v) {
                if (is_string($k)) {
                    $assoc[trim((string) $k)] = trim((string) $v);
                } elseif (is_string($v)) {
                    [$u, $l] = array_map('trim', explode('=', $v, 2) + [1 => '']);
                    if ($u !== '') {
                        $assoc[$u] = $l !== '' ? $l : $u;
                    }
                }
            }
            return $assoc;
        }

        if (is_string($uris) && $uris !== '') {
            $assoc = [];
            foreach (preg_split('/\r?\n/', $uris) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                [$u, $l] = array_map('trim', explode('=', $line, 2) + [1 => '']);
                if ($u !== '') {
                    $assoc[$u] = $l !== '' ? $l : $u;
                }
            }
            return $assoc;
        }

        return [];
    }

    /**
     * Normalize terms (string|array) to a list of strings.
     *
     * @param mixed $terms
     * @return string[]
     */
    public function normalizeTermsToArray($terms): array
    {
        if (is_array($terms)) {
            // Accept plain array or newline-embedded single string.
            $flat = [];
            foreach ($terms as $t) {
                if (!is_string($t)) {
                    continue;
                }
                foreach (preg_split('/\r?\n/', $t) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $flat[] = $line;
                    }
                }
            }
            return $flat;
        }

        if (is_string($terms) && $terms !== '') {
            $flat = [];
            foreach (preg_split('/\r?\n/', $terms) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $flat[] = $line;
                }
            }
            return $flat;
        }

        return [];
    }

    /**
     * Merge two URI maps (existing, incoming), preferring incoming labels.
     *
     * @param array<string,string> $existing
     * @param array<string,string> $incoming
     * @return array<string,string>
     */
    public function mergeUris(array $existing, array $incoming): array
    {
        // array_replace keeps keys; later arrays override earlier ones.
        return array_replace($existing, $incoming);
    }

    /**
     * Merge two term lists (append + dedupe, preserve first-seen order).
     *
     * @param string[] $existing
     * @param string[] $incoming
     * @return string[]
     */
    public function mergeTerms(array $existing, array $incoming): array
    {
        $seen = [];
        $out = [];
        foreach ([$existing, $incoming] as $list) {
            foreach ($list as $term) {
                $k = mb_strtolower($term);
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $out[] = $term;
            }
        }
        return $out;
    }

    /**
     * Convert a URI map to v3 string format.
     *
     * @param array<string,string> $uris
     */
    public function urisArrayToString(array $uris): string
    {
        $lines = [];
        foreach ($uris as $u => $label) {
            $lines[] = $u . ' = ' . $label;
        }
        return implode("\n", $lines);
    }
}

<?php

declare(strict_types=1);

namespace LinkedDataSets\Core;

final class ResourceTemplateMergeHelper
{
    /**
     * Normalize a RT property entry which may be an array or a Representation object.
     */
    private function normalizeProp($prop): array
    {
        if (is_array($prop)) {
            return $prop;
        }
        if (is_object($prop)) {
            // Most Omeka representations implement JsonSerializable.
            if (method_exists($prop, 'jsonSerialize')) {
                $arr = $prop->jsonSerialize();
                if (is_array($arr)) {
                    return $arr;
                }
            }
            // Last resort: try public properties (unlikely needed).
            return get_object_vars($prop);
        }
        return [];
    }

    /**
     * Normalize an Omeka resource reference (object or array) to ['o:id' => int] or null.
     */
    private function normalizeRef($ref): ?array
    {
        if (is_array($ref)) {
            if (isset($ref['o:id']) && is_numeric($ref['o:id'])) {
                return ['o:id' => (int) $ref['o:id']];
            }
            // Sometimes jsonSerialize uses '@id' or contains embedded 'o:id' deeper.
            if (isset($ref['@id']) && preg_match('~/api/[^/]+/(\d+)$~', (string) $ref['@id'], $m)) {
                return ['o:id' => (int) $m[1]];
            }
            return null;
        }
        if (is_object($ref)) {
            // Common Omeka representations expose id() or getId().
            if (method_exists($ref, 'id')) {
                return ['o:id' => (int) $ref->id()];
            }
            if (method_exists($ref, 'getId')) {
                return ['o:id' => (int) $ref->getId()];
            }
            // Try jsonSerialize as last resort.
            if (method_exists($ref, 'jsonSerialize')) {
                $arr = $ref->jsonSerialize();
                return $this->normalizeRef($arr);
            }
        }
        return null;
    }

    /**
     * Walk RT properties and normalize o:property to ['o:id'=>int] only.
     */
    private function normalizeProperties(array $props): array
    {
        foreach ($props as &$p) {
            if (!is_array($p)) {
                $p = $this->normalizeProp($p);
            }
            if (isset($p['o:property'])) {
                $norm = $this->normalizeRef($p['o:property']);
                if ($norm) {
                    $p['o:property'] = $norm;
                } else {
                    // if cannot normalize, drop to avoid sending objects
                    unset($p['o:property']);
                }
            }
        }
        return $props;
    }

    public function merge(array $existingArr, array $incoming): array
    {
        $existingProperties = $existingArr['o:resource_template_property'] ?? [];
        $incomingProperties = $incoming['o:resource_template_property'] ?? [];

        $existingMap = [];
        foreach ($existingProperties as $property) {
            $p = $this->normalizeProp($property);
            $vocab = $p['o:vocabulary_namespace'] ?? $p['vocabulary_namespace_uri'] ?? '';
            $localName = $p['o:local_name'] ?? $p['local_name'] ?? '';
            $key = $vocab . '|' . $localName;
            $existingMap[$key] = $p;
        }

        $incomingMap = [];
        foreach ($incomingProperties as $property) {
            $p = $this->normalizeProp($property);
            $vocab = $p['o:vocabulary_namespace'] ?? $p['vocabulary_namespace'] ?? $p['vocabulary_namespace_uri'] ?? '';
            $localName = $p['o:local_name'] ?? $p['local_name'] ?? '';
            $key = $vocab . '|' . $localName;
            $incomingMap[$key] = $p;
        }

        $mergedMap = $existingMap;

        foreach ($incomingMap as $key => $incomingProperty) {
            if (!isset($mergedMap[$key])) {
                // Add new property from incoming if not in existing
                $mergedMap[$key] = $incomingProperty;
                continue;
            }

            // Merge properties that exist in both
            $existingProperty = $this->normalizeProp($mergedMap[$key]);
            $incomingProperty = $this->normalizeProp($incomingProperty);

            // Union o:data_type
            $existingDataType = $existingProperty['o:data_type'] ?? null;
            $incomingDataType = $incomingProperty['o:data_type'] ?? null;
            if ($existingDataType !== $incomingDataType) {
                $types = array_filter([$existingDataType, $incomingDataType]);
                $types = array_unique($types);
                if (count($types) === 1) {
                    $mergedDataType = reset($types);
                } else {
                    $mergedDataType = array_values($types);
                }
                $existingProperty['o:data_type'] = $mergedDataType;
            } else {
                $existingProperty['o:data_type'] = $existingDataType;
            }

            // Merge o:data[0]['data_types'] by name, preferring incoming definitions
            $existingData = $existingProperty['o:data'][0] ?? [];
            $incomingData = $incomingProperty['o:data'][0] ?? [];

            $existingDataTypes = $existingData['data_types'] ?? [];
            $incomingDataTypes = $incomingData['data_types'] ?? [];

            $dataTypesMap = [];
            foreach ($existingDataTypes as $dt) {
                if (isset($dt['name'])) {
                    $dataTypesMap[$dt['name']] = $dt;
                }
            }
            foreach ($incomingDataTypes as $dt) {
                if (isset($dt['name'])) {
                    // Prefer incoming definitions
                    $dataTypesMap[$dt['name']] = $dt;
                }
            }
            $mergedDataTypes = array_values($dataTypesMap);
            $existingData['data_types'] = $mergedDataTypes;

            // For every other key in o:data[0], overwrite with incoming value if present
            foreach ($incomingData as $keyData => $valueData) {
                if ($keyData === 'data_types') {
                    continue;
                }
                $existingData[$keyData] = $valueData;
            }

            $existingProperty['o:data'][0] = $existingData;

            // If o:property has o:id, keep it from incoming
            if (isset($incomingProperty['o:property']['o:id'])) {
                $existingProperty['o:property']['o:id'] = $incomingProperty['o:property']['o:id'];
            }

            $mergedMap[$key] = $existingProperty;
        }

        // Preserve original property order for better UX (name, description, logical groups)
        // ksort($mergedMap, SORT_STRING);

        $mergedProperties = array_values($mergedMap);

        // Normalize resource class and properties to pure identifier arrays.
        $normalizedResourceClass = null;
        if (isset($incoming['o:resource_class'])) {
            $normalizedResourceClass = $this->normalizeRef($incoming['o:resource_class']);
        } elseif (isset($existingArr['o:resource_class'])) {
            $normalizedResourceClass = $this->normalizeRef($existingArr['o:resource_class']);
        }
        $mergedProperties = $this->normalizeProperties($mergedProperties);

        // Update o:label from incoming if present; resource_class normalized to id array.
        $mergedArr = $existingArr;
        if (isset($incoming['o:label'])) {
            $mergedArr['o:label'] = $incoming['o:label'];
        }
        if ($normalizedResourceClass) {
            $mergedArr['o:resource_class'] = $normalizedResourceClass;
        } else {
            unset($mergedArr['o:resource_class']);
        }

        $mergedArr['o:resource_template_property'] = $mergedProperties;

        return $mergedArr;
    }
}

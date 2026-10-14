<?php

namespace App\Services\Schools;

/** Pure header matching: ambiguous or unknown names stay unassigned. */
class RosterColumnMatcher
{
    public const BUILT_IN_MAPPINGS = [];

    public const SYNONYMS = [
        'class' => ['class', 'class name', 'homeroom', 'home room', 'classroom'],
        'student_first_name' => ['student first name', 'first name', 'given name', 'student given name'],
        'student_last_name' => ['student last name', 'last name', 'surname', 'family name', 'student surname', 'student family name'],
        'grade' => ['grade', 'grade level', 'year level', 'student grade'],
        'guardian_first_name' => ['guardian first name', 'guardian given name'],
        'guardian_last_name' => ['guardian last name', 'guardian surname', 'guardian family name'],
        'guardian_email' => ['guardian email', 'guardian email address'],
        'guardian_phone' => ['guardian phone', 'guardian phone number', 'guardian mobile', 'guardian mobile number', 'guardian telephone'],
    ];

    public function normalize(string $name): string
    {
        $name = preg_replace('/^\xEF\xBB\xBF/', '', $name);
        $name = preg_replace('/(?<=[a-z])(?=[A-Z])|(?<=\pL)(?=\pN)|(?<=\pN)(?=\pL)/u', ' ', $name);
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^\pL\pN]+/u', ' ', $name);
        $name = preg_replace('/\bmother\b/u', 'guardian 1', $name);
        $name = preg_replace('/\bfather\b/u', 'guardian 2', $name);
        $name = preg_replace('/\bparent\b/u', 'guardian', $name);
        $name = preg_replace('/\bone\b/u', '1', $name);
        $name = preg_replace('/\btwo\b/u', '2', $name);
        $name = preg_replace('/\be mail\b/u', 'email', $name);
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    public function signature(array $headers): string
    {
        return hash('sha256', json_encode(array_map($this->normalize(...), $headers), JSON_THROW_ON_ERROR));
    }

    public function fields(): array
    {
        return array_merge(RosterImportService::HEADERS, [
            'guardian_2_first_name', 'guardian_2_last_name', 'guardian_2_email', 'guardian_2_phone',
        ]);
    }

    public function propose(array $headers): array
    {
        $candidates = array_fill_keys($this->fields(), []);
        foreach ($headers as $i => $header) {
            $name = $this->normalize((string) $header);
            $second = preg_match('/^guardian 2 /', $name) === 1;
            $base = preg_replace('/^guardian [12] /', 'guardian ', $name);
            foreach (self::SYNONYMS as $field => $aliases) {
                if (in_array($base, $aliases, true)) {
                    $target = $second ? str_replace('guardian_', 'guardian_2_', $field) : $field;
                    $candidates[$target][] = $i;
                }
            }
        }
        $mapping = array_map(fn ($indices) => count($indices) === 1 ? $indices[0] : null, $candidates);
        $counts = array_count_values(array_filter($mapping, fn ($i) => $i !== null));
        return array_map(fn ($i) => $i !== null && $counts[$i] === 1 ? $i : null, $mapping);
    }
}

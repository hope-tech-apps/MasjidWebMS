<?php

namespace App\Support\Guides;

/** The release tool's plain-text wire format, shared by validation and source links. */
class GuideAskText
{
    public static function parse(string $text, string $file): array
    {
        // The tool emits one terminal LF; accept its absence, never extra blank lines.
        if (str_ends_with($text, "\n")) $text = substr($text, 0, -1);
        $parts = explode("\n\n", $text);
        $header = array_shift($parts);
        if (! preg_match('/^=== (.+) ===$/uD', $header, $title)) throw new GuideValidationException('ask-title', $file);
        $blocks = []; $questionsStarted = false;
        foreach ($parts as $part) {
            $lines = explode("\n", $part);
            if (count($lines) !== 2 || trim($lines[1]) === '' || ! preg_match('/^### (Task|Common question) \[(.+)\]: (.+)$/uD', $lines[0], $match)) throw new GuideValidationException('ask-block', $file);
            if ($match[1] === 'Common question') $questionsStarted = true;
            elseif ($questionsStarted) throw new GuideValidationException('ask-order', $file);
            $blocks[] = ['kind' => $match[1], 'id' => $match[2], 'title' => $match[3]];
        }
        return ['title' => $title[1], 'blocks' => $blocks];
    }
}

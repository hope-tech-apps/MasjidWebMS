<?php

namespace Tests\Support;

final class LegacyMethodContract
{
    public static function body(string $path, string $name): string
    {
        $tokens = token_get_all(file_get_contents(base_path($path)));
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_FUNCTION) continue;
            $j = $i + 1;
            while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if (! is_array($tokens[$j]) || $tokens[$j][1] !== $name) continue;
            while ($tokens[$j] !== '{') $j++;
            $depth = 1; $body = '';
            while ($depth > 0) {
                $token = $tokens[++$j];
                if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) $depth++;
                if ($token === '{') $depth++;
                if ($token === '}') $depth--;
                if ($depth > 0) $body .= is_array($token) ? $token[1] : $token;
            }
            return $body;
        }
        throw new \RuntimeException("Missing legacy method {$path}:{$name}");
    }
}

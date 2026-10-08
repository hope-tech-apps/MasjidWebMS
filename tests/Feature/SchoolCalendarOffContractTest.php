<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchoolCalendarOffContractTest extends TestCase
{
    /** Extract tokens rather than counting braces in quoted error messages. */
    private function body(string $source, string $method): string
    {
        $tokens = token_get_all($source); $found = false; $name = false; $depth = 0; $body = '';
        foreach ($tokens as $token) {
            if (! $found) {
                if (is_array($token) && $token[0] === T_FUNCTION) $name = true;
                elseif ($name && is_array($token) && $token[0] === T_STRING) { $found = $token[1] === $method; $name = false; }
                continue;
            }
            if ($depth === 0) { if ($token === '{') $depth = 1; continue; }
            if ($token === '{') $depth++;
            if ($token === '}') { $depth--; if ($depth === 0) return $body; }
            $body .= is_array($token) ? $token[1] : $token;
        }
        $this->fail('Missing method '.$method);
    }

    #[Test]
    public function every_original_body_survives_verbatim_after_the_dispatch(): void
    {
        $root = base_path('tests/fixtures/calendar-baseline');
        foreach (json_decode(file_get_contents($root.'/bodies.json'), true) as $path => $methods) {
            foreach ($methods as $method => $contract) {
                $current = $this->body(file_get_contents(base_path($path)), $method);
                $this->assertSame($contract['sha256'], hash('sha256', substr($current, -$contract['bytes'])), "$path::$method OFF body changed");
            }
        }
    }

    #[Test]
    public function slice_c_authority_and_payload_are_unchanged(): void
    {
        foreach (json_decode(file_get_contents(base_path('tests/fixtures/calendar-baseline/readers.json')), true) as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', base_path($path)), $path);
        }
    }
    #[Test]
    public function office_methods_keep_their_original_off_bodies(): void
    {
        $source = file_get_contents(base_path('resources/vue-app/views/dashboard/SchoolCalendarView.vue'));
        foreach (json_decode(file_get_contents(base_path('tests/fixtures/calendar-baseline/ui-methods.json')), true) as $method => $contract) {
            $start = strpos($source, 'const '.$method.' =');
            $start = strpos($source, '=> {', $start) + 4;
            $depth = 1; $end = $start;
            while ($depth > 0) {
                if ($source[$end] === '{') $depth++;
                if ($source[$end] === '}') $depth--;
                $end++;
            }
            $body = substr($source, $start, $end - $start - 1);
            $this->assertSame($contract['sha256'], hash('sha256', substr($body, -$contract['bytes'])), $method.' OFF body changed');
        }
    }

}

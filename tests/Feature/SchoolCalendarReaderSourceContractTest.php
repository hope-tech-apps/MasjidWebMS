<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchoolCalendarReaderSourceContractTest extends TestCase
{
    #[Test]
    public function every_main_reader_body_survives_verbatim_after_dispatch(): void
    {
        $contracts = json_decode(file_get_contents(base_path('tests/fixtures/calendar-readers/bodies.json')), true);
        foreach ($contracts as $path => $methods) {
            $tokens = token_get_all(file_get_contents(base_path($path)));
            $found = []; $pending = false; $method = null; $depth = 0; $body = '';
            foreach ($tokens as $token) {
                if ($depth > 0) {
                    if ($token === '{') $depth++;
                    if ($token === '}') { $depth--; if ($depth === 0) { $found[$method] = $body; $method = null; continue; } }
                    $body .= is_array($token) ? $token[1] : $token; continue;
                }
                if (is_array($token) && $token[0] === T_FUNCTION) { $pending = true; continue; }
                if ($pending && is_array($token) && $token[0] === T_STRING) { $method = $token[1]; $pending = false; }
                if ($method !== null && $token === '{') { $depth = 1; $body = ''; }
            }
            foreach ($methods as $name => $contract) $this->assertSame($contract['sha256'], hash('sha256', substr($found[$name], -$contract['bytes'])), $path.'::'.$name.' main body changed');
        }
    }
}

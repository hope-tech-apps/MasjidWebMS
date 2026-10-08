<?php

dataset('verbatim origin OFF methods', fn () => collect(json_decode(file_get_contents(__DIR__.'/../fixtures/ClassSubjects/off-methods.json'), true))
    ->mapWithKeys(fn ($contract) => [$contract['path'].'::'.$contract['method'] => [$contract]])->all());

it('keeps origin 02d8b3a1 method bodies verbatim behind first feature dispatch', function (array $contract) {
    $body = \Tests\Support\LegacyMethodContract::body($contract['path'], $contract['method']);
    if ($contract['gate']) {
        expect(trim($body))->toStartWith('if (');
        $body = preg_replace('/\A\s*if[^\{]+\{.*?\}/s', '', $body, 1, $removed);
        expect($removed)->toBe(1);
    }
    // Hashes were derived with git show origin/main:<path>, never from HEAD output.
    expect(hash('sha256', trim($body)))->toBe($contract['sha256']);
})->with('verbatim origin OFF methods');

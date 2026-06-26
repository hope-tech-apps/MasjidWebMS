<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Bind the application TestCase to every test under Feature/ and Unit/ so
| Pest boots the Laravel application (and the `config`, `db`, etc. container
| bindings) before each test's own setUp() runs.
|
*/

uses(TestCase::class)->in('Feature', 'Unit');

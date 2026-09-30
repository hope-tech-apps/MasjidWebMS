<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A route registered twice is invisible to the router: it keeps ONE route per
 * verb and URI (the second replaces the first in its collection), so no request
 * test, and no `Route::getRoutes()` count, can see a duplicate. What it costs is
 * that a later edit (a middleware, a name, a fence) to one copy silently does not
 * apply, or applies to the copy nobody reads. `PUT grade-weights` was registered
 * twice by a merge slip (each copy with its own comment block); this reads the
 * file, which is the only place the second copy shows.
 */
class TeacherRoutesRegisteredOnceTest extends TestCase
{
    #[Test]
    public function put_grade_weights_is_registered_exactly_once(): void
    {
        $source = (string) file_get_contents(base_path('routes/teacher.php'));

        $this->assertSame(
            1,
            preg_match_all("~Route::put\\(\\s*'/grade-weights'~", $source),
            'routes/teacher.php registers PUT /grade-weights more than once: delete the copy'
        );
    }
}

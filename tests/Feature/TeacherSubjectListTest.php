<?php

namespace Tests\Feature;

use App\Models\GroupStaff;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin form's subject checkboxes must be exactly GroupStaff::SUBJECTS.
 *
 * TeachersView.vue carries its own copy of the list (value + label). A copy is
 * the thing that rotted before: the teacher screen's hifz quality list held a
 * value PHP rejected and was missing `repeat` for eighteen days. A subject missing
 * here is one an admin cannot give a teacher; one invented here is a 422 on save.
 * Read lexically because a Feature test cannot render Vue.
 */
class TeacherSubjectListTest extends TestCase
{
    #[Test]
    public function the_admin_forms_subjects_match_the_servers_in_order_and_label(): void
    {
        $source = file_get_contents(base_path('resources/vue-app/views/dashboard/TeachersView.vue'));

        $this->assertTrue((bool) preg_match('/const subjectOptions = ref<[^>]*>\(\[(?P<body>.*?)\]\);/s', $source, $m),
            'could not find subjectOptions in TeachersView.vue — rename it here too rather than delete this test');

        preg_match_all("/value:\s*'([a-z_]+)',\s*label:\s*(['\"])(.*?)\\2/", $m['body'], $found, PREG_SET_ORDER);

        $client = array_map(fn ($f) => [$f[1], $f[3]], $found);
        $server = array_map(fn ($s) => [$s, GroupStaff::SUBJECT_LABELS[$s]], GroupStaff::SUBJECTS);

        $this->assertSame($server, $client);
    }

    #[Test]
    public function the_teacher_screen_hides_exactly_the_tabs_the_server_refuses(): void
    {
        // Two lists that must agree: routes/teacher.php gates letters/arabic-notes
        // on `teacher.teaches:arabic` and hifz on `teacher.teaches:quran`, and the
        // teacher screen hides the same tabs. A tab shown that the server refuses
        // is a dead tab; a tab hidden that the server allows is a lie.
        $vue = file_get_contents(base_path('resources/vue-app/views/teacher/TeacherClass.vue'));
        $routes = file_get_contents(base_path('routes/teacher.php'));

        $this->assertMatchesRegularExpression("/SUBJECT_OF_TAB[^=]*=\s*\{\s*letters:\s*'arabic',\s*hifz:\s*'quran'\s*\}/", $vue);
        $this->assertStringContainsString("Route::middleware('teacher.teaches:arabic')", $routes);
        $this->assertStringContainsString("Route::middleware('teacher.teaches:quran')", $routes);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_question_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->string('kind',20);
            $table->boolean('active')->default(false);
            $table->string('prompt',80);
            $table->timestamps();
            $table->unique(['masjid_id','kind'],'mqs_kind_uq');
        });
        Schema::table('member_question_answers', function (Blueprint $table) {
            $table->foreignId('field_id')->nullable()->constrained('member_question_options')->restrictOnDelete();
            $table->text('field_text')->nullable();
            $table->index(['masjid_id','field_id'],'mqa_field_idx');
            $table->index(['masjid_id','occupation_id'],'mqa_occupation_idx');
        });
        // The old occupation catalogue describes fields. Keep identities and ciphertext byte for byte.
        DB::table('member_question_options')->where('question','occupation')->update(['question'=>'field']);
        DB::table('member_question_answers')->update([
            'field_id'=>DB::raw('occupation_id'),'field_text'=>DB::raw('occupation_text'),
            'occupation_id'=>null,'occupation_text'=>null,
        ]);
        // Include organisations with retained lists even when the switch is currently off.
        foreach (DB::table('member_question_options')->distinct()->pluck('masjid_id') as $orgId) {
            $org=\App\Models\Masjid::withTrashed()->findOrFail($orgId);
            \App\Support\MemberQuestions::seed($org);
            \App\Support\MobileCache::flushMasjid((int)$orgId,\App\Support\MobileCache::SHOW);
        }
    }

    public function down(): void
    {
        // Restoring the old contract discards new profession answers, then moves fields back.
        DB::table('member_question_answers')->update(['occupation_id'=>null,'occupation_text'=>null]);
        DB::table('member_question_options')->where('question','occupation')->delete();
        DB::table('member_question_answers')->update(['occupation_id'=>DB::raw('field_id'),'occupation_text'=>DB::raw('field_text')]);
        DB::table('member_question_options')->where('question','field')->update(['question'=>'occupation']);
        Schema::table('member_question_answers', function (Blueprint $table) {
            $table->dropForeign(['field_id']);
            $table->dropIndex('mqa_field_idx');
            $table->dropIndex('mqa_occupation_idx');
            $table->dropColumn(['field_id','field_text']);
        });
        Schema::dropIfExists('member_question_settings');
    }
};

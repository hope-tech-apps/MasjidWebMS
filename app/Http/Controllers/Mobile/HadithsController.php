<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Hadith;
use Carbon\Carbon;
use Illuminate\Http\Request;

class HadithsController extends Controller
{
    public function todayHadith ()
    {
        $hadith = Hadith::where('show_date', Carbon::now()->format('Y-m-d'))->first();
        if (!$hadith) {
            $hadith = Hadith::inRandomOrder()->first();
        }

        return response()->json([
            'status' => 'success',
            'data' => $hadith
        ]);
    }
}

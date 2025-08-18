<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\Service;

class ServicesController extends Controller
{
    public function index ($masjid_id) {
        $masjid = Masjid::findOrFail($masjid_id);

        $services = Service::where('masjid_id', $masjid->id)->with('image', 'icon')->get();

        return response()->json([
            'status' => 'success',
            'data' => $services
        ]);
    }
}

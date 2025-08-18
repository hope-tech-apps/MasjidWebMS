<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MasjidAdminsController extends Controller
{
    public function index()
    {
        $admins = User::where('type', 'MasjidAdmin')->get();
        return response()->json([
            'status' => 'success',
            'data' => $admins
        ], Response::HTTP_OK);
    }

    public function availableAdmins()
    {
        $admins = User::where('type', 'MasjidAdmin')->doesntHave('masjid')->get();
        return response()->json([
            'status' => 'success',
            'data' => $admins
        ], Response::HTTP_OK);
    }
}

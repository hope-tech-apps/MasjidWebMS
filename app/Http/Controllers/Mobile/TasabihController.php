<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Tasbih;
use Symfony\Component\HttpFoundation\Response;

class TasabihController extends Controller
{
    public function index()
    {
        $tasabih = Tasbih::all()->toArray();

        return response()->json([
            'status' => 'success',
            'data' => $tasabih
        ], Response::HTTP_OK);
    }
}

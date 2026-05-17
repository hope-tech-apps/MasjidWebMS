<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\ContactUsMessage;
use App\Models\Masjid;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContactRequestsController extends Controller
{
    /**
     * Display a listing of contact requests for a masjid.
     */
    public function index(Request $request, $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $search = $request->query('search');

        $query = ContactUsMessage::whereHas('contacter.mobileAppUser', function ($query) use ($masjid) {
            $query->where('masjid_id', $masjid->id);
        })
        ->with([
            'contacter' => function ($query) {
                $query->select('id', 'mobile_app_user_id', 'email', 'name', 'phone');
            },
            'reason' => function ($query) {
                $query->select('id', 'text');
            }
        ]);

        // Apply search filter if provided
        if ($search) {
            $query->where(function ($q) use ($search) {
                // Search in message
                $q->where('message', 'LIKE', "%{$search}%")
                  // Search in contacter name, email, phone
                  ->orWhereHas('contacter', function ($contactQuery) use ($search) {
                      $contactQuery->where('name', 'LIKE', "%{$search}%")
                                   ->orWhere('email', 'LIKE', "%{$search}%")
                                   ->orWhere('phone', 'LIKE', "%{$search}%");
                  })
                  // Search in reason text
                  ->orWhereHas('reason', function ($reasonQuery) use ($search) {
                      $reasonQuery->where('text', 'LIKE', "%{$search}%");
                  });
            });
        }

        $messages = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $messages
        ], Response::HTTP_OK);
    }

    /**
     * Display the specified contact request.
     */
    public function show($masjid_id, $message_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        $message = ContactUsMessage::whereHas('contacter.mobileAppUser', function ($query) use ($masjid) {
            $query->where('masjid_id', $masjid->id);
        })
        ->with([
            'contacter' => function ($query) {
                $query->select('id', 'mobile_app_user_id', 'email', 'name', 'phone');
            },
            'reason' => function ($query) {
                $query->select('id', 'text');
            }
        ])
        ->findOrFail($message_id);

        return response()->json([
            'status' => 'success',
            'data' => $message
        ], Response::HTTP_OK);
    }

    /**
     * Remove the specified contact request from storage.
     */
    public function destroy($masjid_id, $message_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $message = ContactUsMessage::whereHas('contacter.mobileAppUser', function ($query) use ($masjid) {
                $query->where('masjid_id', $masjid->id);
            })->findOrFail($message_id);

            $message->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Contact request deleted successfully'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}


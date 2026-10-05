<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pages\StorePageDocumentRequest;
use App\Models\Masjid;
use App\Support\Errors;
use App\Support\PageDocuments;
use Symfony\Component\HttpFoundation\Response;

/**
 * Upload a PDF for a web page and answer with its public address (App\Support\PageDocuments says why
 * a document is uploaded on its own rather than with a section's save).
 *
 * There is one action. Nothing lists the documents and nothing here deletes one.
 */
class PageDocumentsController extends Controller
{
    /**
     * Who may call this is decided by the route's group, the same one that guards saving a page
     * (auth:sanctum, admin, tenant, capability:web_pages, capability:website).
     *
     * The address in the answer is built from the public disk's configured `url`, never from the
     * request: it is written into page content and served from there for good, and this deployment
     * answers to more than one hostname (`.claude/rules/generated-urls.md`).
     */
    public function store(StorePageDocumentRequest $request, $masjid_id)
    {
        // Outside the try/catch, so an unknown organisation is the JSON renderer's 404 and not a 500.
        $masjid = Masjid::findOrFail($masjid_id);

        try {
            $media = PageDocuments::store($masjid, $request->file('document'));

            return response()->json([
                'status' => 'success',
                'data' => [
                    'url' => $media->getUrl(),
                    'name' => $media->name,
                    'size' => (int) $media->size,
                ],
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

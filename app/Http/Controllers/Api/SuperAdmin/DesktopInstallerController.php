<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\DesktopInstallerService;
use Illuminate\Http\Request;

class DesktopInstallerController extends Controller
{
    public function show(DesktopInstallerService $service)
    {
        return response()->json(['data' => $service->current()]);
    }

    public function upload(Request $request, DesktopInstallerService $service)
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:512000'],
        ]);

        foreach ($request->file('files') as $file) {
            if (!$service->isAllowed($file)) {
                return response()->json([
                    'message' => "Archivo no permitido: {$file->getClientOriginalName()}. Solo .exe, .yml o .blockmap.",
                ], 422);
            }
        }

        foreach ($request->file('files') as $file) {
            $service->store($file);
        }

        return response()->json([
            'message' => 'Archivos subidos correctamente.',
            'data' => $service->current(),
        ]);
    }
}

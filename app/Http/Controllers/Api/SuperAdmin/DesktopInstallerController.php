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
            'installer' => ['required', 'file', 'max:512000'],
        ]);

        $file = $request->file('installer');
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension !== 'exe') {
            return response()->json([
                'message' => 'El archivo debe ser un instalador .exe.',
            ], 422);
        }

        $data = $service->replace($file);

        return response()->json([
            'message' => 'Instalador subido correctamente.',
            'data' => $data,
        ]);
    }
}

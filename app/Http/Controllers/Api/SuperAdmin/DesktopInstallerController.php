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
            'installer' => ['required', 'file', 'mimes:exe', 'max:512000'],
        ]);

        $data = $service->replace($request->file('installer'));

        return response()->json([
            'message' => 'Instalador subido correctamente.',
            'data' => $data,
        ]);
    }
}

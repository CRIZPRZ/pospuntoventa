<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesktopLicenseException extends Exception
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->status);
    }
}

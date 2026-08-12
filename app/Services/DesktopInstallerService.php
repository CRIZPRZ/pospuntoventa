<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DesktopInstallerService
{
    private const DIR = 'desktop';
    private const ALLOWED_EXTENSIONS = ['exe', 'yml', 'yaml', 'blockmap'];

    public function current(): ?array
    {
        $disk = Storage::disk('public');
        $files = collect($disk->files(self::DIR))
            ->filter(fn (string $path) => str_ends_with(strtolower($path), '.exe'));

        if ($files->isEmpty()) {
            return null;
        }

        $path = $files->sortByDesc(fn (string $path) => $disk->lastModified($path))->first();

        return [
            'filename' => basename($path),
            'url' => $disk->url($path),
            'size' => $disk->size($path),
            'uploaded_at' => date('c', $disk->lastModified($path)),
        ];
    }

    public function isAllowed(UploadedFile $file): bool
    {
        return in_array(strtolower($file->getClientOriginalExtension()), self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * Guarda un archivo del set de release (instalador .exe, latest.yml o
     * .exe.blockmap, generados por electron-builder gracias a la config
     * "publish" en package.json). Solo reemplaza instaladores .exe viejos —
     * latest.yml/.blockmap se sobreescriben por nombre exacto sin tocar el
     * resto, porque electron-updater los necesita a los tres coexistiendo
     * bajo la misma URL pública (storage/desktop) para poder actualizar
     * equipos ya instalados.
     */
    public function store(UploadedFile $file): void
    {
        $disk = Storage::disk('public');
        $filename = $file->getClientOriginalName();

        if (str_ends_with(strtolower($filename), '.exe')) {
            foreach ($disk->files(self::DIR) as $existing) {
                if (str_ends_with(strtolower($existing), '.exe')) {
                    $disk->delete($existing);
                }
            }
        }

        $disk->putFileAs(self::DIR, $file, $filename);
    }
}

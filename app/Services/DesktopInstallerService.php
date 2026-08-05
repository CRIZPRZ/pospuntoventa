<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class DesktopInstallerService
{
    private const DIR = 'desktop';

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

    public function replace(\Illuminate\Http\UploadedFile $file): array
    {
        $disk = Storage::disk('public');

        foreach ($disk->files(self::DIR) as $existing) {
            $disk->delete($existing);
        }

        $filename = $file->getClientOriginalName();
        $disk->putFileAs(self::DIR, $file, $filename);

        return $this->current();
    }
}

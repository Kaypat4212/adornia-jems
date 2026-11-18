<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicImageController extends Controller
{
    public function __invoke(string $directory, string $filename): StreamedResponse
    {
        $disk = Storage::disk('public');
        $path = $directory.'/'.$filename;

        abort_unless($disk->fileExists($path), 404);

        $mimeType = $disk->mimeType($path);
        abort_unless(in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return $disk->response($path, headers: [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

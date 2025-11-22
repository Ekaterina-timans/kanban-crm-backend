<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Filesystem\FilesystemAdapter;

class AttachmentController extends Controller
{
    public function download(Request $request, Attachment $attachment)
    {
        $disk = $attachment->disk ?? 'public';
        $path = $attachment->path;

        /** @var FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        abort_unless($storage->exists($path), 404);

        $filename = $attachment->original_name ?: basename($path);
        $mime = $attachment->mime ?: 'application/octet-stream';

        return $storage->download($path, $filename, [
            'Content-Type' => $mime,
        ]);
    }
}

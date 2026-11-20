<?php

namespace App\Http\Controllers;

use App\Services\LogRedactor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminLogsController extends Controller
{
    public function index(Request $request, LogRedactor $redactor): Response
    {
        $paths = array_unique([
            storage_path('logs/laravel.log'),
            ...(glob(storage_path('logs/laravel-*.log')) ?: []),
            base_path('error_log'), public_path('error_log'),
            storage_path('logs/php-error.log'),
            dirname(base_path()).'/logs/php.error.log',
        ]);
        $logs = [];
        foreach ($paths as $path) {
            if (is_file($path) && is_readable($path) && ! is_link($path)) {
                $logs[hash('sha256', $path)] = ['name' => basename($path).' · '.basename(dirname($path)), 'path' => $path];
            }
        }
        uasort($logs, fn (array $first, array $second): int => filemtime($second['path']) <=> filemtime($first['path']));
        $selected = $request->query('source', array_key_first($logs));
        abort_if($selected !== null && (! is_string($selected) || ! isset($logs[$selected])), 404);
        $content = '';
        if ($selected !== null) {
            $handle = fopen($logs[$selected]['path'], 'rb');
            if ($handle !== false) {
                try {
                    $size = fstat($handle)['size'];
                    fseek($handle, max(0, $size - 65536));
                    if ($size > 65536) {
                        fgets($handle);
                    }
                    $content = stream_get_contents($handle, 65536);
                } finally {
                    fclose($handle);
                }
            }
        }
        $content = implode("\n", array_slice(explode("\n", $content), -300));

        return response()->view('admin.logs', ['logs' => $logs, 'selected' => $selected, 'content' => $redactor->clean($content)])
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}

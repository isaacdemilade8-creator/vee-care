<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImageUploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'folder' => ['nullable', 'in:avatars,posts'],
        ]);

        $folder = $data['folder'] ?? 'posts';
        $path = $request->file('image')->store("uploads/{$folder}", 'public');

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ], 201);
    }
}

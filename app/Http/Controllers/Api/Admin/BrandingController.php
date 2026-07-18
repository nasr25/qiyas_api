<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BrandingAsset;
use App\Services\BrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Super-Admin-only versioned branding asset management. Route-gated by
 * `role:super-admin` (see routes/api.php) — this controller performs no
 * additional authorization check of its own, matching the existing
 * SettingController convention. See docs/administration/branding.md.
 */
class BrandingController extends Controller
{
    public function __construct(private readonly BrandingService $branding) {}

    /** GET /api/v1/admin/branding/{type} */
    public function history(string $type): JsonResponse
    {
        if (! in_array($type, BrandingService::ASSET_TYPES, true)) {
            return response()->json(['success' => false, 'message' => 'Unknown branding asset type.'], 422);
        }

        return response()->json(['success' => true, 'data' => $this->branding->history($type)->map(fn (BrandingAsset $a) => $this->summarize($a))->values()]);
    }

    /** POST /api/v1/admin/branding/{type}/upload */
    public function upload(Request $request, string $type): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:2048']]);

        try {
            $asset = $this->branding->upload($request->file('file'), $type, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422);
        }

        return response()->json(['success' => true, 'data' => $this->summarize($asset)], 201);
    }

    /** POST /api/v1/admin/branding/{type}/{asset}/activate */
    public function activate(Request $request, string $type, int|string $asset): JsonResponse
    {
        $model = BrandingAsset::where('id', $asset)->where('asset_type', $type)->first();
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Branding asset not found.'], 404);
        }

        $activated = $this->branding->activate($model, $request->user());

        return response()->json(['success' => true, 'data' => $this->summarize($activated)]);
    }

    /** POST /api/v1/admin/branding/{type}/{asset}/restore */
    public function restore(Request $request, string $type, int|string $asset): JsonResponse
    {
        $model = BrandingAsset::where('id', $asset)->where('asset_type', $type)->first();
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Branding asset not found.'], 404);
        }

        $restored = $this->branding->restore($model, $request->user());

        return response()->json(['success' => true, 'data' => $this->summarize($restored)]);
    }

    private function summarize(BrandingAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'asset_type' => $asset->asset_type,
            'version' => $asset->version,
            'status' => $asset->status,
            'url' => Storage::disk('public')->url($asset->storage_path).'?v='.$asset->version,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'file_size' => $asset->file_size,
            'width' => $asset->width,
            'height' => $asset->height,
            'file_hash' => $asset->file_hash,
            'uploaded_by' => $asset->uploader?->name,
            'uploaded_at' => $asset->uploaded_at?->toIso8601String(),
            'activated_at' => $asset->activated_at?->toIso8601String(),
        ];
    }
}

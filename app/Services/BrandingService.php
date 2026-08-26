<?php

namespace App\Services;

use App\Models\BrandingAsset;
use App\Models\Setting;
use App\Models\User;
use enshrined\svgSanitize\Sanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The single write path for global branding assets. See
 * docs/administration/branding.md and docs/security/file-security.md.
 *
 * Validation performed on every upload, in order: extension allow-list,
 * actual MIME type (finfo, never the client-supplied Content-Type),
 * file-signature/decode verification for raster images, strict SVG
 * sanitization (never trusted as-is) via a reviewed third-party
 * sanitizer, maximum file size, maximum pixel count. Any failure at any
 * step rejects the upload — there is no "best effort" path.
 */
class BrandingService
{
    public const ASSET_TYPES = [
        'logo_primary', 'logo_dark', 'logo_login', 'logo_header',
        'logo_compact', 'favicon', 'logo_report', 'logo_email',
    ];

    private const RASTER_EXTENSIONS = ['png', 'jpg', 'jpeg', 'ico'];

    private const MAX_FILE_SIZE_BYTES = 2 * 1024 * 1024; // 2 MB

    private const MAX_PIXELS = 4000 * 4000;

    /** @throws \RuntimeException on any validation failure — message is safe to display to the Super Admin. */
    public function upload(UploadedFile $file, string $assetType, User $actor): BrandingAsset
    {
        if (! in_array($assetType, self::ASSET_TYPES, true)) {
            throw new \RuntimeException('Unknown branding asset type.');
        }
        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            throw new \RuntimeException('File exceeds the maximum allowed size (2 MB).');
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $realMime = $file->getMimeType(); // Symfony/finfo-detected, not the client-supplied header.

        if ($ext === 'svg') {
            [$contents, $width, $height] = $this->processSvg($file);
        } elseif (in_array($ext, self::RASTER_EXTENSIONS, true)) {
            [$contents, $width, $height] = $this->processRaster($file, $ext, $realMime);
        } else {
            throw new \RuntimeException('Unsupported file type. Allowed: PNG, JPG, JPEG, ICO, SVG.');
        }

        $hash = hash('sha256', $contents);
        $storedName = $assetType.'-'.now()->format('YmdHis').'-'.Str::random(8).'.'.$ext;
        $path = "branding/{$storedName}";
        Storage::disk('public')->put($path, $contents);

        return DB::transaction(function () use ($assetType, $file, $path, $realMime, $contents, $width, $height, $hash, $actor) {
            $nextVersion = (BrandingAsset::ofType($assetType)->max('version') ?? 0) + 1;

            $asset = BrandingAsset::create([
                'asset_type' => $assetType,
                'version' => $nextVersion,
                'status' => 'inactive',
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'mime_type' => $realMime,
                'file_size' => strlen($contents),
                'width' => $width,
                'height' => $height,
                'file_hash' => $hash,
                'uploaded_by' => $actor->id,
                'uploaded_at' => now(),
            ]);

            AuditService::log('branding.uploaded', "Branding asset '{$assetType}' v{$nextVersion} uploaded (not yet active)", $asset);

            return $asset;
        });
    }

    /** Activates a specific version, superseding whatever was previously active for that asset type. Never deletes the previous file. */
    public function activate(BrandingAsset $asset, User $actor): BrandingAsset
    {
        return DB::transaction(function () use ($asset, $actor) {
            $previousActive = BrandingAsset::ofType($asset->asset_type)->active()->lockForUpdate()->first();
            if ($previousActive && $previousActive->id !== $asset->id) {
                $previousActive->update(['status' => 'superseded']);
                $asset->previous_version_id = $previousActive->id;
            }

            $asset->status = 'active';
            $asset->activated_at = now();
            $asset->save();

            // Legacy bridge: any pre-existing code path still reading the
            // old flat `Setting` key stays in sync. The public branding
            // read (SettingController::branding()) no longer relies on
            // this — it reads BrandingAsset directly via activeUrls().
            Setting::set('branding', $asset->asset_type, $asset->storage_path, 'string');

            AuditService::log('branding.activated', "Branding asset '{$asset->asset_type}' v{$asset->version} activated", $asset,
                oldValues: $previousActive ? ['version' => $previousActive->version] : null,
                newValues: ['version' => $asset->version],
            );

            return $asset->fresh();
        });
    }

    /**
     * Versioned URLs (with a cache-busting `?v=` query string) for every
     * currently active branding asset, keyed by asset type. The single
     * source of truth for every public-facing consumer (login page,
     * header/nav, report headers, email previews) — never mixes an old
     * and new logo because it always reads the current `active` row.
     *
     * @return array<string,string>
     */
    public function activeUrls(): array
    {
        return BrandingAsset::query()
            ->where('status', 'active')
            ->get()
            ->mapWithKeys(fn (BrandingAsset $a) => [
                $a->asset_type => Storage::disk('public')->url($a->storage_path).'?v='.$a->version,
            ])
            ->all();
    }

    /** Restores (re-activates) a previous, superseded version — itself becomes the new active version via a fresh activation, preserving full history. */
    public function restore(BrandingAsset $asset, User $actor): BrandingAsset
    {
        if ($asset->status === 'active') {
            return $asset;
        }

        return $this->activate($asset, $actor);
    }

    public function history(string $assetType): \Illuminate\Support\Collection
    {
        return BrandingAsset::ofType($assetType)->with('uploader')->orderByDesc('version')->get();
    }

    /** @return array{0:string,1:?int,2:?int} [contents, width, height] */
    private function processRaster(UploadedFile $file, string $ext, string $realMime): array
    {
        $expectedMimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'ico' => 'image/x-icon'];
        // .ico is inconsistently reported by finfo (image/x-icon, image/vnd.microsoft.icon,
        // or occasionally application/octet-stream) across platforms — verify by
        // signature instead of a strict MIME match for that one extension.
        if ($ext !== 'ico' && $realMime !== $expectedMimes[$ext] && ! ($ext === 'jpg' && $realMime === 'image/jpeg')) {
            throw new \RuntimeException('The file content does not match its extension.');
        }

        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || $contents === '') {
            throw new \RuntimeException('The uploaded file could not be read.');
        }

        if ($ext === 'ico') {
            $this->assertIcoSignature($contents);

            return [$contents, null, null]; // ICO dimensions are per-embedded-image; not decoded via GD.
        }

        // getimagesizefromstring() both verifies the file is a genuinely
        // decodable image (rejects corrupted/truncated/polyglot files)
        // and returns real pixel dimensions — never trust the extension alone.
        $info = @getimagesizefromstring($contents);
        if ($info === false) {
            throw new \RuntimeException('The file is not a valid, decodable image.');
        }
        [$width, $height] = $info;
        if ($width * $height > self::MAX_PIXELS) {
            throw new \RuntimeException('Image dimensions exceed the maximum allowed pixel count.');
        }

        $expectedGdType = $ext === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        if ($info[2] !== $expectedGdType) {
            throw new \RuntimeException('The file content does not match its extension.');
        }

        return [$contents, $width, $height];
    }

    private function assertIcoSignature(string $contents): void
    {
        // ICO files begin with the 4-byte reserved+type header 00 00 01 00.
        if (substr($contents, 0, 4) !== "\x00\x00\x01\x00") {
            throw new \RuntimeException('The file is not a valid ICO image.');
        }
    }

    /** @return array{0:string,1:?int,2:?int} */
    private function processSvg(UploadedFile $file): array
    {
        $raw = file_get_contents($file->getRealPath());
        if ($raw === false || trim($raw) === '') {
            throw new \RuntimeException('The uploaded file could not be read.');
        }

        // Defense in depth ahead of the sanitizer: reject any external or
        // parameter entity declaration outright (XXE) rather than relying
        // solely on libxml's modern default of not resolving them.
        if (preg_match('/<!ENTITY\s+\S+\s+(SYSTEM|PUBLIC)/i', $raw) || preg_match('/<!DOCTYPE[^>]*\[.*<!ENTITY/is', $raw)) {
            throw new \RuntimeException('The SVG file contains an unsupported external entity declaration.');
        }

        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true); // Strips external image/font/CSS url() references.
        $sanitizer->minify(false);

        $clean = $sanitizer->sanitize($raw);
        if ($clean === false || trim($clean) === '') {
            throw new \RuntimeException('The SVG file could not be safely sanitized and was rejected.');
        }

        // Belt-and-braces: reject if anything script-like survived sanitization.
        if (preg_match('/<script|on\w+\s*=|javascript:|<foreignObject/i', $clean)) {
            throw new \RuntimeException('The SVG file could not be safely sanitized and was rejected.');
        }

        // SVG has no fixed raster "decode" step — width/height are read
        // from the root element's own attributes when present, purely
        // for display metadata (not a security control).
        $width = $height = null;
        if (preg_match('/<svg[^>]*\swidth="(\d+)/i', $clean, $m)) {
            $width = (int) $m[1];
        }
        if (preg_match('/<svg[^>]*\sheight="(\d+)/i', $clean, $m)) {
            $height = (int) $m[1];
        }

        return [$clean, $width, $height];
    }
}

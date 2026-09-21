<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImageOptimizer
{
    /**
     * Re-encode and store a signature image.
     *
     * Never stores the original bytes. If the file cannot be decoded and
     * re-encoded, the upload is rejected. This closes the class of attacks
     * where a valid image header wraps unexpected content, and it guarantees
     * every stored signature is a fresh JPEG or PNG with no embedded metadata.
     */
    public static function storeSignature(
        UploadedFile $file,
        ?string $disk = null,
        string $directory = 'signatures'
    ): string {
        if (! extension_loaded('gd')) {
            // Server misconfiguration, not user error.
            throw new RuntimeException('GD extension is required for signature processing.');
        }

        $disk ??= config('filesystems.signature_cards.disk', 'local');
        $realPath = $file->getRealPath();

        $imageInfo = @getimagesize($realPath);
        if (! $imageInfo) {
            throw ValidationException::withMessages([
                'signature' => 'The signature image could not be read.',
            ]);
        }

        [$width, $height, $imageType] = $imageInfo;

        $sourceImage = match ($imageType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($realPath),
            IMAGETYPE_PNG  => @imagecreatefrompng($realPath),
            default        => null,
        };

        if (! $sourceImage) {
            throw ValidationException::withMessages([
                'signature' => 'Only JPEG and PNG signature images are accepted.',
            ]);
        }

        // Cap dimensions. Signature cards do not need original camera resolution.
        $maxWidth = 1400;
        $maxHeight = 700;
        $scale = min($maxWidth / $width, $maxHeight / $height, 1);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $optimizedImage = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($imageType === IMAGETYPE_PNG) {
            imagealphablending($optimizedImage, false);
            imagesavealpha($optimizedImage, true);
            $transparent = imagecolorallocatealpha($optimizedImage, 0, 0, 0, 127);
            imagefilledrectangle($optimizedImage, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        imagecopyresampled(
            $optimizedImage, $sourceImage,
            0, 0, 0, 0,
            $targetWidth, $targetHeight, $width, $height
        );

        $extension = $imageType === IMAGETYPE_PNG ? 'png' : 'jpg';
        $relativePath = trim($directory, '/') . '/' . Str::uuid() . '.' . $extension;

        Storage::disk($disk)->makeDirectory($directory);
        $absolutePath = Storage::disk($disk)->path($relativePath);

        $stored = $imageType === IMAGETYPE_PNG
            ? imagepng($optimizedImage, $absolutePath, 6)
            : imagejpeg($optimizedImage, $absolutePath, 75);

        imagedestroy($sourceImage);
        imagedestroy($optimizedImage);

        if (! $stored) {
            // Best-effort cleanup of a possible zero-byte file
            if (Storage::disk($disk)->exists($relativePath)) {
                Storage::disk($disk)->delete($relativePath);
            }
            throw ValidationException::withMessages([
                'signature' => 'The signature image could not be saved. Please try again.',
            ]);
        }

        return $relativePath;
    }
}

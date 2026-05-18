<?php

declare(strict_types=1);

namespace App\Support\Storage;

use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Centralized storage abstraction for all persistent file operations.
 */
final class EnterpriseStorage
{
    public const PURPOSE_DEFAULT = 'default';

    public const PURPOSE_QUOTE = 'quote';

    public const PURPOSE_COLLATERAL = 'collateral';

    public const PURPOSE_IMPORT = 'import';

    public function diskName(string $purpose = self::PURPOSE_DEFAULT): string
    {
        if ($purpose !== self::PURPOSE_DEFAULT) {
            $override = config("enterprise_storage.purpose_disks.{$purpose}");
            if (is_string($override) && $override !== '') {
                return $override;
            }
        }

        return (string) config('enterprise_storage.default_disk', 'local');
    }

    public function disk(string $purpose = self::PURPOSE_DEFAULT): FilesystemAdapter
    {
        /** @var FilesystemAdapter $adapter */
        $adapter = Storage::disk($this->diskName($purpose));

        return $adapter;
    }

    /**
     * @param  string|resource  $contents
     */
    public function putPrivate(string $path, $contents, string $purpose = self::PURPOSE_DEFAULT): void
    {
        $this->disk($purpose)->put($path, $contents, $this->privatePutOptions());
    }

    public function delete(string $path, string $purpose = self::PURPOSE_DEFAULT): bool
    {
        return $this->disk($purpose)->delete($path);
    }

    public function exists(string $path, string $purpose = self::PURPOSE_DEFAULT): bool
    {
        return $this->disk($purpose)->exists($path);
    }

    public function get(string $path, string $purpose = self::PURPOSE_IMPORT): string
    {
        return $this->disk($purpose)->get($path);
    }

    public function putFile(string $directory, UploadedFile $file, string $purpose = self::PURPOSE_IMPORT): string
    {
        $path = $this->disk($purpose)->putFile($directory, $file);

        return $path !== false ? $path : '';
    }

    public function storeUploadedFile(UploadedFile $file, string $directory, string $purpose = self::PURPOSE_DEFAULT): string
    {
        return $file->store($directory, $this->diskName($purpose));
    }

    public function signedUrl(string $path, ?int $minutes = null, string $purpose = self::PURPOSE_DEFAULT): string
    {
        $adapter = $this->disk($purpose);
        $expiresAt = now()->addMinutes($minutes ?? $this->signedUrlMinutes($purpose));

        if ($this->canGenerateTemporaryUrl($adapter)) {
            try {
                return $adapter->temporaryUrl($path, $expiresAt);
            } catch (\Throwable) {
                // Fall through to safe local handling below.
            }
        }

        return $this->localPrivateUrl($adapter, $path, $expiresAt);
    }

    public function downloadResponse(string $path, ?string $name = null, string $purpose = self::PURPOSE_DEFAULT): StreamedResponse
    {
        return $this->disk($purpose)->download($path, $name);
    }

    public function response(string $path, string $purpose = self::PURPOSE_DEFAULT): StreamedResponse
    {
        return $this->disk($purpose)->response($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function privatePutOptions(): array
    {
        if ($this->diskName() === 's3') {
            return ['visibility' => 'private'];
        }

        return [];
    }

    private function signedUrlMinutes(string $purpose): int
    {
        if ($purpose === self::PURPOSE_COLLATERAL) {
            return (int) config('enterprise_storage.collateral_signed_url_minutes', 10);
        }

        return (int) config('enterprise_storage.signed_url_minutes', 10);
    }

    private function canGenerateTemporaryUrl(FilesystemAdapter $adapter): bool
    {
        return method_exists($adapter, 'providesTemporaryUrls')
            ? $adapter->providesTemporaryUrls()
            : $this->diskName() === 's3';
    }

    private function localPrivateUrl(FilesystemAdapter $adapter, string $path, DateTimeInterface $expiresAt): string
    {
        if ($this->diskName() === 's3') {
            throw new \RuntimeException("Unable to generate signed URL for path: {$path}");
        }

        try {
            return $adapter->temporaryUrl($path, $expiresAt);
        } catch (\Throwable) {
            // Last resort for legacy local configs: only when the disk exposes a readable URL.
            try {
                return $adapter->url($path);
            } catch (\Throwable) {
                throw new \RuntimeException("Unable to generate accessible URL for path: {$path}");
            }
        }
    }
}

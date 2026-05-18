<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Support\Storage\EnterpriseStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ConfiguresEnterpriseStorage;
use Tests\TestCase;

class EnterpriseStorageTest extends TestCase
{
    use ConfiguresEnterpriseStorage;

    public function test_local_disk_put_get_delete_and_signed_url(): void
    {
        $this->fakeEnterpriseStorage('local');
        $storage = app(EnterpriseStorage::class);

        $path = 'tenant/1/collaterals/test-doc.pdf';
        $storage->putPrivate($path, 'pdf-content', EnterpriseStorage::PURPOSE_COLLATERAL);

        $this->assertTrue($storage->exists($path, EnterpriseStorage::PURPOSE_COLLATERAL));
        $this->assertSame('pdf-content', Storage::disk('local')->get($path));

        $url = $storage->signedUrl($path, 10, EnterpriseStorage::PURPOSE_COLLATERAL);
        $this->assertNotSame('', $url);

        $storage->delete($path, EnterpriseStorage::PURPOSE_COLLATERAL);
        $this->assertFalse($storage->exists($path, EnterpriseStorage::PURPOSE_COLLATERAL));
    }

    public function test_s3_disk_put_and_presigned_url(): void
    {
        $this->fakeEnterpriseStorage('s3');
        $storage = app(EnterpriseStorage::class);

        $path = 'tenant/2/quotes/1/attachments/file.pdf';
        $storage->putPrivate($path, 'quote-bytes', EnterpriseStorage::PURPOSE_QUOTE);

        $this->assertTrue($storage->exists($path, EnterpriseStorage::PURPOSE_QUOTE));

        $url = $storage->signedUrl($path, 5, EnterpriseStorage::PURPOSE_QUOTE);
        $this->assertStringContainsString('http', $url);
    }

    public function test_import_put_file_and_get(): void
    {
        $this->fakeEnterpriseStorage('local');
        $storage = app(EnterpriseStorage::class);

        $file = UploadedFile::fake()->create('contacts.csv', 10, 'text/csv');
        $path = $storage->putFile('contact-imports', $file, EnterpriseStorage::PURPOSE_IMPORT);

        $this->assertNotSame('', $path);
        $this->assertNotFalse($storage->exists($path, EnterpriseStorage::PURPOSE_IMPORT));
    }

    public function test_purpose_override_uses_collateral_disk(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        config([
            'enterprise_storage.default_disk' => 'local',
            'enterprise_storage.purpose_disks' => [
                'quote' => null,
                'collateral' => 's3',
                'import' => null,
            ],
        ]);

        $storage = app(EnterpriseStorage::class);
        $this->assertSame('s3', $storage->diskName(EnterpriseStorage::PURPOSE_COLLATERAL));
        $this->assertSame('local', $storage->diskName(EnterpriseStorage::PURPOSE_DEFAULT));
    }

    public function test_store_uploaded_file_uses_configured_disk(): void
    {
        $this->fakeEnterpriseStorage('local');
        $storage = app(EnterpriseStorage::class);

        $file = UploadedFile::fake()->image('shot.png');
        $stored = $storage->storeUploadedFile($file, 'tenant/1/demo-links/9');

        $this->assertTrue($storage->exists($stored));
    }
}

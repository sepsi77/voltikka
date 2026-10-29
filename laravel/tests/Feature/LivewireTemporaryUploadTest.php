<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LivewireTemporaryUploadTest extends TestCase
{
    public function test_signed_temporary_upload_is_rejected_before_storage(): void
    {
        Storage::fake('tmp-for-tests');

        $this->uploadFile()->assertStatus(422);

        $this->assertSame([], Storage::disk('tmp-for-tests')->allFiles());
    }

    public function test_package_default_rules_would_store_the_same_upload(): void
    {
        Storage::fake('tmp-for-tests');
        config(['livewire.temporary_file_upload.rules' => null]);

        $this->uploadFile()->assertOk();

        $this->assertNotSame([], Storage::disk('tmp-for-tests')->allFiles());
    }

    private function uploadFile(): TestResponse
    {
        $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), [], false);

        return $this->withHeader('Accept', 'application/json')->post($url, [
            'files' => [UploadedFile::fake()->create('shell.php', 1, 'image/png')],
        ]);
    }
}

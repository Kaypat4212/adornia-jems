<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicImageTest extends TestCase
{
    public function test_public_upload_urls_work_without_a_storage_link(): void
    {
        $disk = Storage::fake('public');
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==');

        foreach (['products', 'homepage', 'avatars'] as $directory) {
            $disk->put($directory.'/photo.png', $image);

            $this->get('/storage/'.$directory.'/photo.png')
                ->assertOk()
                ->assertHeader('Content-Type', 'image/png')
                ->assertStreamedContent($image);
        }
    }

    public function test_private_and_non_image_files_are_not_served(): void
    {
        Storage::fake('local')->put('products/private.png', 'Private file');
        Storage::fake('public')->put('products/invalid.png', '<html>Not an image</html>');

        $this->get('/storage/products/private.png')->assertNotFound();
        $this->get('/storage/products/invalid.png')->assertNotFound();
    }
}

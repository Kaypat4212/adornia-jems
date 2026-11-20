<?php

namespace Tests\Feature;

use App\Models\HomepageSection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_php_upload_failures_show_the_actual_cause(): void
    {
        $source = $this->photo();
        $upload = new UploadedFile($source->getPathname(), 'photo.png', 'image/png', UPLOAD_ERR_INI_SIZE, true);
        $this->put('/admin/products/1', $this->productData() + ['image_upload' => $upload])
            ->assertSessionHasErrors(['image_upload' => $upload->getErrorMessage()]);
        $this->assertDatabaseMissing('products', ['name' => 'Updated piece']);

        $hero = HomepageSection::where('type', 'hero')->firstOrFail();
        $partial = new UploadedFile($source->getPathname(), 'photo.png', 'image/png', UPLOAD_ERR_PARTIAL, true);
        $this->put('/admin/sections/'.$hero->id, [
            'type' => 'hero', 'sort_order' => 10, 'content' => ['heading' => 'Updated heading'], 'image_upload' => $partial,
        ])->assertSessionHasErrors(['image_upload' => $partial->getErrorMessage()]);
        $this->assertNotSame('Updated heading', $hero->fresh()->content['heading']);
    }

    public function test_product_and_homepage_forms_include_one_shared_photo_editor(): void
    {
        $productPage = $this->get('/admin/products/create')
            ->assertOk()->assertSee('data-photo-input', false)->assertSee('photo-editor.js');
        $this->assertSame(1, substr_count($productPage->getContent(), 'id="photo-editor"'));

        $homepage = $this->get('/admin/homepage')
            ->assertOk()->assertSee('data-photo-input', false)->assertSee('photo-editor.js');
        $this->assertSame(1, substr_count($homepage->getContent(), 'id="photo-editor"'));
        $this->assertGreaterThan(1, substr_count($homepage->getContent(), 'data-photo-input'));
    }

    public function test_photos_between_four_and_eight_megabytes_are_accepted(): void
    {
        Storage::fake('public');
        $this->put('/admin/products/1', $this->productData() + ['image_upload' => $this->photo(6 * 1024 * 1024)])
            ->assertSessionHasNoErrors()->assertRedirect('/admin');
        Storage::disk('public')->assertExists(Product::findOrFail(1)->image);

        $hero = HomepageSection::where('type', 'hero')->firstOrFail();
        $this->put('/admin/sections/'.$hero->id, [
            'type' => 'hero', 'sort_order' => 10, 'content' => ['heading' => 'Updated heading'], 'image_upload' => $this->photo(6 * 1024 * 1024),
        ])->assertSessionHasNoErrors();
        Storage::disk('public')->assertExists($hero->fresh()->content['image']);
    }

    public function test_photos_over_eight_megabytes_are_rejected_without_changing_the_product(): void
    {
        $this->put('/admin/products/1', $this->productData() + ['image_upload' => $this->photo(8 * 1024 * 1024)])
            ->assertSessionHasErrors(['image_upload' => 'Choose a photo up to 8 MB.']);
        $this->assertDatabaseMissing('products', ['name' => 'Updated piece']);
    }

    public function test_storage_failures_preserve_existing_photos_and_content(): void
    {
        $product = Product::findOrFail(1);
        $product->update(['image' => 'products/existing.png']);
        $hero = HomepageSection::where('type', 'hero')->firstOrFail();
        $hero->update(['content' => $hero->content + ['image' => 'homepage/existing.png']]);
        $oldContent = $hero->content;

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->twice()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->twice()->andReturn($disk);

        $this->put('/admin/products/1', $this->productData() + ['image_upload' => $this->photo()])
            ->assertSessionHasErrors('image_upload');
        $this->assertSame('products/existing.png', $product->fresh()->image);
        $this->assertNotSame('Updated piece', $product->fresh()->name);

        $this->put('/admin/sections/'.$hero->id, [
            'type' => 'hero', 'sort_order' => 10, 'content' => ['heading' => 'Updated heading'], 'image_upload' => $this->photo(),
        ])->assertSessionHasErrors('image_upload');
        $this->assertSame($oldContent, $hero->fresh()->content);
    }

    private function photo(int $padding = 0): UploadedFile
    {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent('photo.png', $image.str_repeat("\0", $padding));
    }

    private function productData(): array
    {
        return ['name' => 'Updated piece', 'type' => 'Rings', 'detail' => 'Gold', 'price' => 20000, 'stock' => 10, 'art' => 'ring', 'active' => 1];
    }
}

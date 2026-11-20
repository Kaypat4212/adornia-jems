<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_a_category_and_assign_products_to_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/categories')->assertOk();
        $this->post('/admin/categories', ['name' => 'Anklets'])->assertRedirect('/admin/categories');
        $this->get('/admin/products/create')->assertOk()->assertSee('Anklets');

        $this->post('/admin/products', [
            'name' => 'Gold Anklet', 'type' => 'Anklets', 'detail' => 'Gold',
            'price' => 15000, 'stock' => 5, 'art' => 'band', 'active' => 1,
        ])->assertRedirect('/admin');
        $product = Product::where('name', 'Gold Anklet')->firstOrFail();
        $this->get('/admin/products/'.$product->id.'/edit')->assertOk()->assertSee('Anklets');
        $this->seed(\Database\Seeders\HomepageSeeder::class);
        $this->get('/')->assertOk()->assertSee('data-filter="Anklets"', false);

        $this->put('/admin/products/'.$product->id, [
            'name' => 'Gold Anklet', 'type' => 'Missing category', 'detail' => 'Gold',
            'price' => 15000, 'stock' => 5, 'art' => 'band',
        ])->assertSessionHasErrors('type');
        $this->assertSame('Anklets', $product->fresh()->type);
    }

    public function test_renaming_a_category_updates_active_and_archived_products(): void
    {
        $this->seed();
        $category = Category::where('name', 'Rings')->firstOrFail();
        $products = Product::where('type', 'Rings')->get();
        $this->assertGreaterThan(1, $products->count());
        $products->first()->delete();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->put('/admin/categories/'.$category->id, ['name' => 'Fine Rings'])
            ->assertRedirect('/admin/categories');

        foreach ($products as $product) {
            $this->assertDatabaseHas('products', ['id' => $product->id, 'type' => 'Fine Rings']);
        }

        $this->get('/')->assertOk()->assertSee('data-filter="Fine Rings"', false);
    }

    public function test_used_categories_are_kept_and_unused_categories_can_be_deleted(): void
    {
        $this->seed();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $category = Category::where('name', 'Rings')->firstOrFail();
        $this->delete('/admin/categories/'.$category->id)->assertSessionHasErrors('category');
        Product::where('type', 'Rings')->delete();
        $this->delete('/admin/categories/'.$category->id)->assertSessionHasErrors('category');
        $this->assertDatabaseHas('categories', ['id' => $category->id]);

        $unused = Category::create(['name' => 'Unused']);
        $this->delete('/admin/categories/'.$unused->id)->assertRedirect('/admin/categories');
        $this->assertDatabaseMissing('categories', ['id' => $unused->id]);
    }

    public function test_category_names_are_validated(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['', '  ', 'rings', ' Rings ', str_repeat('a', 101)] as $name) {
            $this->post('/admin/categories', ['name' => $name])->assertSessionHasErrors('name');
        }

        $category = Category::where('name', 'Earrings')->firstOrFail();
        $this->put('/admin/categories/'.$category->id, ['name' => 'Rings'])
            ->assertSessionHasErrors('name');
        $this->assertSame('Earrings', $category->fresh()->name);
    }

    public function test_custom_categories_from_before_the_migration_are_preserved(): void
    {
        $migration = require database_path('migrations/2026_11_20_000001_create_categories.php');
        $migration->down();
        $product = Product::create([
            'name' => 'Vintage Brooch', 'type' => 'Brooches', 'detail' => 'Gold',
            'price' => 20000, 'stock' => 1,
        ]);
        $product->delete();
        $migration->up();

        $this->assertDatabaseHas('categories', ['name' => 'Brooches']);
        Category::where('name', 'Brooches')->firstOrFail()->update(['name' => 'Vintage']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'type' => 'Vintage']);
    }

    public function test_category_management_requires_an_admin(): void
    {
        $category = Category::firstOrFail();
        $this->get('/admin/categories')->assertRedirect('/login');
        $this->post('/admin/categories', ['name' => 'Anklets'])->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get('/admin/categories')->assertForbidden();
        $this->post('/admin/categories', ['name' => 'Anklets'])->assertForbidden();
        $this->put('/admin/categories/'.$category->id, ['name' => 'Changed'])->assertForbidden();
        $this->delete('/admin/categories/'.$category->id)->assertForbidden();
    }
}

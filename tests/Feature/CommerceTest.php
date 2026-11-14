<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommerceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.default_mode' => 'live']);
        $this->seed();
        \App\Models\DeliveryRate::where('state', 'Lagos')->update(['active' => true, 'fee' => 0, 'pay_on_delivery' => true]);
    }

    private function address(): array
    {
        return ['name' => 'Test Customer', 'phone' => '08012345678', 'address' => '12 Test Road', 'city' => 'Lagos', 'state' => 'Lagos', 'delivery_rate_id' => \App\Models\DeliveryRate::where('state', 'Lagos')->value('id'), 'terms' => '1'];
    }

    public function test_registration_login_logout_and_admin_role_cannot_be_injected(): void
    {
        $this->post('/register', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'Password123', 'password_confirmation' => 'Password123', 'is_admin' => true])->assertRedirect('/account');
        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertFalse($user->is_admin);
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'Password123'])->assertRedirect('/account');
        $this->get('/account')->assertOk();
    }

    public function test_customer_cannot_access_admin_and_guests_cannot_checkout(): void
    {
        $this->get('/checkout')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
        $this->post('/admin/products', [])->assertForbidden();
    }

    public function test_admin_product_crud_and_pages(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->get('/admin/products/create')->assertOk();
        $data = ['name' => 'Pearl Bracelet', 'type' => 'Bracelets', 'detail' => 'Pearl concept', 'price' => 15000, 'stock' => 8, 'art' => 'band', 'active' => 1];
        $this->post('/admin/products', $data)->assertRedirect('/admin');
        $product = Product::where('name', 'Pearl Bracelet')->firstOrFail();
        $this->get('/admin/products/'.$product->id.'/edit')->assertOk();
        $this->put('/admin/products/'.$product->id, array_replace($data, ['price' => 17000]))->assertRedirect('/admin');
        $this->assertEquals(17000, $product->fresh()->price);
        $this->delete('/admin/products/'.$product->id)->assertRedirect();
        $this->assertSoftDeleted($product);
        $this->get('/products/'.$product->id)->assertNotFound();
    }

    public function test_checkout_uses_server_price_and_stores_immutable_items(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->withSession(['cart' => [1 => 2]])->get('/checkout')->assertOk();
        $this->post('/checkout', $this->address() + ['total' => 1])->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertEquals(56000, $order->total);
        $this->assertEquals(98, Product::find(1)->stock);
        $this->get('/bag')->assertJsonPath('count', 0);
        Product::find(1)->update(['name' => 'Changed', 'price' => 1]);
        $this->assertEquals('The Lumière Ring', $order->items->first()->name);
        $this->get('/orders/'.$order->id)->assertOk()->assertSee('The Lumière Ring');
        $this->actingAs(User::factory()->create())->get('/orders/'.$order->id)->assertForbidden();
    }

    public function test_unavailable_stock_rolls_back_order_and_inventory(): void
    {
        Product::find(2)->update(['stock' => 0]);
        $this->actingAs(User::factory()->create())->withSession(['cart' => [1 => 1, 2 => 1]])->post('/checkout', $this->address())->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(100, Product::find(1)->stock);
    }

    public function test_cancel_restock_happens_once_and_terminal_state_cannot_change(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->withSession(['cart' => [1 => 1]])->post('/checkout', $this->address());
        $order = Order::firstOrFail();
        $this->put('/admin/orders/'.$order->id, ['status' => 'cancelled'])->assertRedirect();
        $this->assertEquals(100, Product::find(1)->stock);
        $this->put('/admin/orders/'.$order->id, ['status' => 'cancelled'])->assertSessionHasErrors('status');
        $this->assertEquals(100, Product::find(1)->stock);
    }

    public function test_wishlist_and_profile_are_owned_by_current_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/wishlist/1')->assertRedirect();
        $this->get('/wishlist')->assertOk()->assertSee('The Lumière Ring');
        $this->post('/wishlist/1');
        $this->assertCount(0, $user->wishlist()->get());
        $this->put('/account', ['name' => 'New name', 'phone' => '0801', 'address' => 'Home', 'is_admin' => true])->assertRedirect();
        $this->assertFalse($user->fresh()->is_admin);
        $this->assertEquals('New name', $user->fresh()->name);
    }

    public function test_password_reset_uses_one_time_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): bool {
            $this->get('/reset-password/'.$notification->token.'?email='.$user->email)->assertOk();
            $this->post('/reset-password', ['token' => $notification->token, 'email' => $user->email, 'password' => 'Newpassword123', 'password_confirmation' => 'Newpassword123'])->assertRedirect('/login');
            $this->post('/reset-password', ['token' => $notification->token, 'email' => $user->email, 'password' => 'Another123', 'password_confirmation' => 'Another123'])->assertSessionHasErrors('email');
            return true;
        });
    }

    public function test_product_photo_upload_and_public_pages(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();
        $image = UploadedFile::fake()->createWithContent('ring.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg=='));
        $this->actingAs($user)->put('/admin/products/1', ['name' => 'Photo ring', 'type' => 'Rings', 'detail' => 'Gold', 'price' => 28000, 'stock' => 10, 'art' => 'ring', 'active' => 1, 'image_upload' => $image])->assertRedirect('/admin');
        Storage::disk('public')->assertExists(Product::find(1)->image);
        $this->get('/products/1')->assertOk();
        $this->get('/')->assertOk()->assertSee('Photo ring');
    }
}

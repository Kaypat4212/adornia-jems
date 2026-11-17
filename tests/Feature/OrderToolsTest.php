<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderToolsTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $owner): Order
    {
        return Order::create([
            'user_id' => $owner->id, 'reference' => 'AJ-TRACK-1', 'name' => $owner->name,
            'phone' => '08000000000', 'address' => 'Test address', 'city' => 'Lagos',
            'state' => 'Lagos', 'total' => 25000, 'status' => 'confirmed',
        ]);
    }

    public function test_tracking_and_export_are_protected_and_tracking_is_visible_to_the_customer(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $details = ['carrier' => 'Test Courier', 'tracking_number' => 'SHIP-123'];
        $this->actingAs($owner)->put(route('admin.orders.tracking', $order), $details)->assertForbidden();
        $this->get(route('admin.sales.export'))->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->put(route('admin.orders.tracking', $order), $details)->assertRedirect();
        $this->get(route('admin.sales.export', ['status' => 'confirmed']))->assertDownload('adornia-sales.csv');
        $this->actingAs($owner)->get(route('orders.show', $order))->assertOk()->assertSee('SHIP-123');
    }

    public function test_reorder_is_limited_to_the_owner_and_current_stock(): void
    {
        $this->seed();
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $product = Product::firstOrFail();
        $product->update(['stock' => 2]);
        $order->items()->create([
            'product_id' => $product->id, 'name' => $product->name, 'price' => 1000, 'quantity' => 4,
        ]);

        $this->actingAs(User::factory()->create())->post(route('orders.reorder', $order))->assertForbidden();
        $this->actingAs($owner)->withSession(['cart' => [$product->id => 1]])
            ->post(route('orders.reorder', $order))->assertRedirect(route('checkout'))
            ->assertSessionHas('cart', [$product->id => 2]);
        $this->getJson(route('bag.show'))->assertJsonPath('total', $product->price * 2);
    }
}

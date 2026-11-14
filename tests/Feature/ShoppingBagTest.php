<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ShoppingBagTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_storefront_renders_the_brand_and_catalog(): void
    {
        $this->get('/')->assertOk()->assertSee('Adornia Jems')->assertSee('The Lumière Ring');
    }

    public function test_bag_uses_catalog_prices_and_accumulates_quantities(): void
    {
        $this->postJson('/bag', ['product_id' => 1, 'price' => 1])->assertOk()->assertJsonPath('total', 28000);
        $this->postJson('/bag', ['product_id' => 1])->assertOk()->assertJsonPath('count', 2)->assertJsonPath('total', 56000);
        $this->getJson('/bag')->assertOk()->assertJsonPath('items.0.quantity', 2);
        $this->deleteJson('/bag/1')->assertOk()->assertJsonPath('count', 1);
        $this->deleteJson('/bag/1')->assertOk()->assertJsonPath('count', 0)->assertJsonPath('total', 0);
    }

    public function test_unknown_products_are_rejected(): void
    {
        $this->postJson('/bag', ['product_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->getJson('/bag')->assertJsonPath('count', 0);
    }

    public function test_bag_is_empty_for_a_new_session(): void
    {
        $this->withSession(['cart' => [1 => 2]])->getJson('/bag')->assertJsonPath('count', 2);
        $this->app['session']->flush();
        $this->getJson('/bag')->assertJsonPath('count', 0);
    }

    public function test_quantity_limit_is_enforced(): void
    {
        $this->withSession(['cart' => [1 => 99]])->postJson('/bag', ['product_id' => 1])->assertUnprocessable();
        $this->getJson('/bag')->assertJsonPath('count', 99);
    }
}

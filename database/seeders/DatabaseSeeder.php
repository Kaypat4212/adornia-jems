<?php
namespace Database\Seeders;
use App\Models\Product;
use Illuminate\Database\Seeder;
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([HomepageSeeder::class, DeliverySeeder::class]);
        foreach (config('catalog') as $product) {
            Product::firstOrCreate(['id' => $product['id']], $product + ['stock' => 100, 'description' => 'A sample Adornia Jems design. Final materials and specifications will be confirmed before launch.']);
        }
    }
}

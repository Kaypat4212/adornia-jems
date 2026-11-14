<?php
namespace Database\Seeders;
use App\Models\DeliveryRate;
use Illuminate\Database\Seeder;

class DeliverySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Lagos' => 'Within Lagos', 'Enugu' => 'Within Enugu', '*' => 'Interstate delivery'] as $state => $name) {
            DeliveryRate::firstOrCreate(['state' => $state, 'name' => $name], ['active' => false, 'pay_on_delivery' => false, 'fee' => null]);
        }
    }
}

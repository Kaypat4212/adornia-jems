<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\{HomepageSection, StoreSetting};
class HomepageSeeder extends Seeder
{
    public function run(): void
    {
        StoreSetting::firstOrCreate(['id' => 1], ['content' => ['brand' => 'Adornia Jems', 'announcement' => 'ADORNIA JEMS — EVERYDAY, A LITTLE EXTRAORDINARY', 'footer' => 'Jewelry for your own kind of extraordinary.', 'title' => 'Adornia Jems — A little extraordinary.', 'description' => 'Discover jewelry for your own kind of extraordinary.', 'accent' => '#9c794b', 'background' => '#f7f5ef', 'ink' => '#302c24', 'shipping' => 0, 'cod' => true, 'nav_collection' => 'The collection', 'nav_story' => 'Our world', 'nav_3d' => 'Explore in 3D']]);
        $sections = [
            ['hero', ['eyebrow' => 'The art of a little sparkle', 'heading' => 'Made to be your', 'highlight' => 'signature.', 'body' => 'A quiet statement. A little light. Discover jewelry that makes the everyday feel a little more extraordinary.', 'button' => 'EXPLORE THE COLLECTION', 'link' => '#collection', 'secondary' => 'A closer look', 'secondary_link' => '#experience', 'ring_name' => 'The Lumière Ring', 'badge' => 'A NEW PERSPECTIVE', 'hint' => 'DRAG TO DISCOVER EVERY ANGLE · 3D CONCEPT']],
            ['ticker', ['item1' => 'YOUR OWN KIND OF SHINE', 'item2' => 'DETAILS WORTH A CLOSER LOOK', 'item3' => 'LITTLE PIECES, BIG FEELINGS']],
            ['collection', ['eyebrow' => 'Find your next favorite', 'heading' => 'The everyday extraordinary.', 'note' => 'Sample designs and prices. Replace these with your real catalog before launch.']],
            ['story', ['eyebrow' => 'The world of Adornia', 'heading' => 'Small details. Something personal.', 'body' => 'Some pieces become part of your story. The ring you reach for every morning. A necklace that feels like you. A little sparkle, just because.', 'body2' => 'Welcome to Adornia Jems — a space for finding your own expression, one beautiful detail at a time.', 'button' => 'FIND YOUR SIGNATURE PIECE', 'link' => '#collection']],
        ];
        foreach ($sections as $index => [$type, $content]) {
            HomepageSection::firstOrCreate(['type' => $type], ['content' => $content, 'sort_order' => ($index + 1) * 10, 'active' => true]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use Illuminate\Database\Seeder;

class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('Demo data is forbidden in production.');
        }
        $restaurant = Restaurant::updateOrCreate(['slug' => 'demo-kueche'], [
            'name' => 'Die Demo-Küche', 'description' => 'Frisch gekocht, lokal geliefert. Ein Beispielrestaurant zum Ausprobieren.',
            'cuisine' => 'Mediterran · Pizza · Bowls', 'address' => 'Beispielstraße 1', 'city' => 'Hilden',
            'is_published' => true, 'is_accepting_orders' => true, 'minimum_order_cents' => 1500,
            'delivery_fee_cents' => 250, 'estimated_minutes' => 35, 'delivery_postcodes' => ['40721', '40723', '40724'],
        ]);
        foreach ([
            ['Pizza Margherita', 'Pizza', 'Tomatensauce, Mozzarella und frisches Basilikum.', 1090, ['Gluten', 'Milch']],
            ['Pizza Verdure', 'Pizza', 'Gegrilltes Gemüse, Mozzarella und Rucola.', 1390, ['Gluten', 'Milch']],
            ['Mediterrane Bowl', 'Bowls', 'Reis, Kichererbsen, knackiges Gemüse und Zitronendressing.', 1290, []],
            ['Ofenkartoffeln', 'Beilagen', 'Mit Rosmarin und hausgemachtem Kräuterdip.', 590, ['Milch']],
        ] as $index => $item) {
            $restaurant->menuItems()->updateOrCreate(['name' => $item[0]], [
                'category' => $item[1], 'description' => $item[2], 'price_cents' => $item[3],
                'allergens' => $item[4], 'sort_order' => $index, 'is_available' => true,
            ]);
        }
    }
}

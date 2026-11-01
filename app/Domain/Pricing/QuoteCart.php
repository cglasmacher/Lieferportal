<?php

namespace App\Domain\Pricing;

use App\Models\Restaurant;
use Illuminate\Validation\ValidationException;

final class QuoteCart
{
    public function calculate(Restaurant $restaurant, array $input): array
    {
        if (! $restaurant->is_accepting_orders) {
            throw ValidationException::withMessages(['restaurant' => 'Das Restaurant nimmt gerade keine Bestellungen an.']);
        }
        $delivery = $input['fulfillment'] === 'delivery';
        if ($delivery && ! in_array($input['postcode'] ?? '', $restaurant->delivery_postcodes, true)) {
            throw ValidationException::withMessages(['postcode' => 'Diese Adresse liegt außerhalb des Liefergebiets.']);
        }
        $catalog = $restaurant->menuItems()->where('is_available', true)
            ->whereIn('id', array_column($input['items'], 'menu_item_id'))->get()->keyBy('id');
        $lines = [];
        foreach ($input['items'] as $item) {
            $product = $catalog->get($item['menu_item_id']);
            if (! $product) {
                throw ValidationException::withMessages(['items' => 'Ein Artikel ist nicht mehr verfügbar oder gehört nicht zu diesem Restaurant.']);
            }
            $lines[] = ['menu_item_id' => $product->id, 'name' => $product->name,
                'quantity' => $item['quantity'], 'unit_price_cents' => $product->price_cents,
                'total_cents' => $product->price_cents * $item['quantity']];
        }
        $subtotal = array_sum(array_column($lines, 'total_cents'));
        if ($delivery && $subtotal < $restaurant->minimum_order_cents) {
            throw ValidationException::withMessages(['items' => 'Der Mindestbestellwert für Lieferung ist noch nicht erreicht.']);
        }
        $fee = $delivery ? $restaurant->delivery_fee_cents : 0;

        return ['currency' => 'EUR', 'restaurant_slug' => $restaurant->slug, 'fulfillment' => $input['fulfillment'],
            'lines' => $lines, 'subtotal_cents' => $subtotal, 'delivery_fee_cents' => $fee,
            'total_cents' => $subtotal + $fee, 'checkout_available' => false,
            'message' => 'Vorschau: Es wird noch keine Bestellung übermittelt und kein Geld eingezogen.'];
    }
}

export type MenuItem = { id: number; category: string; name: string; description: string; price_cents: number; allergens: string[] };
export type Restaurant = { slug: string; name: string; description: string; cuisine: string; city: string; is_accepting_orders: boolean; minimum_order_cents: number; delivery_fee_cents: number; estimated_minutes: number; address?: string; delivery_postcodes?: string[]; menu_items?: MenuItem[]; payment_methods?: {id: string; label: string; available: boolean}[] };
export type Quote = { currency: string; subtotal_cents: number; delivery_fee_cents: number; total_cents: number; message: string; checkout_available: boolean };
export const euro = (cents: number) => new Intl.NumberFormat('de-DE', {style: 'currency', currency: 'EUR'}).format(cents / 100);

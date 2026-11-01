import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { euro, type Restaurant, type Quote } from './types';

async function api<T>(url: string, options?: RequestInit): Promise<T> {
    const response = await fetch(url, { ...options, headers: { 'Accept': 'application/json', ...options?.headers } });
    const json = await response.json();
    if (!response.ok) {
        const messages = Object.values(json.errors ?? {}).flat() as string[];
        throw new Error(messages[0] ?? json.message ?? 'Die Anfrage konnte nicht abgeschlossen werden.');
    }
    return json.data as T;
}
function App() {
    const [restaurants, setRestaurants] = useState<Restaurant[]>([]);
    const [restaurant, setRestaurant] = useState<Restaurant | null>(null);
    const [cart, setCart] = useState<Record<number, number>>({});
    const [fulfillment, setFulfillment] = useState<'delivery' | 'pickup'>('delivery');
    const [postcode, setPostcode] = useState('');
    const [quote, setQuote] = useState<Quote | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [checking, setChecking] = useState(false);
    const revision = useRef(0);
    useEffect(() => {
        let active = true;
        const slug = window.location.pathname.match(/^\/r\/([^/]+)$/)?.[1];
        const request = slug
            ? api<Restaurant>(`/api/v1/restaurants/${encodeURIComponent(decodeURIComponent(slug))}`).then(r => { if(active) setRestaurant(r); })
            : api<Restaurant[]>('/api/v1/restaurants').then(r => { if(active) setRestaurants(r); });
        request.catch(e => { if(active) setError(e.message); }).finally(() => { if(active) setLoading(false); });
        return () => { active = false; };
    }, []);
    const invalidate = () => { revision.current++; setQuote(null); setError(''); setChecking(false); };
    const adjust = (id: number, change: number) => {
        invalidate();
        setCart(previous => {
            const next = {...previous};
            next[id] = Math.max(0, Math.min(20, (next[id] ?? 0) + change));
            if(next[id] === 0) delete next[id];
            return next;
        });
    };
    const lines = (restaurant?.menu_items ?? []).filter(item => cart[item.id]);
    const subtotal = lines.reduce((sum, item) => sum + item.price_cents * cart[item.id], 0);
    const delivery = fulfillment === 'delivery' && lines.length > 0 ? restaurant?.delivery_fee_cents ?? 0 : 0;
    const count = Object.values(cart).reduce((a,b) => a+b,0);
    async function checkCart() {
        if(!restaurant) return;
        const current = ++revision.current;
        setChecking(true); setError(''); setQuote(null);
        try {
            const checked = await api<Quote>(`/api/v1/restaurants/${restaurant.slug}/quote`, {
                method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''},
                body:JSON.stringify({fulfillment, postcode:fulfillment === 'delivery' ? postcode : null,
                    items:lines.map(item => ({menu_item_id:item.id, quantity:cart[item.id]}))}),
            });
            if(current === revision.current) setQuote(checked);
        } catch(e) { if(current === revision.current) setError(e instanceof Error ? e.message : 'Bitte versuche es erneut.'); }
        finally { if(current === revision.current) setChecking(false); }
    }
    return <>
        <div className="preview-banner">Entwicklungsvorschau · Keine echten Bestellungen oder Zahlungen</div>
        <header><a className="brand" href="/" aria-label="Lieferportal Startseite"><span className="brand-icon">↗</span> lieferportal<span className="brand-dot">.</span></a><span className="header-note">Gutes Essen. Faire Wege.</span><a className="header-note" href="/portal">Restaurant-Login</a><span className="location">⌖ Hilden</span></header>
        <main>
            {loading ? <p role="status" className="loading">Restaurants werden geladen …</p> : restaurant ? <>
                <a className="back" href="/">← Alle Restaurants</a>
                <section className="restaurant-hero">
                    <div><span className="eyebrow">DEIN RESTAURANT. DEINE NACHBARSCHAFT.</span><h1>{restaurant.name}</h1><p>{restaurant.description}</p><div className="hero-tags"><span>{restaurant.cuisine}</span><span>{restaurant.is_accepting_orders ? 'Vorschau geöffnet' : 'Aktuell pausiert'}</span></div></div>
                    <div className="hero-art" aria-hidden="true"><span>frisch<br/><i>von hier.</i></span><div className="art-ring"/></div>
                </section>
                <div className="facts"><span>◷ ca. {restaurant.estimated_minutes} Min.</span><span>Lieferung {euro(restaurant.delivery_fee_cents)}</span><span>Mindestens {euro(restaurant.minimum_order_cents)} bei Lieferung</span></div>
                <div className="order-layout">
                    <section className="menu" aria-label="Speisekarte"><div className="section-title"><h2>Die Speisekarte</h2><span>Frisch für dich</span></div>
                        {[...new Set(restaurant.menu_items?.map(item=>item.category))].map(category=><section key={category}><h3>{category}</h3>{restaurant.menu_items?.filter(item=>item.category===category).map(item=><article className="dish" key={item.id}>
                            <div><h4>{item.name}</h4><p>{item.description}</p><small>{item.allergens.length ? `Allergene: ${item.allergens.join(', ')}` : 'Keine Allergene angegeben – bitte beim Restaurant nachfragen'}</small><strong>{euro(item.price_cents)}</strong></div>
                            <button className="add" disabled={!restaurant.is_accepting_orders || (cart[item.id] ?? 0)>=20} onClick={()=>adjust(item.id,1)} aria-label={`${item.name} hinzufügen`}>+</button>
                        </article>)}</section>)}
                    </section>
                    <aside className="basket" aria-label="Warenkorb"><div className="basket-heading"><h2>Deine Bestellung</h2><span>{count}</span></div>
                        <div className="toggle" aria-label="Bestellart"><button aria-pressed={fulfillment==='delivery'} className={fulfillment==='delivery'?'active':''} onClick={()=>{invalidate();setFulfillment('delivery');}}>Lieferung</button><button aria-pressed={fulfillment==='pickup'} className={fulfillment==='pickup'?'active':''} onClick={()=>{invalidate();setFulfillment('pickup');}}>Abholung</button></div>
                        {fulfillment==='delivery' && <label className="postcode">Deine Postleitzahl<input inputMode="numeric" autoComplete="postal-code" placeholder="z. B. 40721" value={postcode} maxLength={5} onChange={e=>{invalidate();setPostcode(e.target.value.replace(/\D/g,''));}}/></label>}
                        {!lines.length ? <div className="empty"><span aria-hidden="true">♧</span><h3>Was darf es sein?</h3><p>Wähle etwas Leckeres aus der Karte.</p></div> : <div className="basket-items">{lines.map(item=><div className="basket-item" key={item.id}><div><b>{item.name}</b><span>{euro(item.price_cents*cart[item.id])}</span></div><div className="quantity"><button onClick={()=>adjust(item.id,-1)} aria-label={`Einmal ${item.name} entfernen`}>−</button><span aria-label="Anzahl">{cart[item.id]}</span><button disabled={cart[item.id]>=20} onClick={()=>adjust(item.id,1)} aria-label={`Einmal ${item.name} hinzufügen`}>+</button></div></div>)}</div>}
                        <div className="totals"><div><span>Speisen</span><span>{euro(quote?.subtotal_cents ?? subtotal)}</span></div><div><span>Lieferkosten</span><span>{euro(quote?.delivery_fee_cents ?? delivery)}</span></div><div className="grand-total"><b>Gesamt</b><b>{euro(quote?.total_cents ?? subtotal+delivery)}</b></div></div>
                        <button className="primary" disabled={!count || checking || !restaurant.is_accepting_orders} onClick={checkCart}>{checking?'Wird geprüft …':'Warenkorb prüfen'}</button>
                        {error && <p className="error" role="alert">{error}</p>}{quote && <p className="success" role="status">{quote.message}</p>}
                        <fieldset className="payments" disabled><legend>Zahlungsmöglichkeiten in Vorbereitung</legend>{restaurant.payment_methods?.map(method=><label key={method.id}><input type="radio" name="payment" value={method.id}/>{method.label}</label>)}</fieldset>
                        <p className="basket-note">Verkauf und Lieferung durch das Restaurant. Der Zahlungsstart folgt nach der Händleranbindung.</p>
                    </aside>
                </div>
            </> : <>
                <section className="landing"><span className="eyebrow">DEINE STADT. DEIN GESCHMACK.</span><h1>Lokal bestellen.<br/><em>Fair genießen.</em></h1><p>Entdecke Restaurants aus deiner Nachbarschaft.<br/>Ein gemeinsames Portal mit 5 % Vermittlungsprovision für Restaurants.</p></section>
                {error && <p className="error" role="alert">{error}</p>}
                <div className="section-title"><h2>Restaurants in Hilden</h2><span>Direkt aus deiner Nachbarschaft</span></div>
                <div className="restaurant-grid">{restaurants.map(r=><a className="restaurant-card" href={`/r/${r.slug}`} key={r.slug}><div className="card-art" aria-hidden="true">Guten<br/><i>Appetit.</i></div><div className="card-content"><span className="eyebrow">{r.city} · {r.is_accepting_orders?'Verfügbar':'Pausiert'}</span><h2>{r.name}</h2><p>{r.cuisine}</p><div className="card-meta"><span>◷ {r.estimated_minutes} Min.</span><span>{euro(r.delivery_fee_cents)} Lieferung</span><b>→</b></div></div></a>)}</div>
                {!restaurants.length && !error && <p>Noch keine Restaurants veröffentlicht.</p>}
            </>}
        </main><footer><span className="brand">lieferportal.</span><p>Entwicklungsvorschau mit Beispieldaten. Noch kein öffentliches Bestellangebot.</p></footer>
    </>;
}
createRoot(document.getElementById('root')!).render(<React.StrictMode><App/></React.StrictMode>);

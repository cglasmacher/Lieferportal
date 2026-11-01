import { merchantApi as api } from './merchantApi';
import { Billing, ActivityLog } from './Billing';
import React, { useEffect, useState, type FormEvent } from 'react';
import { createRoot } from 'react-dom/client';
import { euro, type MenuItem } from './types';

type Member = {slug:string;name:string;role:'owner'|'manager'|'staff'};
type Session = {user:{name:string;email:string};restaurants:Member[]};
type ManagedItem = MenuItem & {is_available:boolean;sort_order:number};
type Details = Member & {billing_plan:string;can_edit:boolean;is_published:boolean;is_accepting_orders:boolean;estimated_minutes:number;minimum_order_cents:number;delivery_fee_cents:number;delivery_postcodes:string[];menu_items:ManagedItem[]};
function cents(value:string):number {
    if(!/^\d{1,4}([.,]\d{1,2})?$/.test(value)) throw new Error('Bitte einen gültigen Eurobetrag mit höchstens zwei Nachkommastellen eingeben.');
    const [whole,fraction='']=value.replace(',','.').split('.');
    return Number(whole)*100+Number(fraction.padEnd(2,'0'));
}
const amount = (value:number) => (value/100).toFixed(2).replace('.',',');
function Login() {
    const [email,setEmail]=useState(''); const [password,setPassword]=useState('');
    const [busy,setBusy]=useState(false); const [error,setError]=useState('');
    async function submit(e:FormEvent) {
        e.preventDefault();setBusy(true);setError('');
        try { await api('/login','POST',{email,password}); window.location.assign('/portal'); }
        catch(e) { setError(e instanceof Error?e.message:'Anmeldung fehlgeschlagen.');setPassword('');setBusy(false); }
    }
    return <section className="login-panel"><span className="eyebrow">FÜR UNSERE RESTAURANTS</span><h1>Willkommen<br/>im Portal.</h1><p>Speisekarte pflegen. Verfügbarkeit steuern.<br/>Alles an einem Ort.</p>
        <form onSubmit={submit} className="portal-form"><label>E-Mail<input type="email" autoComplete="username" required maxLength={254} value={email} onChange={e=>setEmail(e.target.value)}/></label><label>Passwort<input type="password" autoComplete="current-password" required value={password} onChange={e=>setPassword(e.target.value)}/></label><button className="primary" disabled={busy}>{busy?'Anmeldung läuft …':'Anmelden'}</button>{error&&<p role="alert" className="error">{error}</p>}</form>
        <p className="hint">Zugang noch nicht eingerichtet? Wende dich an die Plattformverwaltung.</p></section>;
}
function ItemEditor({item,busy,onSave,onCancel}:{item?:ManagedItem;busy:boolean;onSave:(body:unknown,id?:number)=>Promise<void>;onCancel:()=>void}) {
    const [name,setName]=useState(item?.name ?? '');const [category,setCategory]=useState(item?.category ?? '');
    const [description,setDescription]=useState(item?.description ?? '');const [price,setPrice]=useState(item?amount(item.price_cents):'');
    const [allergens,setAllergens]=useState(item?.allergens.join(', ') ?? '');const [available,setAvailable]=useState(item?.is_available ?? true);
    const [sort,setSort]=useState(item?.sort_order ?? 0);const [error,setError]=useState('');
    async function submit(e:FormEvent) { e.preventDefault();setError('');
        try { await onSave({name,category,description,price_cents:cents(price),allergens:[...new Set(allergens.split(',').map(x=>x.trim()).filter(Boolean))],is_available:available,sort_order:sort},item?.id); }
        catch(e) { setError(e instanceof Error?e.message:'Speichern fehlgeschlagen.'); }
    }
    return <section className="portal-box"><h2>{item?'Gericht bearbeiten':'Neues Gericht'}</h2><form onSubmit={submit} className="portal-form"><fieldset disabled={busy}>
        <label>Name<input required maxLength={120} value={name} onChange={e=>setName(e.target.value)}/></label><div className="form-row"><label>Kategorie<input required maxLength={80} value={category} onChange={e=>setCategory(e.target.value)}/></label><label>Preis in €<input required inputMode="decimal" value={price} onChange={e=>setPrice(e.target.value)}/></label></div>
        <label>Beschreibung<textarea required maxLength={2000} rows={3} value={description} onChange={e=>setDescription(e.target.value)}/></label><label>Allergene, mit Komma getrennt<input value={allergens} onChange={e=>setAllergens(e.target.value)}/></label><small>Bitte vollständig anhand der Zutaten prüfen. Ein leeres Feld wird öffentlich als „keine angegeben“ angezeigt.</small>
        <div className="form-row"><label>Reihenfolge<input type="number" required min={0} max={65000} value={sort} onChange={e=>setSort(Number(e.target.value))}/></label><label className="checkbox"><input type="checkbox" checked={available} onChange={e=>setAvailable(e.target.checked)}/>Verfügbar</label></div>
        <div className="form-actions"><button className="primary">{busy?'Wird gespeichert …':'Gericht speichern'}</button><button type="button" className="secondary" onClick={onCancel}>Abbrechen</button></div></fieldset>{error&&<p role="alert" className="error">{error}</p>}</form></section>;
}
function Settings({restaurant,busy,onSave}:{restaurant:Details;busy:boolean;onSave:(data:unknown)=>Promise<void>}) {
    const [open,setOpen]=useState(restaurant.is_accepting_orders);const [minutes,setMinutes]=useState(restaurant.estimated_minutes);
    const [minimum,setMinimum]=useState(amount(restaurant.minimum_order_cents));const [fee,setFee]=useState(amount(restaurant.delivery_fee_cents));
    const [codes,setCodes]=useState(restaurant.delivery_postcodes.join(', '));const [error,setError]=useState('');
    async function submit(e:FormEvent) {e.preventDefault();setError('');try {await onSave({is_accepting_orders:open,estimated_minutes:minutes,minimum_order_cents:cents(minimum),delivery_fee_cents:cents(fee),delivery_postcodes:[...new Set(codes.split(',').map(x=>x.trim()).filter(Boolean))]});}catch(e){setError(e instanceof Error?e.message:'Speichern fehlgeschlagen.');}}
    return <section className="portal-box"><h2>Lieferung & Verfügbarkeit</h2><form className="portal-form" onSubmit={submit}><fieldset disabled={busy||!restaurant.can_edit}>
        <label className="checkbox"><input type="checkbox" checked={open} onChange={e=>setOpen(e.target.checked)}/>Bestellannahme aktiv</label>
        <label>Geschätzte Zeit in Minuten<input type="number" required min={5} max={240} value={minutes} onChange={e=>setMinutes(Number(e.target.value))}/></label>
        <div className="form-row"><label>Mindestbestellwert in €<input inputMode="decimal" required value={minimum} onChange={e=>setMinimum(e.target.value)}/></label><label>Lieferkosten in €<input inputMode="decimal" required value={fee} onChange={e=>setFee(e.target.value)}/></label></div>
        <label>Liefer-Postleitzahlen, mit Komma getrennt<input value={codes} onChange={e=>setCodes(e.target.value)}/></label><small>Ohne Postleitzahlen ist nur Abholung möglich. Der öffentliche Checkout bleibt in dieser Entwicklungsphase gesperrt.</small>
        {restaurant.can_edit&&<button className="primary">Einstellungen speichern</button>}</fieldset>{error&&<p className="error" role="alert">{error}</p>}</form></section>;
}
function Portal() {
    const [session,setSession]=useState<Session|null>(null);const [slug,setSlug]=useState('');const [restaurant,setRestaurant]=useState<Details|null>(null);
    const [busy,setBusy]=useState(false);const [loading,setLoading]=useState(true);const [error,setError]=useState('');const [notice,setNotice]=useState('');
    const [editor,setEditor]=useState<ManagedItem|'new'|null>(null);
    useEffect(()=>{let active=true;api<Session>('/session').then(data=>{if(active){setSession(data);setSlug(data.restaurants[0]?.slug ?? '');}}).catch(e=>{if(active)setError(e.message);}).finally(()=>{if(active)setLoading(false);});return()=>{active=false;};},[]);
    useEffect(()=>{if(!slug)return;let active=true;setRestaurant(null);setEditor(null);setError('');setNotice('');api<Details>(`/restaurants/${slug}`).then(data=>{if(active)setRestaurant(data);}).catch(e=>{if(active)setError(e.message);});return()=>{active=false;};},[slug]);
    async function save(path:string,method:string,data:unknown) {setBusy(true);setNotice('');try {await api(path,method,data);const fresh=await api<Details>(`/restaurants/${slug}`);setRestaurant(fresh);setNotice('Änderungen gespeichert.');}finally{setBusy(false);}}
    async function logout() {setBusy(true);try {await api('/logout','POST');window.location.assign('/portal/login');}catch(e){setError(e instanceof Error?e.message:'Abmeldung fehlgeschlagen.');setBusy(false);}}
    return <><div className="portal-title"><div><span className="eyebrow">DEIN RESTAURANTPORTAL</span><h1>Alles im Blick.</h1><p>{session?.user.name}</p></div><button className="secondary" disabled={busy} onClick={logout}>Abmelden</button></div>
        {error&&<p className="error" role="alert">{error}</p>}{notice&&<p className="success" role="status">{notice}</p>}
        {loading?<p role="status">Portal wird geladen …</p>:session&&!session.restaurants.length?<p>Deinem Zugang ist noch kein Restaurant zugeordnet. Bitte wende dich an die Plattformverwaltung.</p>:<label className="restaurant-select">Restaurant<select value={slug} disabled={busy} onChange={e=>setSlug(e.target.value)}>{session?.restaurants.map(r=><option key={r.slug} value={r.slug}>{r.name}</option>)}</select></label>}
        {restaurant&&<><div className="portal-status"><b>{restaurant.name}</b><span>{restaurant.is_published?'Veröffentlicht':'Noch unveröffentlicht'}</span><span>{restaurant.can_edit?'Bearbeitungszugriff':'Lesezugriff'}</span>{restaurant.is_published&&<a href={`/r/${restaurant.slug}`}>Kundenansicht ↗</a>}</div>
        {restaurant.role==='owner'&&<Billing key={restaurant.slug} slug={restaurant.slug}/>}
        <div className="portal-layout"><section><div className="section-title"><h2>Speisekarte</h2>{restaurant.can_edit&&['growth','pro'].includes(restaurant.billing_plan)&&<a className="secondary" href={`/api/v1/merchant/restaurants/${slug}/menu-export`}>CSV exportieren</a>}{restaurant.can_edit&&<button className="secondary" disabled={busy} onClick={()=>setEditor('new')}>+ Neues Gericht</button>}</div>
            {!restaurant.menu_items.length&&<p>Noch keine Gerichte angelegt.</p>}
            {restaurant.menu_items.map(item=><article className="dish" key={item.id}><div><small>{item.category} · {item.is_available?'Verfügbar':'Pausiert'}</small><h4>{item.name}</h4><p>{item.description}</p><strong>{euro(item.price_cents)}</strong></div>{restaurant.can_edit&&<button className="secondary" disabled={busy} onClick={()=>setEditor(item)}>Bearbeiten</button>}</article>)}
            {editor&&<ItemEditor key={editor==='new'?'new':editor.id} item={editor==='new'?undefined:editor} busy={busy} onCancel={()=>setEditor(null)} onSave={async(body,id)=>{await save(`/restaurants/${slug}/menu-items${id?'/'+id:''}`,id?'PUT':'POST',body);setEditor(null);}}/>}
        {restaurant.can_edit&&restaurant.billing_plan==='pro'&&<ActivityLog key={restaurant.slug} slug={restaurant.slug}/>}
        </section><Settings key={restaurant.slug} restaurant={restaurant} busy={busy} onSave={body=>save(`/restaurants/${slug}`,'PATCH',body)}/></div></>}
    </>;
}
function MerchantApp() {return <><div className="preview-banner">Entwicklungsvorschau · Bestell- und Zahlungsstart noch gesperrt</div><header><a className="brand" href="/">↗ lieferportal.</a><span className="header-note">Restaurantportal</span><a className="location" href="/">Zum Marktplatz</a></header><main>{window.location.pathname==='/portal/login'?<Login/>:<Portal/>}</main></>;}
createRoot(document.getElementById('root')!).render(<React.StrictMode><MerchantApp/></React.StrictMode>);

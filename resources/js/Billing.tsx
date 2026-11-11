import React, {useEffect,useState} from 'react';
import {merchantApi as api} from './merchantApi';
import {euro} from './types';
type Plan={key:string;name:string;monthly_net_cents:number;commission_basis_points:number;team_limit:number|null;menu_export:boolean;activity_log:boolean;planned_benefits:string[]};
type Draft={referral_discount_cents:number;id:string;month:string;plan_name:string;net_cents:number;vat_cents:number;gross_cents:number};
type Term={effective_month:string;plan_name:string;monthly_net_cents:number};
type ReferralStatus = { code: string | null; month: string; total: number; qualified: number; discount_cents: number };
type Account={referrals:ReferralStatus;effective_rates:{plan_name:string;commission_basis_points:number;source:string};drafts:Draft[];billing_terms:Term[];current_plan:Plan;plans:Plan[];monthly_fee:{net_cents:number;vat_cents:number;gross_cents:number};requested_plan:string|null;billing_live:boolean};
export function Billing({slug}:{slug:string}) {
    const [account,setAccount]=useState<Account|null>(null);const [error,setError]=useState('');const [notice,setNotice]=useState('');const [busy,setBusy]=useState(false);
    const path=`/restaurants/${slug}/billing`;
    useEffect(()=>{let active=true;api<Account>(path).then(x=>{if(active)setAccount(x);}).catch(e=>{if(active)setError(e.message);});return()=>{active=false;};},[path]);
    async function request(plan?:string) {setBusy(true);setError('');setNotice('');try {await api(path+'/request',plan?'POST':'DELETE',plan?{plan}:undefined);setAccount(await api<Account>(path));setNotice(plan?'Tarifanfrage gespeichert. Die Plattformverwaltung meldet sich zur Aktivierung.':'Tarifanfrage zurückgenommen.');}catch(e){setError(e instanceof Error?e.message:'Anfrage fehlgeschlagen.');}finally{setBusy(false);}}
    return <section className="billing-section"><div className="section-title"><h2>Dein Tarif & Abrechnung</h2><span>Pro Restaurantstandort · monatlich</span></div><p>Wähle zwischen umsatzabhängiger Provision und fester Monatspauschale. Alle Preise netto zuzüglich Umsatzsteuer. Zahlungsgebühren des jeweiligen Anbieters kommen separat hinzu.</p>
        {error&&<p role="alert" className="error">{error}</p>}{notice&&<p role="status" className="success">{notice}</p>}
        {account&&<><div className="plan-grid">{account.plans.map(p=><article className={'plan-card '+(account.current_plan.key===p.key?'current':'')} key={p.key}><span className="eyebrow">{p.name}</span><h3>{p.monthly_net_cents?euro(p.monthly_net_cents):'5 %'}<small>{p.monthly_net_cents?' / Monat':' pro Bestellung'}</small></h3><p>{p.monthly_net_cents?'0 % Bestellprovision':'Keine monatliche Grundgebühr'}</p><ul><li>Marktplatz und Restaurantseite</li><li>Speisekarte & Lieferparameter</li><li>{p.team_limit===null?'Unbegrenzte':p.team_limit} Teamzugänge</li>{p.menu_export&&<li>Speisekartenexport als CSV</li>}{p.activity_log&&<li>Änderungsprotokoll (letzte 50 Einträge)</li>}</ul>
        {p.planned_benefits.length>0&&<details><summary>Geplante Zusatzvorteile</summary><ul>{p.planned_benefits.map(x=><li key={x}>{x} <small>– geplant</small></li>)}</ul></details>}
        <button className="secondary" disabled={busy||account.current_plan.key===p.key||account.requested_plan===p.key} onClick={()=>request(p.key)}>{account.current_plan.key===p.key?'Aktueller Tarif':account.requested_plan===p.key?'Angefragt':'Tarif anfragen'}</button></article>)}</div>
        <div className="billing-summary"><b>Konfigurierter Funktionstarif: {account.current_plan.name}</b><p>Monatspauschale: {euro(account.monthly_fee.net_cents)} netto + {euro(account.monthly_fee.vat_cents)} USt. = {euro(account.monthly_fee.gross_cents)} brutto.</p><small>Konfigurationsvorschau für einen vollen Monat, keine Rechnung. Für die Abrechnung gelten die ausdrücklich gespeicherten Zeiträume. Es werden noch keine Abogebühren eingezogen. Tarifwechsel werden erst durch die Plattformverwaltung zugeordnet.</small>{account.requested_plan&&<p><button className="secondary" disabled={busy} onClick={()=>request()}>Tarifanfrage zurücknehmen</button></p>}</div>
        <ReferralStatusCard status={account.referrals} />
        <section className="billing-history"><h3>Vereinbarte Abrechnungszeiträume</h3><p>Berechnungsbasis für neue Bestellungen: {account.effective_rates.plan_name} · {account.effective_rates.commission_basis_points/100} % Provision. {account.effective_rates.source==='before_agreement'?'Der erste vereinbarte Abrechnungsmonat hat noch nicht begonnen.':account.effective_rates.source==='configuration'?'Bisher nur Tarifkonfiguration, noch kein vereinbarter Abrechnungsstart.':'Gemäß den gespeicherten Bedingungen.'}</p>{account.billing_terms.length?<ul>{account.billing_terms.map(t=><li key={t.effective_month}>Ab {t.effective_month.slice(0,7)}: {t.plan_name} · {euro(t.monthly_net_cents)} netto/Monat</li>)}</ul>:<p>Noch kein Abrechnungsstart vereinbart. Die Tarifzuordnung allein erzeugt keine Monatsabrechnung.</p>}
        <h3>Gespeicherte Monatsentwürfe</h3><p>Interne Abrechnungsvorbereitung, keine Rechnungen oder Zahlungsaufforderungen. Entwürfe werden durch die Plattformverwaltung erstellt.</p>
        {!account.drafts.length?<p>Noch keine Monatsentwürfe vorhanden.</p>:<div className="billing-table-wrap"><table className="billing-table"><thead><tr><th>Monat</th><th>Tarif</th><th>Rabatt</th><th>Netto</th><th>USt.</th><th>Brutto</th><th>Dokument</th></tr></thead><tbody>{account.drafts.map(d=><tr key={d.id}><td>{d.month}</td><td>{d.plan_name}</td><td>{d.referral_discount_cents ? euro(d.referral_discount_cents) : "–"}</td><td>{euro(d.net_cents)}</td><td>{euro(d.vat_cents)}</td><td>{euro(d.gross_cents)}</td><td><a href={`/portal/restaurants/${slug}/billing-drafts/${d.id}`} target="_blank" rel="noreferrer">Entwurf öffnen ↗</a></td></tr>)}</tbody></table></div>}</section>
        </>}
    </section>;
}
function ReferralStatusCard({ status }: { status: ReferralStatus }) {
    return (
        <section className="billing-history">
            <h3>Restaurants empfehlen</h3>
            <p>Im Starter-Tarif reduzieren bis zu zwei qualifizierte Empfehlungen deine Monatspauschale um jeweils 10 € netto. Bei 49 € regulär zahlst du mindestens 29 €.</p>
            {status.code ? (
                <label>
                    Dein Empfehlungscode{' '}
                    <input aria-label="Empfehlungscode" value={status.code} readOnly onFocus={event => event.target.select()} />
                </label>
            ) : <p>Die Verwaltung gibt den Empfehlungscode nach Prüfung deines Unternehmens frei.</p>}
            <p>{status.total} geworbene Partner · {status.qualified} qualifiziert für {status.month} · {euro(status.discount_cents)} möglicher Rabatt</p>
            <small>Ein Partner zählt nach zwei bestätigten Monatszahlungen, wenn auch der betrachtete Monat bezahlt ist. Der Stand beim Erstellen des Entwurfs wird gespeichert.</small>
        </section>
    );
}

type Activity={id:number;action:string;metadata:Record<string,unknown>;created_at:string};
const actions:Record<string,string>={'menu.created':'Gericht angelegt','menu.updated':'Gericht geändert','settings.updated':'Lieferparameter geändert','billing.changed':'Tarif zugeordnet','billing.requested':'Tarif angefragt','billing.request_cancelled':'Tarifanfrage zurückgenommen','billing.terms_agreed':'Abrechnungsbedingungen gespeichert','billing.draft_prepared':'Monatsentwurf erstellt'};
export function ActivityLog({slug}:{slug:string}) {
    const [rows,setRows]=useState<Activity[]>([]);const [error,setError]=useState('');const [busy,setBusy]=useState(false);
    async function load(){setBusy(true);setError('');try{setRows(await api<Activity[]>(`/restaurants/${slug}/activities`));}catch(e){setError(e instanceof Error?e.message:'Laden fehlgeschlagen.');}finally{setBusy(false);}}
    return <section className="portal-box"><h2>Änderungsprotokoll</h2><button className="secondary" disabled={busy} onClick={load}>{busy?'Wird geladen …':'Letzte 50 Einträge laden'}</button>{error&&<p role="alert" className="error">{error}</p>}<ul className="activity-list">{rows.map(row=><li key={row.id}><b>{actions[row.action]??row.action}</b><span>{new Date(row.created_at).toLocaleString('de-DE')}{row.metadata.item_id?` · Artikel #${row.metadata.item_id}`:''}</span></li>)}</ul></section>;
}

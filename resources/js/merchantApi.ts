const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
export async function merchantApi<T>(path:string, method='GET', body?:unknown):Promise<T> {
    const response=await fetch('/api/v1/merchant'+path,{method,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf()},body:body===undefined?undefined:JSON.stringify(body)});
    const json=await response.json();
    if(response.status===401) { window.location.assign('/portal/login');throw new Error('Bitte erneut anmelden.'); }
    if(!response.ok) {
        const messages=Object.values(json.errors ?? {}).flat() as string[];
        throw new Error(response.status===419?'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.':messages[0] ?? json.message ?? 'Speichern fehlgeschlagen.');
    }
    return json.data as T;
}

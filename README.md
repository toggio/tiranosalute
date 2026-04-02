# Tirano Salute

Tirano Salute è un’applicazione pensata per il lavoro quotidiano di una piccola struttura sanitaria privata con cinque medici di base. L’applicazione gestisce la prenotazione delle visite, il ciclo operativo degli appuntamenti, la redazione dei referti e una dashboard utile per monitorare tempi, carichi di lavoro e andamento del servizio.

L'impianto resta volutamente essenziale: backend PHP object-oriented senza framework, frontend Vue 3 senza build, database SQLite e documentazione OpenAPI servita direttamente dall'applicazione.

## Scenario e ruoli

I ruoli previsti sono quattro.

- `PATIENT`: ricerca disponibilità, prenota per se stesso, consulta visite e referti di propria competenza.
- `DOCTOR`: gestisce le visite assegnate, aggiorna la disponibilità, avvia e conclude gli appuntamenti con il referto.
- `RECEPTION`: lavora su anagrafiche, disponibilità, prenotazioni e dashboard operative, ma non può leggere i referti.
- `INTEGRATOR`: account tecnico unico di sistema. Mantiene le funzioni operative della reception, accede ai referti, dispone della dashboard statistiche dedicata e gestisce lo staff interno.

## Stack

- Backend: PHP 8 OOP senza framework
- Frontend: Vue 3 + Vue Router vendorizzati localmente
- Database: SQLite
- Documentazione API: OpenAPI + Swagger UI vendorizzata localmente

Timezone applicativa: `Europe/Rome`  
Formato date e orari: `Y-m-d H:i:s`

L'applicazione non usa Composer, build tool frontend o dipendenze aggiuntive. Librerie frontend, font e Swagger UI sono inclusi localmente, così la demo resta avviabile anche senza connessione internet.

## Avvio locale

Requisiti minimi:

- PHP 8.1+
- estensioni `pdo_sqlite`, `sqlite3`, `openssl`, `json`

Inizializzazione del database:

```bash
php scripts/init_db.php --fresh
php scripts/seed.php
```

Avvio con server locale PHP:

```bash
php -S localhost:8000 router.php
```

URL principali:

- applicazione: `http://localhost:8000`
- Swagger UI: `http://localhost:8000/swagger`
- OpenAPI JSON: `http://localhost:8000/openapi.json`

## Database e seed

Il database può essere ricreato da zero in qualunque momento. Il seed genera un dataset dimostrativo ma abbastanza ricco da rendere leggibili le parti più importanti del progetto:

- 5 medici
- circa 300 pazienti demo
- categorie visita
- disponibilità settimanali su turni da circa 10 ore per medico
- sovrapposizioni frequenti tra almeno due medici nella stessa fascia
- visite concluse, annullate e prenotate nel futuro
- storico degli stati coerente
- referti demo cifrati

I dati sono distribuiti in modo che ogni medico abbia profili diversi a seconda della categoria visita, così da far emergere meglio sia la raccomandazione del medico in fase di prenotazione sia le differenze mostrate nelle dashboard statistiche.

La griglia degli slot resta fissata a **15 minuti**. La durata reale di una visita può comunque superare il singolo slot e quel comportamento entra nelle statistiche storiche.

## Credenziali demo

Password comune: `Demo1234!`

- `integrator@tiranosalute.local` (account integrator di sistema)
- `reception@tiranosalute.local`
- `alberto.neri@tiranosalute.local`
- `giulia.rossi@example.com`

## Flusso di prenotazione

La prenotazione segue questo percorso:

1. scelta di categoria visita, motivo e finestra temporale
2. ricerca delle disponibilità
3. selezione di uno slot e del medico da confermare
4. creazione dell'appuntamento con `selected_doctor_id`

### Ricerca disponibilità

`GET /api/availability/search` richiede:

- `from`
- `to`
- `visit_category`

Parametri opzionali:

- `doctor_id`, per cercare solo su un medico specifico
- `patient_id`, disponibile solo per `RECEPTION` e `INTEGRATOR` per escludere conflitti del paziente

Se non viene indicato un medico, il backend non restituisce unicamente una lista di coppie slot-medico. Gli slot vengono aggregati in ordine cronologico e, per ogni orario, la risposta comprende:

- lo slot
- il medico consigliato
- eventuali medici alternativi disponibili nello stesso momento
- il contesto del ranking usato dal backend

Se `doctor_id` è presente, la ricerca resta limitata a quel medico. La conferma finale avviene comunque sempre su un medico esplicito.

La gestione disponibilità della SPA usa fasce ricorrenti settimanali. A livello di modello e API restano già presenti anche i campi opzionali `valid_from` e `valid_to`, pensati per estensioni future come ferie, sostituzioni o disponibilità temporanee, ma non sono ancora esposti nella schermata demo di gestione disponibilità.

### Conferma finale

`POST /api/appointments/book` usa il payload pubblico seguente:

```json
{
  "visit_category": "prima visita",
  "visit_reason": "Dolore lombare",
  "notes": "Da alcuni giorni",
  "slot_start": "2026-03-30 09:00:00",
  "selected_doctor_id": 1
}
```

Vincoli applicativi principali:

- un paziente può prenotare solo per se stesso
- `patient_id` è accettato solo per `RECEPTION` e `INTEGRATOR`
- non è consentita più di una visita attiva per paziente

## Flusso visita e referto

Il ciclo operativo di una visita è lineare:

1. la visita nasce `PRENOTATA`
2. il medico la porta in `IN_CORSO`
3. la conclude e genera il referto

Per esigenze di test e dimostrazione durante la tesi, l'applicazione non blocca tecnicamente l'avvio o la chiusura di una visita futura. In un contesto reale questa regola andrebbe normalmente resa più restrittiva, ma nella demo è lasciata intenzionalmente permissiva per poter provare l'intero flusso operativo senza dipendere dall'orario corrente.

I referti vengono salvati in forma cifrata. `RECEPTION` non può leggerli o decrittarli. `PATIENT`, `DOCTOR` e l'unico account `INTEGRATOR` di sistema accedono solo ai documenti compatibili con il proprio ruolo.

## Algoritmo di assegnazione del medico

La raccomandazione del medico resta uno dei punti centrali del progetto. Il criterio combina due aspetti:

- efficienza storica
- carico operativo

Per ogni medico candidato nello stesso slot vengono considerate quattro metriche:

- `estimated_duration_minutes`
- `estimated_delay_minutes`
- `daily_load`
- `weekly_load`

Durata e ritardo sono calcolati sulla categoria visita richiesta. Se lo storico del medico è limitato, le sue medie vengono smussate verso la media globale della categoria con una media pesata semplice.

```text
estimated_duration = ((doctor_avg_duration * samples) + (global_avg_duration * 5)) / (samples + 5)
estimated_delay    = ((doctor_avg_delay * samples) + (global_avg_delay * 5)) / (samples + 5)
```

In assenza di storico globale, i fallback restano:

- durata: 15 minuti
- ritardo: 0 minuti

Le metriche vengono poi normalizzate tra i medici candidati dello stesso slot e combinate con questi pesi:

```text
score =
  duration_norm * 0.50 +
  delay_norm    * 0.25 +
  daily_norm    * 0.20 +
  weekly_norm   * 0.05
```

Il punteggio minore identifica il medico consigliato. Gli altri medici disponibili vengono mantenuti come alternative nello stesso slot.

La dashboard statistiche usa anche un `performance_score`, ma si tratta di un indicatore separato e non coincide con lo score operativo di assegnazione.

## Dashboard e indicatori

Le dashboard operative mostrano andamento delle visite per ruolo.

L'endpoint statistiche `GET /api/stats` è disponibile solo per l'`INTEGRATOR`.

La dashboard statistiche include:

- durata media visita
- ritardo medio
- volumi di visite concluse per medico e categoria
- annullate conteggiate separatamente
- performance score comparativo tra medici

## Regole operative rilevanti

### Autoannullamento

Una visita ancora `PRENOTATA` viene annullata automaticamente quando sono trascorse 12 ore da `scheduled_start`. Il motivo registrato è `scaduta`.

La logica viene richiamata in punti applicativi sensati, senza dipendere da un cron obbligatorio. Nello storico la transizione viene marcata come azione automatica di sistema, non attribuita all'utente che aveva creato originariamente la prenotazione.

### Annullamento da parte del paziente

Il paziente può annullare solo le proprie visite ancora `PRENOTATA` e solo entro la mezzanotte del giorno precedente alla visita.

### Cambio password obbligatorio

Quando reception o integrator reimpostano la password di un utente, il sistema attiva `must_change_password`.

Al login successivo:

- l'accesso è consentito
- l'utente resta limitato a profilo, cambio password e logout
- dopo il cambio password, `must_change_password` torna a `false`

## Sicurezza

Sono previste due modalità di autenticazione.

### Sessione web + CSRF

- `POST /api/login`
- cookie HttpOnly di sessione
- token CSRF separato
- nella configurazione demo i cookie sono `ts_auth` e `ts_csrf`
- header `X-CSRF-Token` obbligatorio per le write browser-based

### Bearer token

- `POST /api/login/bearer`
- disponibile per qualunque utente attivo con credenziali valide
- header `Authorization: Bearer <token>`
- durata predefinita: 24 ore
- `POST /api/logout` revoca il bearer token corrente se la richiesta è autenticata via token
- nessun controllo CSRF per questa modalità

Altri vincoli di sicurezza:

- RBAC distinto per ruolo
- nessuna prenotazione per conto di altri pazienti da parte di un `PATIENT`
- referti esclusi per `RECEPTION`
- envelope encryption sui referti

## Gestione Staff

Lo staff gestibile dall'applicazione è solo di tipo `RECEPTION`.

L'account `INTEGRATOR` resta unico e di sistema, ma non fa parte dello staff gestibile:

- non viene creato dalla UI o dalle API di staff
- non compare nell'elenco staff
- non viene aggiornato tramite le API di staff
- non viene usato come ruolo di promozione per altri utenti

## API e Swagger

La specifica si trova in `docs/openapi.template.json` ed è servita a runtime come `GET /openapi.json`.

Swagger riflette il comportamento reale dell'applicazione:

- ricerca disponibilità con slot aggregati
- medico consigliato e alternative
- conferma finale con `selected_doctor_id`
- reset password amministrativo con `must_change_password`
- statistiche per medico e categoria calcolate sulle visite concluse, con annullate separate
- disponibilità settimanali ricorrenti, con supporto API già predisposto per `valid_from` e `valid_to`
- `GET /api/reports` con `tax_code` obbligatorio per `INTEGRATOR`
- `GET /api/stats` riservato a `INTEGRATOR`
- timestamp applicativi nel formato `Y-m-d H:i:s`
- supporto a sessione web con CSRF e bearer token

## Deploy in sottocartella

SPA, API e Swagger funzionano anche quando l'applicazione viene pubblicata in sottocartella.

Esempio:

- app: `https://host/tiranosalute/`
- API: `https://host/tiranosalute/api/...`
- Swagger: `https://host/tiranosalute/swagger`

Il base path viene rilevato a runtime e applicato sia alle route sia alla spec OpenAPI.

## Note Licenze

Per librerie e font vendorizzati nel repository, consulta `THIRD_PARTY_NOTICES.md`.

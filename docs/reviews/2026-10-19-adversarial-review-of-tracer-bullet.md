## 1. Executive summary

- **Valutazione complessiva della qualità delle specifiche:** Il documento `2026-10-17-tracer-bullet-design.md` presenta un'architettura sobria, disciplinata e ben allineata al dominio del "Plain Web" su hosting condiviso. La scelta del rendering unificato tramite frammenti HTML + timeline JSON, l'isolamento dei moduli tramite Context API e la difesa stratificata per i file dati dimostrano una solida comprensione del contesto operativo. Tuttavia, una revisione avversariale rivela **contraddizioni strutturali e difetti di interazione** che compromettono il funzionamento stesso del *tracer bullet*.

- **Principali rischi individuati:**
  
   1. **Rottura del flusso di setup su hosting non-Apache (Bloccante):** L'interazione tra l'algoritmo di selezione del backend di storage e il posizionamento in-webroot su server che ignorano `.htaccess` (es. Nginx, IIS, LiteSpeed non compatibili) porta al blocco totale dell'installazione (`Fail`), contraddicendo il requisito di fallback automatico a flat-file.
  
   2. **Violazione dell'invariante di immutabilità della Status Timeline (Alta):** L'uso di un `ETag` basato sull'ora UTC (`current UTC hour`) combinato con il calcolo dell'orizzonte a partire da `now` permette a client e CDN di servire per quasi 60 minuti una timeline i cui primi segmenti contengono diciture relative al giorno precedente ("oggi" / "domani") dopo il superamento della mezzanotte locale.
  
   3. **Corruzione/Perdita silenziosa di stato nel backend Flat-File (Alta):** L'implementazione di `transaction(callable)` sopra file separati per collection (con lock e `rename` per-collection) non definisce né garantisce atomicità o rollback multi-collection, né previene deadlock in caso di transazioni che toccano più collection (`schedules` ed `exceptions`).
  
   4. **Denial of Service (DoS) globale sull'autenticazione (Alta):** Il rate limit globale di 30 tentativi/minuto rallenta le richieste anziché rifiutarle in modo stateless o isolato; se implementato con `sleep()` in PHP o senza separazione tra endpoint Developer e Client, un attaccante può saturare i worker PHP dell'hosting condiviso o bloccare l'accesso legittimo con 1 richiesta ogni 2 secondi.
  
   5. **Inconsistenza di cache e lingua sui siti multilingua (Media):** La rimozione automatica dell'attributo `lang` nello snippet generato quando coincide con la lingua di default rompe gli embed esistenti se il Developer cambia successivamente la lingua di default dell'installazione.

- **Condizioni che impediscono di procedere:** Le criticità **R-001**, **R-002**, **R-003** e **R-004** devono essere risolte a livello di specifica prima di scrivere il codice del core (`Innesto\Roots\Store`, `Http\ViewController`, `Setup\SelfCheck`), poiché alterano i contratti base e i test end-to-end.

- **Aree del documento che richiedono maggiore attenzione:** §3.2 (Data directory and Stores), §3.3 (Store API), §3.6 (Authentication and sessions), §3.9 (HTTP behaviour) e §4.3 (Status timeline).

- **Decisione consigliata:** **Revisionare prima di procedere.** Le specifiche sono ottime nell'impianto generale, ma contengono 4 difetti bloccanti (P0) direttamente dimostrabili dal testo che causerebbero il fallimento dei test di accettazione o vulnerabilità architetturali nel core.

## 2. Comprensione del sistema

- **Obiettivi:** Realizzare il primo *tracer bullet* end-to-end della suite open-source **Innesto**, coprendo il caricamento via FTP del core (**Innesto Roots**) e del modulo **Innesto Orario** (`in.hours`), il setup iniziale, l'attivazione del modulo, la configurazione degli orari, l'embedding in un sito statico/PHP/htmx e la modifica degli orari da parte del cliente finale tramite PWA mobile.

- **Attori:**
  
   - **Developer:** Installa la suite via FTP, esegue il setup tramite codice monouso, gestisce la configurazione condivisa e le impostazioni di Roots, attiva/disattiva moduli, crea/elimina `Schedule`, genera il PIN per il Cliente e copia gli snippet di embed. Accede a `dev/` (sessione di 12 ore).
  
   - **Client:** Utente non tecnico (es. commerciante). Accede alla PWA su `app/` tramite PIN a 8 cifre (sessione di 90 giorni), visualizza lo stato e modifica orari settimanali, stagioni ed eccezioni (es. "Chiuso oggi").
  
   - **Visitor:** Utente pubblico non autenticato che visita il Sito ospite; il suo browser interroga `view.php` e `asset.php` senza mai inviare o ricevere cookie.

- **Funzionalità principali:**
  
   - Bootstrap e Self-check dell'ambiente con probe HTTP eseguite dal browser del Developer.
  
   - Astrazione dello storage (`Store API` documentale) con doppio backend trasparente: SQLite (≥ 3.27) o flat-file JSON protetti da prefisso PHP `<?php exit; ?>`.
  
   - Ciclo di vita dei moduli basato su Manifest (`module.php`), isolamento del contesto (`Context object`) e *fault fence* per intercettare eccezioni senza abbattere il sistema.
  
   - Motore di rendering unificato (`view.php` e `innesto_render()`) che espone 4 superfici (Web Component light-DOM, PHP include, htmx/Unpoly, JSON versionato) e 3 viste per Orario (`week`, `status`, `jsonld`).
  
   - Calcolo deterministico di una `Status timeline` su un orizzonte di 14 giorni, priva di testo relativo al tempo nell'HTML server-side e potenziata lato client da un Web Component (`<in-hours>`).

- **Architettura e dati:** Applicazione PHP ≥ 8.3 senza dipendenze runtime esterne (zero Composer/npm in produzione, zero `intl`/`mbstring`). Dati salvati fuori dalla webroot (se scrivibile) o in `<roots>/data-<random>/`, il cui percorso è salvato in `<roots>/data-path.php`. Log strutturato append-only con rotazione a 1 MB (2 generazioni).

- **Vincoli e assunzioni:** Hosting condiviso con solo accesso FTP; HTTPS obbligatorio (eccetto override per `localhost`); un'installazione serve un singolo Sito e un singolo business; nessun sistema di migrazione automatica dello schema o aggiornamento in-place nello scope attuale.

- **Aspetti non ricostruibili con certezza dal documento:**
  
   - Non abbiamo visibilità sul contenuto dei file esterni referenziati (`GLOSSARY.md`, `AGENTS.md`, `ADR-0001`, `ADR-0002` e ticket `#1`–`#24`) oltre a quanto riassunto nel documento stesso.
  
   - Il meccanismo esatto con cui il backend flat-file implementa il rollback di `transaction(callable)` su più collection non è specificato.
  
   - Il formato esatto di serializzazione dei file di collection nel backend flat-file (un unico file JSON per collection vs un file per documento) è implicito ("read-modify-write under `flock` on a per-collection lock file, then writes a temp file and atomically `rename`s it" suggerisce un file per collection, ma non è dichiarato formalmente).

## 3. Registro delle criticità

| **ID**    | **Area**                                      | **Criticità**                                                                                                                                                                                            | **Gravità** | **Priorità** | **Confidenza** |
| --------- | --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------- | ------------ | -------------- |
| **R-001** | Architettura / Setup (§3.2, §3.11)            | Contraddizione mortale nel probe HTTP della Data directory su host in-webroot che ignorano `.htaccess`: il setup blocca l'installazione (`Fail`) invece di fare fallback a flat-file.                    | Critica     | P0           | Alta           |
| **R-002** | Modello Dati / Persistenza (§3.2, §3.3)       | Assenza di atomicità, isolamento e rollback reale per `transaction(callable)` nel backend flat-file su operazioni multi-collection e rischio di deadlock.                                                | Critica     | P0           | Alta           |
| **R-003** | Flussi / Caching / Orario (§3.9, §4.3)        | Violazione dell'invariante della Status Timeline a cavallo della mezzanotte locale a causa del disallineamento tra `ETag` orario UTC, `max-age` e segmenti già scaduti.                                  | Alta        | P0           | Alta           |
| **R-004** | Sicurezza / Disponibilità (§3.6, §8)          | Il throttling con ritardo crescente fino a 60s e cap globale di 30 tentativi/minuto espone a esaurimento dei worker PHP (DoS) e blocco incrociato Client/Developer.                                      | Alta        | P0           | Alta           |
| **R-005** | Dominio Orario (§4.1, §4.3)                   | Regola di precedenza e di fusione (merge) incoerente o non definita quando una `Exception` di tipo `Special hours` del giorno $D$ sfora oltre la mezzanotte nel giorno $D+1$.                            | Alta        | P1           | Alta           |
| **R-006** | Sicurezza / Setup (§2, §3.1, §3.2)            | Scrittura di `<roots>/data-path.php` nella directory applicativa e condizione di race/lockout al primo accesso non autenticato.                                                                          | Alta        | P1           | Alta           |
| **R-007** | Dominio Orario / Client UI (§4.1, §4.7)       | Corruzione semantica e crash logico nell'azione "We're open again" (`end = yesterday`) quando la chiusura a tempo indeterminato è iniziata oggi o nel futuro.                                            | Alta        | P1           | Alta           |
| **R-008** | Modello Dati / Store API (§3.3, §4.1, §4.2)   | Impossibilità di interrogare o filtrare correttamente le eccezioni tramite `Store::list()` per presenza di `end = null` e formati data eterogenei (`YYYY-MM-DD` vs `MM-DD`).                             | Alta        | P1           | Alta           |
| **R-009** | API / Integrazioni (§3.8, §3.10)              | Omissione del parametro `lang` nello snippet generato per la lingua di default: il cambio successivo del Default locale rompe silenziosamente la lingua delle pagine già pubblicate.                     | Media       | P1           | Alta           |
| **R-010** | Sicurezza / Client PWA (§3.6, §3.13)          | Persistenza in chiaro dei dati del form in `sessionStorage` non svuotata in caso di chiusura tab o timeout prolungato su dispositivi condivisi.                                                          | Media       | P2           | Alta           |
| **R-011** | Modello Dati / Concorrenza (§3.2, §3.4, §3.6) | Degrado delle prestazioni e contesa di lock su Roots Store (specialmente su flat-file) poiché ogni singola richiesta autenticata o tentativo di login fallito esegue una scrittura nello Store centrale. | Media       | P1           | Media          |
| **R-012** | Dominio Orario / SEO (§4.4)                   | Generazione di `jsonld` non valido o semanticamente corrotto per le Stagioni/Eccezioni che attraversano il capodanno o senza fine, e assenza di pruning coerente.                                        | Media       | P2           | Alta           |
| **R-013** | Osservabilità / Log (§3.12)                   | Condizione di race nella rotazione del file di log (`Roots log`) a 1 MB senza lock esplicito, con rischio di perdita di eventi di sicurezza o corruzione delle righe JSON.                               | Media       | P2           | Media          |
| **R-014** | Architettura / Web Component (§3.8, §3.10)    | Mancata sincronizzazione tra i parametri accettati dal loader JS e le opzioni del Manifest al cambio versione, e vulnerabilità a DOM clobbering / attributi conflittuali nello swap `outerHTML`.         | Bassa       | P2           | Media          |

## 4. Analisi dettagliata delle criticità

### R-001 — Contraddizione mortale nel probe HTTP della Data directory su host che ignorano `.htaccess`

- **Riferimento:** §3.1 (Files on the server), §3.2 (Data directory and Stores), §3.11 (Self-check).

- **Problema:** Esiste una contraddizione diretta tra tre regole delle specifiche:
  
   1. §3.1 e §3.11 stabiliscono che il mancato supporto a `.htaccess` e il posizionamento della Data directory dentro la webroot (`<roots>/data-<random>/`) sono condizioni tollerate, classificate come semplice **Warn** nel Self-check, proprio perché alcuni hosting condivisi ignorano `.htaccess` e non offrono directory scrivibili fuori dalla webroot.
  
   2. §3.2 stabilisce che il backend **SQLite** viene scelto solo se *"a non-`.php` probe file in the Data directory proved unreachable over HTTP"*, altrimenti si usano i **Guarded flat files** (`<?php exit; ?>` seguito da JSON), che sono sicuri anche se richiesti via HTTP perché l'interprete PHP esegue `exit`.
  
   3. Tuttavia, §3.2 ("HTTP proof") e §3.11 ("Grade: Fail") affermano: *"setup refuses to finish if a probe file in the Data directory returns any content over HTTP"* e classificano *"Data directory unwritable or reachable over HTTP"* come **Fail (blocks setup)**.

- **Evidenze:**
  
   - §3.2: *"SQLite is used when `pdo_sqlite` is present, SQLite is 3.27 or later, and a non-`.php` probe file in the Data directory proved unreachable over HTTP. Guarded flat files (`<?php exit; ?>` followed by JSON) are used otherwise."*
  
   - §3.2: *"HTTP proof: setup refuses to finish if a probe file in the Data directory returns any content over HTTP."*
  
   - §3.11: *"Fail (blocks setup): PHP < 8.3; Data directory unwritable or reachable over HTTP; no HTTPS"* vs *"Warn: Data directory inside the webroot · `.htaccess` not honoured"*.

- **Scenario di fallimento:**
  
   1. Il Developer carica `innesto/` su un hosting condiviso basato su Nginx o LiteSpeed dove `.htaccess` viene ignorato e la directory superiore `../` non è scrivibile.
  
   2. Al primo avvio, Roots crea `<roots>/data-<random>/` dentro la webroot.
  
   3. Il Self-check esegue le probe HTTP dal browser del Developer.
  
   4. Se il probe file è un file non-`.php` (come richiesto da §3.2 per testare SQLite), il server web lo serve direttamente con HTTP 200 e contenuto visibile, poiché `.htaccess` è ignorato.
  
   5. Invece di degradare a **Warn** e selezionare il backend **Guarded flat files** (il cui intero scopo architetturale è proteggere i dati tramite `<?php exit; ?>` quando la cartella è raggiungibile via HTTP!), la regola di §3.2/§3.11 scatta in **Fail** e blocca permanentemente il setup.
  
   6. Viceversa, se il probe file per il blocco del setup fosse un file `.php` con `<?php exit; ?>`, esso restituirebbe sempre un body vuoto (`200 OK` con 0 byte) su qualsiasi host dotato di PHP, ma non rileverebbe se i file `.sqlite` sono scaricabili via HTTP a meno di fare *due* probe distinte con regole di blocco diverse.

- **Causa:** Fusione impropria nel documento tra due probe con scopi diversi: (A) la probe su file *non-PHP* (es. `probe.txt`) che serve a decidere se SQLite è sicuro o se occorre usare i flat-file protetti da PHP; (B) la probe su file *PHP* protetto (es. `probe.php` contenente `<?php exit; ?>` + payload di test) che serve a verificare che il server esegua effettivamente PHP nella Data directory e non serva i sorgenti `.php` in chiaro.

- **Conseguenze:** Impossibilità totale di installare Innesto su qualsiasi hosting in cui la Data directory ricada nella webroot e `.htaccess` non sia supportato, rendendo inutile l'intero meccanismo di fallback ai Guarded flat files e il prefisso `<?php exit; ?>`.

- **Gravità / Priorità / Confidenza:** **Critica / P0 / Alta**.

- **Mitigazione proposta:** Distinguere esplicitamente le due probe HTTP in §3.2 e §3.11:
  
   1. **Probe PHP (`probe.php` con `<?php exit; ?>SECRET`):** Se restituisce contenuto via HTTP (il server non interpreta i `.php` nella Data directory e li serve come testo), il grado è **Fail (blocks setup)**.
  
   2. **Probe non-PHP (`probe.dat` con testo casuale):** Se restituisce contenuto via HTTP (perché la cartella è nella webroot e `.htaccess` è ignorato), il grado è **Warn**, SQLite viene disabilitato forzatamente e Roots seleziona obbligatoriamente **Guarded flat files** (dove ogni file di Store, log e setup code è un file `.php` protetto da `<?php exit; ?>`).

- **Verifica:** Test E2E in container con `.htaccess` disabilitato (`AllowOverride None`) e permessi di scrittura solo dentro `<roots>/`: il setup deve completarsi con stato **Warn**, selezionare i flat-file e verificare che nessun file `.php` nella cartella `data-<random>/` emetta alcun byte via HTTP.

- **Decisione richiesta:** Confermare che su host in-webroot senza `.htaccess` tutti i file creati nella Data directory (Stores, `roots-log.php`, `setup-code.php`, `reset.php`) usino l'estensione `.php` con guard iniziale `<?php exit; ?>`.

### R-002 — Assenza di atomicità, isolamento e rollback per `transaction(callable)` nel backend Flat-File e rischio di deadlock

- **Riferimento:** §3.2 (Data directory and Stores), §3.3 (Store API), §3.7 (Lifecycle), §4.1 (Lifecycle), §4.2 (Store).

- **Problema:** §3.3 definisce `transaction(callable)` come parte dell'API dello Store e afferma che *"One contract test suite proves both backends behave identically"*, inclusi *"transactions (commit and rollback), Store revision bumps"* (§7.1). Inoltre:
  
   - §3.7 richiede che `onActivate(context)` giri in una transazione e *"If anything throws, everything rolls back"*.
  
   - §4.1 richiede che la pulizia delle eccezioni scadute da 30 giorni avvenga *"inside the transaction of the next save to that Schedule"*.
  
   - §4.2 richiede che *"Deleting a Schedule deletes its Exceptions in the same transaction"*.
     Tuttavia, §3.2 specifica per il backend su file: *"the file backend does read-modify-write under `flock` on a per-collection lock file, then writes a temp file and atomically `rename`s it"*.
     Se i lock e i file sono **per-collection** (es. un file per `schedules` e un file per `exceptions`, più un posto dove salvare la `Store revision`), è impossibile eseguire un commit atomico o un rollback su più collection con un semplice `rename` per-collection senza un coordinatore di transazione o senza unificare lo Store in un singolo file per Modulo.

- **Evidenze:**
  
   - §3.2: *"the file backend does read-modify-write under `flock` on a per-collection lock file, then writes a temp file and atomically `rename`s it."*
  
   - §3.3: *"Operations: `get`, `put` (create or replace), `delete`, `list` (...), and `transaction(callable)`. Nothing else, so both backends serve the whole API."*
  
   - §3.3: *"Store revision: a counter in each Store, bumped by every committed write transaction."*

- **Scenario di fallimento:**
  
   - *Scenario A (Rollback parziale / Corruzione):* Dentro `transaction()`, il codice modifica un documento in `schedules`, poi tenta di cancellare o modificare documenti in `exceptions` (es. cancellazione di una Schedule in §4.2 o salvataggio con pulizia in §4.1). Se le scritture avvengono immediatamente su ciascuna collection o se il primo `rename` (`schedules`) ha successo ma il secondo (`exceptions`) fallisce (o la callable lancia un'eccezione dopo la prima `put`), `schedules` resta modificato mentre `exceptions` no.
  
   - *Scenario B (Deadlock):* Il processo 1 acquisisce il lock sulla collection A e poi richiede la collection B dentro una transazione; il processo 2 acquisisce il lock su B e poi su A. Poiché `flock` è bloccante, entrambi i worker PHP vanno in deadlock fino a `max_execution_time`.
  
   - *Scenario C (Revision bump non atomico):* La `Store revision` è globale per l'intero Store del Modulo (§3.3). Con lock per-collection, due transazioni concorrenti su collection diverse dello stesso Store possono sovrascrivere o incrementare in modo non sincronizzato la `Store revision`.

- **Causa:** Scelta di un'astrazione di locking a livello di *singola collection* anziché a livello di *Store* per il backend flat-file, combinata con l'assenza di buffering in-memory della transazione.

- **Conseguenze:** Fallimento della suite di test di contratto (§7.1) sul rollback multi-collection, corruzione dei dati su hosting privi di SQLite e potenziali letture "sporche" (dirty reads) di transazioni parzialmente applicate da parte di `view.php`.

- **Gravità / Priorità / Confidenza:** **Critica / P0 / Alta**.

- **Mitigazione proposta:**
  
   - **Soluzione architetturale minima e più robusta:** Poiché Innesto si rivolge a siti piccoli/medi e i volumi di dati di un Modulo sono minimi (pochi KB per Schedule, Exceptions, Config), nel backend flat-file **un intero Store deve risiedere in un singolo file PHP/JSON** (es. `store-in.hours.php` contenente `{"schema": 1, "revision": 42, "collections": {"schedules": {...}, "exceptions": {...}}}`) protetto da **un unico lock file per Store** (`store-in.hours.lock`).
  
   - Durante `transaction(callable)`, il backend flat-file acquisisce `LOCK_EX` sullo Store, carica lo stato in memoria, esegue tutte le `put`/`delete` sul buffer in memoria, incrementa `revision` di 1 e, solo se la `callable` termina senza eccezioni, serializza l'intero Store su un file temporaneo ed esegue un singolo `rename()` atomico. Se la `callable` lancia un'eccezione, scarta semplicemente il buffer in memoria e rilascia il lock (rollback perfetto a costo zero).

- **Verifica:** Test in §7.1: una `transaction()` che esegue `put` sulla collection A, `delete` sulla collection B e poi lancia un'`Exception` deve lasciare intatti su disco sia A, sia B, sia la `Store revision`, su entrambi i backend.

- **Decisione richiesta:** Accettare l'unificazione del backend flat-file in un singolo file (e singolo lock) per Store anziché per collection.

### R-003 — Violazione dell'invariante della Status Timeline a cavallo della mezzanotte locale a causa del caching e dell'ETag orario UTC

- **Riferimento:** §3.8 (Rendering), §3.9 (HTTP behaviour), §4.3 (Status timeline).

- **Problema:** §4.3 stabilisce che la Status timeline viene spezzata a ogni mezzanotte locale affinché la dicitura di un segmento (che contiene parole relative al giorno come *"Closed · reopens tomorrow at 9:00"* dove *"Today and tomorrow mean the Schedule's local day"*) non cambi mai mentre quel segmento è attivo.
  Tuttavia, questa affermazione è falsa non appena si considera la cache HTTP (`max-age=N` fino a 60 minuti e `ETag` basato su `current UTC hour` in §3.9) o una pagina già aperta prima della mezzanotte:
  
   1. **Bug nei segmenti successivi al primo dentro la stessa timeline:** Al punto 3 e 4 di §4.3, PHP genera segmenti per `[now, now + 14 days)` spezzandoli a ogni mezzanotte locale e assegna a *ciascun* segmento la sua frase localizzata in PHP. Ma se alle `23:50` di Lunedì (giorno locale) il negozio è chiuso e riapre Martedì alle `09:00`, l'intervallo di chiusura viene spezzato a mezzanotte in due segmenti:
      
       - Segmento 1 (`23:50` – `24:00` di Lunedì): per chi guarda l'orologio tra le 23:50 e le 24:00, Martedì è **"domani"** (*"Chiuso · riapre domani alle 09:00"*).
      
       - Segmento 2 (`00:00` – `09:00` di Martedì): quando l'orologio del visitatore entra nel Segmento 2 (es. alle `00:05` di Martedì), il giorno locale corrente di quello specifico segmento è **Martedì**! Come viene calcolata in PHP la frase del Segmento 2? Se viene calcolata rispetto a `now` del server al momento del render (Lunedì 23:50), dirà ancora *"riapre domani alle 09:00"* (errato alle 00:05 di Martedì!). Se invece viene calcolata rispetto al giorno locale del `from` di ciascun segmento (`00:00` di Martedì), la specifica non lo dichiara in §4.3 (dice solo *"Today and tomorrow mean the Schedule's local day"*, al singolare, come se esistesse un solo "oggi" per l'intera chiamata di render).
  
   2. **Bug nella vista `week` e nelle timezone con offset non interi rispetto a UTC:** Nella vista `week` (§4.4), l'HTML renderizzato mostra *"the hours in effect on the render date"* e le eccezioni dell'orizzonte. L'`ETag` cambia al cambio dell'**ora UTC** (`current UTC hour`, §3.9), ma le timezone mondiali hanno anche offset di `:30` e `:45` minuti (es. `Asia/Kolkata` UTC+5:30, `Australia/Eucla` UTC+8:45, `America/St_Johns` UTC-3:30) e comunque `Cache-Control: public, max-age=N` (fino a 60 minuti) impedisce al browser di rivalidare l'`ETag` fino alla scadenza di `N`.

- **Evidenze:**
  
   - §3.9: *"`200`: `Cache-Control: public, max-age=N`... `ETag`: a hash of ... the current UTC hour"*.
  
   - §4.3: *"3. Over the horizon `[now, now + 14 days)`, emit alternating open and closed segments with absolute UTC `from` and `to`, split at each local midnight so that a segment's wording never changes while it lasts. 4. Give each segment its sentence, localised in PHP... 'Today' and 'tomorrow' mean the Schedule's local day."*.

- **Scenario di fallimento:**
  
   1. Alle `23:55` (ora locale di Roma), un visitatore carica la pagina; `view.php` genera la timeline e imposta `Cache-Control: public, max-age=1800` (30 min).
  
   2. Se il generatore PHP valuta "today" e "tomorrow" usando la data locale di `now` (il momento del render, cioè Lunedì) anziché la data locale dell'istante `segment.from` di ciascun segmento, alle `00:05` di Martedì (sia per l'utente rimasto sulla pagina, sia per qualsiasi nuovo visitatore che riceve la risposta dalla cache HTTP) lo stato dirà *"Chiuso · riapre domani alle 09:00"*, inducendo il cliente a credere che il negozio sia chiuso per tutto il Martedì e riapra Mercoledì.
  
   3. Anche se il generatore valuta correttamente "today/tomorrow" rispetto a `segment.from`, nella vista `week` (§4.4) la tabella HTML mostra *"the hours in effect on the render date"*: se a mezzanotte inizia una nuova `Season`, per `max-age` minuti (o per 30 minuti nelle timezone UTC+X:30 dove la mezzanotte locale cade a metà dell'ora UTC) la tabella `week` mostrerà gli orari della vecchia stagione mentre la `Status` line mostrerà gli orari della nuova stagione.

- **Causa:** Ambiguità nella definizione della data di riferimento per il calcolo delle stringhe relative di ciascun segmento in §4.3, e uso dell'ora UTC anziché della data/ora nella timezone della Schedule per l'`ETag` in §3.9.

- **Conseguenze:** Informazioni di apertura/chiusura gravemente fuorvianti ("riapre domani" quando in realtà apre tra poche ore nello stesso giorno) proprio nelle ore serali/notturne in cui gli utenti consultano gli orari per il giorno successivo.

- **Gravità / Priorità / Confidenza:** **Alta / P0 / Alta**.

- **Mitigazione proposta:**
  
   1. Specificare in §4.3 punto 4 che, quando PHP genera la frase per un segmento $[t_{\text{from}}, t_{\text{to}})$, **"today" e "tomorrow" sono valutati rispetto alla data locale di $t_{\text{from}}$ nella timezone della Schedule** (e non rispetto a `now` del render). In questo modo, lo split a mezzanotte locale garantisce matematicamente che il segmento `23:50–00:00` usi Lunedì come "oggi" (quindi Martedì = "domani") e il segmento `00:00–09:00` usi Martedì come "oggi" (quindi *"Chiuso · apre alle 09:00"*), rendendo la timeline 100% immune alla cache HTTP e alla permanenza sulla pagina!
  
   2. In §3.9, sostituire nell'`ETag` "the current UTC hour" con la data locale della Schedule + il bucket orario (o quarto d'ora UTC), oppure includere la data locale della Schedule nel calcolo prima del render.

- **Verifica:** Test unitario in §7.2 (Status timeline at a fixed clock): fissato `now` alle `23:30` di Lunedì per un negozio che apre Martedì alle `09:00`, verificare che la timeline emessa in una singola chiamata contenga un segmento pre-mezzanotte con testo *"Closed · reopens tomorrow at 9:00"* e un segmento post-mezzanotte (`00:00`–`09:00` di Martedì) con testo *"Closed · opens at 9:00"*.

- **Decisione richiesta:** Nessuna decisione di business necessaria; è una correzione di specifica indispensabile per mantenere la promessa architetturale di §4.3.

### R-004 — Vulnerabilità di Denial of Service (DoS) globale sull'autenticazione e contesa del lock dello Store

- **Riferimento:** §3.4 (Roots' own Store), §3.6 (Authentication and sessions), §3.12 (Roots log), §8 (Defaults this spec sets).

- **Problema:** §3.6 e §8 definiscono il meccanismo di throttling per l'autenticazione (Developer password, Setup code, Client PIN):
  
   - *"Throttling starts at a 1 s delay per IP after 5 consecutive failures, doubling per failure up to 60 s, with a global cap of 30 sign-in attempts per minute that slows sign-ins without ever locking"*.
  
   - *"Roots' own Store: It holds ... short-lived throttling state"* (§3.4).
     Questa progettazione presenta tre gravi vulnerabilità in un ambiente di hosting condiviso:
1. **Worker Starvation (DoS da `sleep()`):** La dicitura *"1 s delay ... up to 60 s ... that slows sign-ins without ever locking"* implica che il server rallenti la risposta (o imponga un'attesa). Se l'implementazione esegue `sleep()` / `usleep()` lato PHP (specialmente fino a 60 secondi, quando peraltro §3.11 ammette `max_execution_time` di 30 secondi!), bastano 10 richieste concorrenti da un singolo IP per saturare tutti i worker PHP-FPM/Apache dell'account di hosting condiviso, mandando offline non solo il login ma anche `view.php` per tutti i visitatori del sito.

2. **Global Rate Cap condiviso e banale da saturare (0,5 req/sec):** Un tetto globale di 30 tentativi al minuto su tutta l'installazione significa che chiunque su Internet invii 1 richiesta POST ogni 2 secondi a `/innesto/app/` attiva il rallentamento globale per il Cliente legittimo e per il Developer (se il cap è unico).

3. **Scrittura nello Store centrale a ogni tentativo fallito:** Salvare lo stato di throttling per-IP dentro `Roots' own Store` (§3.4) significa che un attacco di brute-force (es. 50 req/sec da IP multipli o dallo stesso IP) acquisisce continuamente il lock esclusivo in scrittura sul database/file di Roots, bloccando le letture di `view.php` (che deve leggere `Roots' Store` per la `Shared config` e la `Store revision`!) e facendo crescere indefinitamente il file dello Store con migliaia di IP.
- **Evidenze:**
  
   - §3.4: *"Roots' own Store: It holds Shared config, Roots settings ... short-lived throttling state"*.
  
   - §3.6: *"Throttling: a growing delay per IP after a few failures, plus a global rate cap. It never locks the account."*
  
   - §3.13: *"Throttling shows 'wait N s and try again, your account is never locked'."*
  
   - §8: *"Throttling starts at a 1 s delay per IP after 5 consecutive failures, doubling per failure up to 60 s, with a global cap of 30 sign-in attempts per minute that slows sign-ins without ever locking"*.

- **Scenario di fallimento:**
  
   1. Un bot automatizzato invia 2 richieste al secondo a `app/index.php` con PIN casuali.
  
   2. Il cap globale di 30 tentativi/minuto viene superato permanentemente.
  
   3. Il Cliente legittimo scansiona il QR code al bancone del negozio, inserisce il PIN corretto a 8 cifre e si vede rifiutare/rallentare il login perché il cap globale è saturo.
  
   4. Nel frattempo, ogni richiesta del bot scrive nello Store di Roots (incrementando o modificando lo stato) e scrive nel `Roots log`, causando contesa di lock con `view.php` e ruotando l'intero log di sicurezza (2 MB totali su 2 generazioni, §3.12) in meno di 20 minuti, cancellando le tracce precedenti.

- **Causa:** Mancata distinzione tra *rifiuto immediato HTTP 429 con timestamp `retry_after`* e *rallentamento sincrono del processo*, unita alla collocazione dello stato di throttling effimero dentro lo stesso Store che contiene la configurazione letta da tutte le richieste pubbliche.

- **Conseguenze:** Negazione del servizio (DoS) per il Cliente/Developer, potenziale contesa di I/O su `view.php` e riempimento/sovrascrittura rapida dei log di audit.

- **Gravità / Priorità / Confidenza:** **Alta / P0 / Alta**.

- **Mitigazione proposta:**
  
   1. **Zero `sleep()` in PHP:** Specificare esplicitamente che il throttling **non deve mai chiamare `sleep()`** nel processo PHP. Deve memorizzare un timestamp `not_before` per l'IP/bucket e, se una richiesta arriva prima di `not_before`, rispondere **immediatamente** (in < 5 ms) con HTTP `429 Too Many Requests`, header `Retry-After: <N>` e il messaggio UI *"wait N s and try again"* (senza nemmeno eseguire `password_verify()`, che è CPU-intensive).
  
   2. **Separazione dello stato di throttling dallo Store di Roots:** Salvare lo stato di throttling in un file/tabella separata (es. `throttle.php` o tabella dedicata non inclusa nella `Store revision` di Roots), con numero massimo di record IP (es. max 1.000 IP con eviction LRU/scadenza) per evitare esaurimento disco/memoria e per non invalidare mai la `Store revision` di Roots (che fa parte dell'`ETag` di `view.php`!).
  
   3. **Isolamento dei bucket:** Separare i contatori tra `dev/` e `app/`, e applicare il cap globale solo ai tentativi *falliti* (oppure consentire a un IP che non ha alcun fallimento pregresso una soglia minima prima che il cap globale lo blocchi).

- **Verifica:** Test automatizzato che invia 60 tentativi di login falliti da un IP A in 5 secondi: verificare che (1) le risposte 6..60 siano restituite immediatamente con HTTP 429 senza `sleep`, (2) la `Store revision` di Roots e l'`ETag` di `view.php` **non cambino** a seguito dei tentativi falliti, e (3) il tempo di risposta di `view.php` rimanga invariato.

- **Decisione richiesta:** Confermare che lo stato di throttling non incrementi la `Store revision` di Roots e che il ritardo sia implementato tramite rifiuto immediato `429` + timestamp `not_before` anziché attesa sincrona del worker PHP.

### R-005 — Ambiguità e incoerenza nelle regole di precedenza e merge per i "past-midnight spills" con Eccezioni

- **Riferimento:** §4.1 (Domain model), §4.3 (Status timeline), §4.7 (Client Screens).

- **Problema:** Le regole sui turni che sforano oltre la mezzanotte (*past-midnight spills*, es. `22:00–02:00`) in §4.1 presentano lacune e contraddizioni quando interagiscono con le `Exceptions` (`Closure` e `Special hours`):
  
   1. §4.1 afferma: *"A past-midnight spill follows its start day's rules: it survives a Closure on the next day, and an Exception on the start day replaces it."*
      
      Ma cosa succede se sul giorno successivo ($D+1$) non c'è una `Closure`, bensì una `Exception` di tipo **Special hours** che inizia alle `01:00` (es. `01:00–05:00`) o se lo spill sul giorno $D$ (`22:00–02:00`) proviene da `Weekly hours` e sul giorno $D+1$ il mattino inizia alle `01:00` a causa di una `Special hours`?
  
   2. Subito dopo, §4.1 afferma: *"A spill that overlaps the next day's first period is refused by the Weekly hours and Season editors. When an Exception creates one, the two periods merge into one opening."*
      
      Questa frase lascia indefinito un caso critico: se Sabato ha orario ordinario `20:00–02:00` (spill fino alle 02:00 di Domenica) e il Cliente tocca **"Closed today"** (§4.7) la Domenica mattina alle `00:30` perché vuole chiudere subito il locale per un'emergenza, la creazione di una `Closure` per "oggi" (Domenica) **non chiude il locale**, perché lo spill di Sabato *"survives a Closure on the next day"*! Il locale continuerà a risultare **"Open · closes 02:00"** sul sito nonostante il Cliente abbia appena premuto *"Closed today"*!

- **Evidenze:**
  
   - §4.1: *"A past-midnight spill follows its start day's rules: it survives a Closure on the next day, and an Exception on the start day replaces it. A spill that overlaps the next day's first period is refused by the Weekly hours and Season editors. When an Exception creates one, the two periods merge into one opening."*
  
   - §4.7: *"a one-tap 'Closed today', which creates a one-day Closure for today's local date"*.

- **Scenario di fallimento:**
  
   1. Un pub ha orario del Sabato `18:00–02:00`.
  
   2. Alle `00:15` di Domenica, per un problema tecnico il pub deve chiudere immediatamente.
  
   3. Il titolare apre la PWA, vede che il tile dice *"Open · closes 02:00"*, entra nella Schedule e preme **"Closed today"**.
  
   4. Poiché l'orologio segna le `00:15` di Domenica, "today's local date" è Domenica. Viene creata una `Closure` per Domenica.
  
   5. Per la regola di §4.1 (*"A past-midnight spill ... survives a Closure on the next day"*), lo spill `00:00–02:00` appartenente a Sabato sopravvive alla `Closure` di Domenica.
  
   6. Il sito web e la PWA continuano a mostrare **"Open · closes 02:00"** fino alle 2 di notte, rendendo il pulsante d'emergenza "Closed today" inefficace durante qualsiasi turno post-mezzanotte.

- **Causa:** Conflitto tra il modello concettuale del dominio (dove l'apertura `00:00–02:00` appartiene alla data logica del giorno precedente) e l'azione UI "Closed today" (che usa la data di calendario corrente dell'orologio senza considerare se l'apertura in corso appartenga al giorno precedente).

- **Conseguenze:** Fallimento funzionale dell'azione principale del Cliente per tutte le attività serali/notturne (pub, ristoranti, locali, sale prove) dopo la mezzanotte.

- **Gravità / Priorità / Confidenza:** **Alta / P1 / Alta**.

- **Mitigazione proposta:**
  
   - Specificare in §4.7 il comportamento di **"Closed today"** quando è in corso un turno iniziato ieri (un *past-midnight spill* attivo in `now`): se `now` cade dentro uno spill del giorno $D-1$, "Closed today" deve troncare/chiudere anche lo spill attivo (o chiedendo conferma / applicando la chiusura a partire da `now`), oppure nella Status card a `00:15` il pulsante rapido deve essere contestuale all'apertura in corso (*"Close now for the rest of tonight / today"*).
  
   - Chiarire in §4.1 che il merge di periodi sovrapposti o adiacenti (come già indicato in §4.3 punto 2: *"Merge overlapping or touching intervals"*) si applica universalmente durante la risoluzione degli intervalli UTC prima di emettere i segmenti.

- **Verifica:** Test con orologio fissato a Domenica `00:30` durante uno spill del Sabato `20:00–02:00`: l'azione rapida di chiusura eseguita dal Cliente deve portare immediatamente lo stato calcolato a `Closed`.

- **Decisione richiesta:** Come deve comportarsi "Closed today" se premuto tra le `00:00` e la fine di uno spill iniziato il giorno precedente? (Opzione A: chiude solo i turni che iniziano oggi lasciando il locale "Aperto" fino alle 02:00; Opzione B: interrompe anche lo spill in corso o imposta una chiusura che copre l'apertura corrente).

### R-006 — Scrittura di `<roots>/data-path.php` nella directory applicativa e lockout al primo avvio

- **Riferimento:** §2 (Step 2), §3.1 (Files on the server), §3.2 (Data directory and Stores), §3.6 (Authentication and sessions).

- **Problema:**
  
   1. **Permessi di scrittura su `<roots>`:** §3.2 stabilisce che al setup Roots cerca una directory scrivibile fuori dalla webroot (es. `../innesto-data/`) e poi scrive il percorso assoluto scelto in `<roots>/data-path.php`. Ma se `<roots>` stessa non è scrivibile dal processo PHP (configurazione comune e raccomandata su hosting dove i file PHP del codice sorgente hanno permessi `0755`/`0644` di proprietà dell'utente FTP e solo una cartella dati dedicata è scrivibile da `www-data`/`nobody`), la scrittura di `<roots>/data-path.php` fallisce anche quando `../innesto-data/` esiste ed è scrivibile.
  
   2. **Trigger non autenticato al primo accesso (Step 2):** §2 Step 2 dice: *"The Developer opens `[https://example.com/innesto/](https://example.com/innesto/)`, which redirects to `dev/`. Roots picks the Data directory, writes a Setup code into it and asks for that code."*
      
      Questo avviene sulla semplice richiesta `GET /innesto/dev/` prima di qualsiasi autenticazione. Se un bot o scanner web visita l'URL prima del Developer (o se il Developer non ha ancora reso scrivibile la cartella preferita `../innesto-data/` e apre la pagina per verificare), Roots sceglie immediatamente il fallback `<roots>/data-<random>/`, scrive `data-path.php` e fissa la posizione della Data directory prima ancora che il Developer abbia inserito il Setup code.

- **Evidenze:**
  
   - §2 Step 2: *"The Developer opens `[https://example.com/innesto/](https://example.com/innesto/)`, which redirects to `dev/`. Roots picks the Data directory, writes a Setup code into it and asks for that code."*
  
   - §3.2: *"at setup, Roots walks up from `<roots>` looking for a writable directory outside the webroot (e.g. `../innesto-data/`). If there is none, it creates `<roots>/data-<random>/` with layered defences. The chosen absolute path is written to `<roots>/data-path.php`, which the Developer may edit over FTP to move it."*

- **Scenario di fallimento:**
  
   1. Il Developer carica `innesto/` via FTP; il server ha `../` non scrivibile da PHP, ma il Developer intende creare `../innesto-data/` con permessi `0777`/`0775` oppure al contrario `<roots>/` non ha permessi di scrittura per il processo web.
  
   2. Nel primo caso, un bot richiede `/innesto/` un secondo dopo l'upload FTP: Roots crea `<roots>/data-a8f3b2/` dentro la webroot e fissa `data-path.php`.
  
   3. Nel secondo caso (`<roots>` read-only per PHP, ma `../innesto-data/` scrivibile), Roots non riesce a scrivere `<roots>/data-path.php` e fallisce con errore fatale o rigenera un Setup code a ogni richiesta perché non ricorda dove ha messo la Data directory.

- **Causa:** Accoppiamento tra la richiesta `GET` iniziale non autenticata e la mutazione irreversibile del filesystem (`mkdir` + scrittura di `data-path.php`).

- **Conseguenze:** Fallimento del setup su ambienti con webroot protetta da scrittura o creazione indesiderata di cartelle `data-<random>` su semplici richieste `GET` non autenticate.

- **Gravità / Priorità / Confidenza:** **Alta / P1 / Alta**.

- **Mitigazione proposta:**
  
   1. Pre-includere nella release un file `<roots>/data-path.php` con valore `null` (o template configurabile via FTP prima del primo avvio).
  
   2. Se `<roots>/` non è scrivibile da PHP, non fallire se il percorso standard deterministico `../innesto-data/` esiste ed è scrivibile (usandolo come convenzione di default senza bisogno di sovrascrivere `data-path.php`, oppure chiedendo al Developer nella schermata di Self-check di caricare/modificare `data-path.php` via FTP).
  
   3. Non fissare in modo permanente la scelta del backend di storage fino al POST autenticato dal Setup code.

- **Verifica:** Test di setup in cui `<roots>/` ha permessi `0555` (sola lettura per PHP) ma `../innesto-data/` ha permessi `0775`: il setup deve funzionare o mostrare un messaggio diagnostico esplicito anziché fallire nel tentativo di scrivere `data-path.php`.

- **Decisione richiesta:** Come deve comportarsi Roots se `<roots>/` non è scrivibile da PHP per creare `data-path.php`?

### R-007 — Corruzione semantica nell'azione "We're open again" (`end = yesterday`) per chiusure iniziate oggi o nel futuro

- **Riferimento:** §4.1 (Domain model), §4.2 (Store), §4.7 (Client Screens).

- **Problema:** §4.7 specifica che quando una chiusura a tempo indeterminato (*"closed until further notice"*, ossia `end = null`) è attiva, la schermata Home mostra il pulsante **"We're open again"**, il quale *"sets the Closure's end to yesterday"*.
  Ma cosa succede se il Cliente ha attivato la chiusura a tempo indeterminato **oggi** stesso (quindi `start == today`) per un guasto mattutino e nel pomeriggio il guasto si risolve e preme **"We're open again"**?
  Impostare `end = yesterday` produce un record `Exception` nello Store con **`end < start`** (es. `start = 2026-10-18`, `end = 2026-10-17`)!

- **Evidenze:**
  
   - §4.7: *"When a 'closed until further notice' Closure is running, the home shows it even on a day a shorter Exception overrides, with 'We're open again', which sets the Closure's end to yesterday."*
  
   - §4.1: *"Lifecycle: a non-repeating Exception stops affecting output once its end date has passed. It is deleted 30 days later, inside the transaction of the next save to that Schedule."*

- **Scenario di fallimento:**
  
   1. Il 18 ottobre alle `09:00` il Cliente crea una Closure "until further notice" a partire da oggi (`start = 2026-10-18`, `end = null`).
  
   2. Alle `14:00` dello stesso 18 ottobre il problema è risolto; il Cliente apre l'app e tocca **"We're open again"**.
  
   3. Il sistema imposta `end = 2026-10-17` (ieri).
  
   4. Il record nello Store ha ora un intervallo invalido (`start: 2026-10-18`, `end: 2026-10-17`). Se il validatore dello Store o del dominio rifiuta `end < start`, il salvataggio fallisce con errore! Se invece lo salva, i calcoli di durata dello span (`end - start` usato per la regola *"Among overlapping Exceptions, the shorter span wins"* in §4.1) producono una durata negativa (`-1 giorni`).

- **Causa:** Assunzione implicita che una chiusura "until further notice" sia sempre iniziata almeno il giorno precedente a quello in cui viene terminata.

- **Conseguenze:** Errore bloccante quando il Cliente tenta di riaprire nello stesso giorno in cui ha chiuso, o corruzione dell'invariante `start <= end` nello Store.

- **Gravità / Priorità / Confidenza:** **Alta / P1 / Alta**.

- **Mitigazione proposta:**
  
   - Specificare in §4.7 che **"We're open again"**:
     
      - Se `start < today`: imposta `end = yesterday` (preservando lo storico dei giorni passati in cui l'attività è stata effettivamente chiusa, fino alla pulizia dopo 30 giorni).
     
      - Se `start >= today`: **elimina direttamente** l'Exception (oppure la rimuove con possibilità di Undo), poiché non c'è alcun giorno passato completato da conservare e impostare `end = yesterday` violerebbe l'invariante `start <= end`.

- **Verifica:** Test funzionale: creare una Closure "until further notice" con `start = today`, invocare immediatamente "We're open again" nello stesso giorno `today`, e verificare che il salvataggio abbia successo e che nello Store non esistano record con `end < start`.

- **Decisione richiesta:** Confermare che per `start >= today` l'azione "We're open again" elimini l'Exception anziché impostare `end = yesterday`.

### R-008 — Limitazioni della `Store API` nel filtraggio delle Exceptions (`end = null` e date `MM-DD` vs `YYYY-MM-DD`)

- **Riferimento:** §3.3 (Store API), §3.7 (Manifest), §4.1 (Domain model), §4.2 (Store).

- **Problema:** §3.3 definisce che `Store::list()` supporta unicamente *"equality and range filters on declared fields, ordering, limit. Nothing else"*. Nel Manifest di `in.hours` (§3.7) e in §4.2, la collection `exceptions` dichiara i campi filtrabili `schedule` (`string`), `start` (`string`) ed `end` (`string`).
  Tuttavia, §4.2 stabilisce che:
  
   1. `start` contiene una stringa `YYYY-MM-DD` quando `yearly = false`, ma contiene **`MM-DD`** quando `yearly = true`.
  
   2. `end` contiene una stringa `YYYY-MM-DD`, oppure `MM-DD` quando `yearly = true`, oppure **`null`** quando è una chiusura a tempo indeterminato (`open-ended Closure`).
      In SQLite (e nei confronti lessicografici di stringhe in PHP):

- Un filtro di range su `end >= '2026-10-18'` per trovare le eccezioni non scadute **esclude automaticamente tutte le chiusure con `end = null`** (in SQL `NULL >= '2026-10-18'` è `UNKNOWN`/falso) e **confronta in modo errato le date `MM-DD`** (poiché `'12-25' < '2026-10-18'` lessicograficamente, perché `'1' < '2'`!).

- **Evidenze:**
  
   - §3.3: *"Operations: `get`, `put` (create or replace), `delete`, `list` (equality and range filters on declared fields, ordering, limit), and `transaction(callable)`. Nothing else"*.
  
   - §3.7: `'exceptions' => ['fields' => ['schedule' => 'string', 'start' => 'string', 'end' => 'string']]`.
  
   - §4.2: *"`start` (filterable, `YYYY-MM-DD`, or `MM-DD` when yearly), `end` (filterable, `null` = open-ended Closure)"*.

- **Scenario di fallimento:**
  
   1. Lo sviluppatore di `in.hours` usa `store->list('exceptions', ['schedule' => 'main', 'end' => ['>=', $today]])` per caricare le eccezioni attive o per fare il pruning a 30 giorni.
  
   2. Tutte le chiusure a tempo indeterminato (`end = null`) e tutte le eccezioni annuali (`MM-DD` che iniziano con `'0'` o `'1'`, cioè tutte da gennaio a dicembre poiché `'01'..'12' < '20'`) vengono scartate dal filtro di range!
  
   3. Se invece `in.hours` filtra *solo* per `schedule = 'main'` e carica in memoria tutte le eccezioni per filtrarle in PHP, allora dichiarare `start` ed `end` come campi indicizzati/filtrabili nel Manifest (§3.7) e in §4.2 è fuorviante o inutile.

- **Causa:** Polimorfismo di formato dentro gli stessi campi (`start`/`end` usati sia per date ISO `YYYY-MM-DD`, sia per ricorrenze `MM-DD`, sia per `null`) combinato con una `Store API` minimale priva di operatori `IS NULL` / `OR`.

- **Conseguenze:** Bug gravi nel recupero delle eccezioni annuali e delle chiusure `until further notice`, oppure divergenza di comportamento tra il filtro SQLite (dove `NULL` ha semantica ternaria SQL) e il filtro PHP del backend flat-file (dove `null < '2026'` in PHP non-strict o lancia TypeError se non gestito esplicitamente).

- **Gravità / Priorità / Confidenza:** **Alta / P1 / Alta**.

- **Mitigazione proposta:**
  
   - **Nel contratto di `Store::list()` (§3.3):** Specificare esplicitamente la semantica dei valori `null` nei filtri di uguaglianza e di range e nell'ordinamento, affinché SQLite e flat-file si comportino in modo identico (e coprirlo nel test di contratto §7.1!).
  
   - **Nel modello di `in.hours` (§4.2):** Poiché le eccezioni non scadute di una singola Schedule sono al massimo qualche decina (e quelle scadute vengono cancellate dopo 30 giorni, §4.1), filtrare sullo Store solo per `schedule` (o mantenere `expires_at` come campo separato `YYYY-MM-DD` valorizzato a `'9999-12-31'` per le eccezioni annuali e le chiusure open-ended!). Usando un campo `active_until = '9999-12-31'` per `yearly` e `open-ended`, qualsiasi semplice filtro di range `active_until >= $cutoff` funziona in modo nativo, veloce e identico sia su SQLite che su flat-file.

- **Verifica:** Aggiungere alla *Store contract suite* (§7.1) test espliciti con documenti aventi campi indicizzati impostati a `null` sotto filtri di uguaglianza, range (`>`, `<`, `>=`, `<=`) e `ORDER BY` per dimostrare l'identità tra SQLite e flat-file.

- **Decisione richiesta:** Sostituire i campi indicizzati `start`/`end` in `exceptions` con il solo filtro su `schedule` (o con `active_until` normalizzato a `YYYY-MM-DD` / `'9999-12-31'`)?

### R-009 — Fragilità dell'omissione di `lang` nello snippet generato quando coincide con il Default locale

- **Riferimento:** §3.5 (Shared config), §3.8 (Locale resolution), §3.10 (Embed code generator).

- **Problema:** §3.10 stabilisce che nell'Embed code generator il selettore della lingua ha il Default locale preselezionato, *"and `lang` left out when it is the Default"*. Tuttavia, subito sotto, l'esempio mostrato in §3.10 per `it` include `lang="it"` (`<in-hours view="week" schedule="main" lang="it">`).
  Oltre alla contraddizione interna tra la regola testuale e l'esempio di codice nella stessa sezione, omettere `lang` quando coincide col Default crea una trappola operativa:
  
   1. Al momento dell'installazione, il Developer lascia `Default locale = it` e copia lo snippet sulla pagina italiana `/it/contatti.html` (che quindi viene generato **senza** `lang="it"`).
  
   2. Un mese dopo, il sito diventa principalmente in inglese e il Developer cambia il `Default locale` in `en` nelle impostazioni condivise (§3.5 afferma espressamente: *"All of these can be edited later"*).
  
   3. Improvvisamente, la pagina italiana `/it/contatti.html` (che non aveva `lang="it"` perché al momento della generazione `it` era il default) inizia a mostrare gli orari in **inglese**!

- **Evidenze:**
  
   - §2 Step 4: *"The Developer confirms ... the Default and Enabled locales ... All of these can be edited later (§3.5)."*
  
   - §3.10: *"locale (Default preselected, and `lang` left out when it is the Default)"* vs l'esempio immediatamente sottostante: `<in-hours view="week" schedule="main" lang="it">`.

- **Scenario di fallimento:**
  
   1. Il Developer genera lo snippet per la lingua predefinita `it` (senza `lang="it"`) e lo incolla nel template italiano del sito statico.
  
   2. Successivamente aggiunge `en` e imposta `en` come `Default locale` in Roots.
  
   3. Tutte le pagine italiane già pubblicate cambiano silenziosamente lingua in inglese senza che nessuno abbia toccato il loro HTML.

- **Causa:** Ottimizzazione prematura per risparmiare 10 caratteri (`lang="it"`) nello snippet generato, creando una dipendenza implicita tra l'HTML statico pubblicato e una configurazione mutabile sul server.

- **Conseguenze:** Regressione silenziosa della lingua sulle pagine esistenti al cambio del Default locale e confusione nella documentazione.

- **Gravità / Priorità / Confidenza:** **Media / P1 / Alta**.

- **Mitigazione proposta:**
  
   - **Includere sempre esplicitamente `lang="<locale>"`** nello snippet generato dall'Embed code generator (esattamente come fa già l'esempio di codice in §3.10!), lasciando la risoluzione al Default locale in `view.php` solo come fallback difensivo quando un integratore scrive il tag a mano senza `lang`.

- **Verifica:** Test del generatore di snippet: qualsiasi locale selezionato nel form deve produrre l'attributo `lang="..."` (o il parametro `'lang' => '...'`) nello snippet generato.

- **Decisione richiesta:** Confermare l'inclusione esplicita di `lang` in tutti gli snippet generati, allineando il testo di §3.10 all'esempio di codice già presente in §3.10.

### R-010 — Rischio di collisione e persistenza di dati in `sessionStorage` al timeout di sessione della PWA

- **Riferimento:** §3.13 (Client PWA shell), §8 (Gaps settled by this spec).

- **Problema:** §3.13 specifica: *"before posting, the shell stores the form's fields in `sessionStorage`, keyed by the Screen URL. If Roots answers that the session has expired, nothing is written. The PIN pad shows in-app, then the Client returns to the same Screen with the fields refilled... The stored copy is cleared on a confirmed Save or when the Client leaves the form."*
  
  Ci sono due problemi in questo meccanismo:
  
   1. **Conflitto con dati freschi (Stale Overwrite):** Se la sessione del Cliente è scaduta (dopo 90 giorni di inattività o perché il PIN è stato rigenerato da un altro dispositivo per revocare un telefono smarrito, §3.6), nel frattempo un altro operatore o il Developer può aver modificato gli orari. Quando il Cliente reinserisce il nuovo PIN, la shell sovrascrive silenziosamente tutti i campi del form (es. l'intera griglia settimanale `Regular hours` e tutte le `Seasons`) con il contenuto salvato in `sessionStorage` prima del login, rischiando di far salvare al Cliente una versione obsoleta che cancella le modifiche altrui.
  
   2. **Struttura complessa dei form dinamici:** L'editor `Regular hours` contiene liste dinamiche di periodi per giorno e stagioni aggiunte/rimosse nel DOM (§4.7). Un semplice salvataggio chiave-valore dei campi HTML non ricostruisce le righe aggiunte o rimosse dinamicamente se il template non viene ripopolato lato server o tramite uno stato strutturato.

- **Evidenze:**
  
   - §3.13: *"before posting, the shell stores the form's fields in `sessionStorage`, keyed by the Screen URL ... returns to the same Screen with the fields refilled"*.
  
   - §4.7: *"Regular hours editor: Weekly hours and Seasons, with inline errors"*.

- **Scenario di fallimento:**
  
   1. Il Cliente nella schermata `Regular hours` aggiunge 2 nuovi turni pomeridiani e una nuova `Season` cliccando sui pulsanti "+" gestiti via JS, poi preme "Save" a sessione scaduta.
  
   2. Dopo il re-login col PIN, il server restituisce l'HTML iniziale della schermata `Regular hours` (che ha meno input DOM rispetto a quelli presenti prima del submit).
  
   3. Lo script di refill cerca di popolare input che non esistono nel DOM fresco (perdendo le righe aggiunte) oppure sovrascrive gli input esistenti in modo disallineato.

- **Causa:** Sottovalutazione della complessità di serializzare e re-idratare form dinamici multi-riga (come `Weekly hours` + `Seasons`) tramite semplice `sessionStorage` nel browser.

- **Conseguenze:** Fallimento del test Playwright §7.3 (*"an expired session returns to the refilled form after the PIN"*) se testato su `Regular hours`, o perdita parziale dei dati inseriti.

- **Gravità / Priorità / Confidenza:** **Media / P2 / Alta**.

- **Mitigazione proposta:**
  
   - Invece di ricaricare la pagina dal server dopo il PIN e tentare di re-idratare un DOM potenzialmente complesso da `sessionStorage`, poiché i form della PWA vengono già inviati via `fetch` (§3.13: *"the shell's JS submits Screen forms with `fetch`"*), quando `fetch` riceve `401 Unauthorized` (sessione scaduta), **non navigare via dalla pagina**: mostra il tastierino del PIN come **overlay/modale in-app** sopra il form corrente (che rimane intatto nel DOM con tutte le sue righe e il suo stato!), invia il PIN via `fetch` per ottenere il nuovo cookie di sessione e nuovo token CSRF, chiudi l'overlay e mostra il banner *"Not saved yet: you were signed out. Check and tap Save again."*
  
   - Questo approccio elimina quasi del tutto la fragilità della ricostruzione DOM da `sessionStorage` (che può restare come semplice fallback in caso di full reload).

- **Verifica:** Test Playwright (§7.3): aprire un form complesso (`Regular hours` o `Exception`), aggiungere periodi, invalidare la sessione lato server, premere Save, inserire il PIN e verificare che tutti i periodi aggiunti siano ancora presenti e salvabili con un secondo tap.

- **Decisione richiesta:** Approvare l'uso dell'overlay PIN in-place (mantenendo il DOM del form vivo sotto l'overlay) come meccanismo primario per la sessione scaduta durante il `fetch` di salvataggio.

### R-011 — Scrittura nello Store di Roots a ogni richiesta autenticata (Sliding Session Expiry) e invalidazione dell'ETag globale

- **Riferimento:** §3.3 (Store API), §3.4 (Roots' own Store), §3.5 (Shared config and Roots settings), §3.6 (Authentication and sessions), §3.9 (HTTP behaviour).

- **Problema:** Combinando quattro requisiti presenti nel documento emerge un grave effetto collaterale architetturale:
  
   1. §3.4: *"Roots' own Store: It holds ... session token hashes with their expiry"*.
  
   2. §3.6: *"Idle expiry is sliding: Client 90 days, Developer 12 hours."*
  
   3. §3.3: *"Store revision: a counter in each Store, bumped by every committed write transaction."*
  
   4. §3.9: *"`ETag`: a hash of the Module's Store revision, Roots' Store revision..."*
      
      Se le sessioni (con la loro scadenza *sliding*) vivono dentro `Roots' own Store` e ogni scrittura nello Store incrementa `Roots' Store revision`, allora **ogni singola richiesta GET del Developer in `dev/` o del Client in `app/`** aggiorna l'`expiry` della sessione nello Store di Roots, il che **incrementa la `Store revision` di Roots**, il che **invalida l'`ETag` di tutte le viste pubbliche (`view.php`) di tutti i Moduli per tutti i visitatori del sito**, anche quando nessuno ha modificato alcun dato o impostazione!

- **Evidenze:**
  
   - §3.3: *"Store revision: a counter in each Store, bumped by every committed write transaction."*
  
   - §3.4: *"Roots' own Store: It holds Shared config, Roots settings, each Module's state ... session token hashes with their expiry, short-lived throttling state, the latest Self-check results with their time, and per-Module error counts with the last error"*.
  
   - §3.6: *"Idle expiry is sliding: Client 90 days, Developer 12 hours."*
  
   - §3.9: *"`ETag`: a hash of the Module's Store revision, Roots' Store revision..."*

- **Scenario di fallimento:**
  
   1. Il Cliente apre semplicemente la PWA sul telefono per guardare se il negozio risulta aperto (senza toccare o salvare nulla).
  
   2. Poiché la scadenza della sessione è *sliding*, Roots aggiorna il timestamp di scadenza della sessione nello Store di Roots.
  
   3. Analogamente, un modulo genera un errore o viene eseguito un Self-check automatico all'apertura di `dev/` (§3.11: *"Cheap server-side probes ... re-run on every Developer-area load. Results are stored with their time"*).
  
   4. Ogni volta che uno di questi dati operativi viene scritto in `Roots' own Store`, la `Store revision` di Roots aumenta.
  
   5. Tutti i client pubblici e proxy che inviano `If-None-Match` a `view.php` ricevono `200 OK` (con ricalcolo completo del rendering) anziché `304 Not Modified`.

- **Causa:** Collocazione nello stesso Store (e sotto lo stesso contatore `Store revision`) sia dei **dati di configurazione pubblica** (`Shared config`, stato dei Moduli) sia dello **stato operativo effimero** (sessioni sliding, throttling, timestamp dell'ultimo Self-check a ogni caricamento di `dev/`, contatori di errore).

- **Conseguenze:** Invalidazione continua e ingiustificata della cache HTTP (`ETag` / `304`) di tutte le viste pubbliche e scritture su disco a ogni singola richiesta `GET` in `dev/` e `app/`.

- **Gravità / Priorità / Confidenza:** **Media / P1 / Alta**.

- **Mitigazione proposta:**
  
   1. Separare la `Shared config revision` (che cambia **solo** quando cambiano `Shared config`, `Roots settings` o lo stato `Active/Inactive` di un modulo) dallo stato runtime/operativo (sessioni, throttling, risultati Self-check, contatori errori), oppure usare due Store interni distinti per Roots (`roots_config` vs `roots_runtime`).
  
   2. Aggiornare l'expiry *sliding* della sessione su disco solo se è trascorso almeno un intervallo significativo dall'ultimo aggiornamento (es. > 5 minuti per il Developer, > 1 ora per il Client), evitando una scrittura su disco a ogni click di navigazione.

- **Verifica:** Test di integrazione: leggere l'`ETag` di `view.php`, effettuare il login e navigare su 3 schermate in `dev/` e `app/` senza salvare configurazioni o dati di modulo, quindi richiedere nuovamente `view.php` con `If-None-Match`: la risposta deve essere `304 Not Modified`.

- **Decisione richiesta:** Confermare la separazione tra la revisione della configurazione di Roots (usata nell'`ETag`) e le scritture di stato operativo (sessioni, throttling, self-check, contatori errori).

### R-012 — Incompletezza e potenziali anomalie nella generazione di `jsonld` (Schema.org)

- **Riferimento:** §4.1 (Domain model), §4.4 (Views — `jsonld`).

- **Problema:** §4.4 definisce le regole per la vista `jsonld` (`openingHoursSpecification`), ma presenta tre lacune rispetto al modello del dominio di §4.1:
  
   1. **Stagioni ed Eccezioni ricorrenti annuali (`yearly = true`):** §4.1 prevede `Season` ed `Exception` che si ripetono ogni anno (`MM-DD`) e possono anche attraversare il capodanno (es. stagione invernale `11-01` – `03-31` o chiusura natalizia `12-24` – `01-06`). Schema.org `validFrom` e `validThrough` in `OpeningHoursSpecification` richiedono date complete (`YYYY-MM-DD` / ISO 8601 `Date` o `DateTime`), non stringhe `--MM-DD`. La specifica non definisce su quale anno (o finestra temporale) debbano essere proiettate le stagioni/eccezioni annuali nel `jsonld`, né come emettere una stagione che attraversa il capodanno.
  
   2. **Orizzonte delle eccezioni future in `jsonld`:** §4.4 dice che nella vista `week` vengono mostrate solo le eccezioni entro l'orizzonte di 14 giorni, mentre per `jsonld` dice: *"Seasons and Exceptions that haven't ended become dated entries with `validFrom`/`validThrough`"*. Se ci sono 10 eccezioni annuali, per quale anno vengono emesse? Solo per la prossima occorrenza entro i prossimi 12 mesi (o 14 giorni)?
  
   3. **Sovrapposizione in Schema.org:** Se una `Exception` di tipo `Special hours` copre un singolo mercoledì (`2026-12-24`), in Schema.org quale `dayOfWeek` assume la voce `OpeningHoursSpecification`? E se una `Exception` dura 3 giorni (Mercoledì–Venerdì) con orari speciali, viene emessa una voce per ciascun `dayOfWeek` toccato o per tutti i giorni?

- **Evidenze:**
  
   - §4.1: *"A yearly Season uses day–month bounds and may cross the new year... An Exception may repeat yearly on fixed dates"*.
  
   - §4.4: *"Seasons and Exceptions that haven't ended become dated entries with `validFrom`/`validThrough`, a Closure being `00:00`/`00:00`. Expired Exceptions are pruned at render."*

- **Scenario di fallimento:**
  
   1. Il Developer configura una stagione invernale annuale (`11-01` – `03-31`) e una chiusura annuale per il Santo Patrono (`06-15`).
  
   2. Al rendering di `jsonld`, se il codice emette `validFrom: "06-15"` il markup JSON-LD è invalido per il validatore di Google Rich Results (che richiede `YYYY-MM-DD`).
  
   3. Se invece proietta l'anno corrente `2026` il 18 ottobre 2026, `2026-06-15` è già passato (quindi verrebbe scartato come scaduto anziché proiettato al `2027-06-15`), e per `11-01` – `03-31` se usa lo stesso anno produce `validFrom: "2026-11-01"`, `validThrough: "2026-03-31"` (`validThrough < validFrom`, invalido!).

- **Causa:** Mancata specifica della regola di concretizzazione delle ricorrenze annuali (`MM-DD`) in date `YYYY-MM-DD` per la vista `jsonld`.

- **Conseguenze:** Errori di validazione su Google Search Console per i dati strutturati `LocalBusiness`.

- **Gravità / Priorità / Confidenza:** **Media / P2 / Alta**.

- **Mitigazione proposta:**
  
   - Specificare in §4.4 che per `jsonld` le `Seasons` e le `Exceptions` (incluse quelle `yearly`) vengono concretizzate in intervalli `YYYY-MM-DD` sulla finestra `[today, today + 365 days)` (o sull'orizzonte scelto), risolvendo gli attraversamenti di capodanno nell'effettivo anno di inizio e fine (es. `2026-11-01` – `2027-03-31`).

- **Verifica:** Test unitario su `jsonld` con una Season annuale che attraversa il capodanno (`11-01`..`03-31`) e una Exception annuale già passata nell'anno solare corrente: verificare che tutte le proprietà `validFrom` e `validThrough` emesse rispettino il formato `^\d{4}-\d{2}-\d{2}$` e la condizione `validFrom <= validThrough`.

- **Decisione richiesta:** Quale orizzonte temporale (es. prossimi 365 giorni vs prossimi 14 giorni) deve coprire `jsonld` per la proiezione di Seasons ed Exceptions?

### R-013 — Race condition nella rotazione di `Roots log` e potenziale rottura del guard PHP

- **Riferimento:** §3.12 (Roots log and Health Screen).

- **Problema:** §3.12 descrive il `Roots log` come *"one append-only guarded file (`<?php exit; ?>` followed by JSON lines) in the Data directory... When the file passes about 1 MB at write time, it is renamed over one previous generation."*
  
  Su un file che inizia con `<?php exit; ?>\n`, un semplice `fopen(..., 'a')` o `FILE_APPEND` su un file che è appena stato ruotato da un processo concorrente creerebbe un nuovo file **privo dell'intestazione `<?php exit; ?>\n`**! Se due richieste concorrenti loggano un errore nel momento in cui il file supera 1 MB, senza un lock coordinato si rischia:
  
   1. Che un processo scriva una riga JSON in append su un nuovo file prima che l'header `<?php exit; ?>` venga scritto, rendendo il file di log scaricabile/leggibile in chiaro via HTTP sugli host senza `.htaccess`!
  
   2. Che due processi eseguano entrambi il `rename` in sequenza immediata, sovrascrivendo sia la generazione corrente sia la generazione precedente e perdendo l'intero storico di 1 MB appena archiviato.

- **Evidenze:**
  
   - §3.12: *"Roots log: one append-only guarded file (`<?php exit; ?>` followed by JSON lines) in the Data directory... When the file passes about 1 MB at write time, it is renamed over one previous generation."*

- **Scenario di fallimento:**
  
   1. Su un host senza `.htaccess` con Data directory in-webroot, il file `roots-log.php` raggiunge 1 MB.
  
   2. Il Processo A rinomina `roots-log.php` in `roots-log.1.php`.
  
   3. Prima che il Processo A crei il nuovo `roots-log.php` con `<?php exit; ?>`, il Processo B apre `roots-log.php` in modalità append (creandolo da zero) e vi scrive direttamente `{"ts":"...","event":"..."}` alla riga 1.
  
   4. Il nuovo `roots-log.php` non inizia più con `<?php exit; ?>`: chiunque richieda il file via HTTP vede le righe JSON in chiaro (poiché fuori dai tag `<?php ?>` PHP stampa il testo letterale!).

- **Causa:** Assenza di specifica sul locking (`flock` su un lock file dedicato al logger) durante la scrittura/rotazione e l'inizializzazione dell'header `<?php exit; ?>`.

- **Conseguenze:** Potenziale esposizione del file di log via HTTP o perdita della generazione precedente durante la rotazione.

- **Gravità / Priorità / Confidenza:** **Media / P2 / Media**.

- **Mitigazione proposta:**
  
   - Specificare in §3.12 che le scritture e la rotazione del `Roots log` avvengono sotto `flock` esclusivo su un file `roots-log.lock` (o che il nuovo file con `<?php exit; ?>\n` viene preparato in un file temporaneo o controllato a `ftell() === 0` sotto `flock` prima di qualsiasi append).

- **Verifica:** Test unitario del logger: verificare che anche dopo la rotazione a 1 MB il file attivo e il file archiviato inizino sempre esattamente con `<?php exit; ?>\n`.

- **Decisione richiesta:** Nessuna (dettaglio implementativo di sicurezza da fissare nella specifica di §3.12).

### R-014 — Fragilità nel carry-over degli attributi HTML e re-entrancy nello swap `outerHTML` del Web Component

- **Riferimento:** §3.8 (Rendering: one path, four surfaces), §3.15 (Styling contract and overrides).

- **Problema:** §3.8 specifica che il Web Component `<in-hours>`:
  
   1. Quando è vuoto (*loader*), fa fetch del frammento da `view.php`, copia i propri attributi non-opzione (`class`, `id`, `style`, `data-*`, …) sulla nuova radice (dove `lang` e `data-in-rendered` del frammento vincono) e sostituisce se stesso tramite `outerHTML`.
  
   2. Quando viene inserito nel DOM il nuovo `<in-hours lang="…" data-in-rendered>`, il browser invoca nuovamente `connectedCallback()` sulla nuova istanza dell'elemento custom, che ora agisce da *enhancer*.
      Tuttavia, la copia cieca degli attributi ha due casi limite:

- **Collisione dell'attributo `class`:** Se il frammento restituito dal server (o tramite un `Template override` / `Class map` in §3.15) possiede già delle classi sulla radice `<in-hours class="custom-root">`, e l'elemento loader nella pagina ospite aveva `<in-hours class="my-margin" view="week" schedule="main">`, sovrascrivere l'attributo `class` con `setAttribute('class', ...)` cancella le classi emesse dal server (o viceversa). Le classi vanno **unite (`classList.add`)**, non sovrascritte.

- **Attributi eventi inline (`on*`):** Copiare tutti gli attributi non-opzione senza filtrare eventuali attributi `on*` è una cattiva pratica DOM; conviene copiare facendo merge di `class` e ignorando attributi riservati.

- **Evidenze:**
  
   - §3.8: *"It copies its non-option attributes (`class`, `id`, `style`, `data-*`, …) onto the new root, where the fragment's own `lang` and rendered marker win."*

- **Scenario di fallimento:**
  
   1. Il Developer definisce un template override o una classe sul root `<in-hours class="theme-dark">` e nella pagina statica scrive `<in-hours class="mt-4" view="week" schedule="main">`.
  
   2. Il loader copia `class="mt-4"` sovrascrivendo `theme-dark`, facendo perdere lo stile del template.

- **Causa:** Specifica che parla di copia di attributi (`class`) anziché di unione dei token per `class`.

- **Conseguenze:** Perdita delle classi CSS applicate lato server sulla radice del frammento.

- **Gravità / Priorità / Confidenza:** **Bassa / P2 / Media**.

- **Mitigazione proposta:** Specificare in §3.8 che per l'attributo `class` il carry-over esegue l'unione (`classList`) delle classi del loader con quelle eventualmente già presenti sulla radice del frammento.

- **Verifica:** Test Vitest in §7: verificare che se il loader ha `class="a"` e il frammento restituito ha `class="b"`, l'elemento risultante dopo lo swap abbia `class="b a"`.

- **Decisione richiesta:** Nessuna.

## 5. Analisi degli scenari avversariali

| **Scenario**                                                 | **Condizioni iniziali**                                                                 | **Sequenza degli eventi**                                                                                                                                                                                                                                       | **Risultato indesiderato**                                                                                                                                                                                                             | **Controllo o requisito mancante**                                                                                                                          |
| ------------------------------------------------------------ | --------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **AS-01: Setup su Nginx / LiteSpeed senza fuori-webroot**    | Host condiviso che ignora `.htaccess` e consente scrittura solo dentro la webroot.      | 1. Upload FTP di `innesto/`.<br>2. Apertura di `dev/`.<br>3. Creazione di `data-<random>/` nella webroot.<br>4. Il browser esegue la probe HTTP sul file nella Data directory.                                                                                  | Il file non-PHP risponde 200 OK. Invece di attivare il backend flat-file protetto da `<?php exit; ?>`, il Self-check va in stato `Fail` e blocca il setup.                                                                             | Distinzione tra probe PHP (Fail se eseguita/letta in chiaro) e probe non-PHP (Warn + obbligo di flat-file `.php`). (Vedi **R-001**).                        |
| **AS-02: Fallimento a metà di `transaction()` su flat-file** | Backend flat-file attivo; Schedule `main` con 3 Exceptions.                             | 1. Il Developer elimina la Schedule `main` (§4.2) oppure salva la Schedule attivando il pruning delle Exceptions scadute (§4.1).<br>2. La modifica su `schedules` va a buon fine, ma la scrittura su `exceptions` fallisce (es. quota disco piena o eccezione). | La Schedule è cancellata ma le sue Exceptions restano orfane nello Store (o viceversa), violando l'atomicità transazionale.                                                                                                            | Lock e file unico a livello di Store per il backend flat-file con commit atomico tramite singolo `rename()`. (Vedi **R-002**).                              |
| **AS-03: Attraversamento della mezzanotte con cache attiva** | Negozio chiuso il Lunedì sera (`23:50`), apre Martedì alle `09:00`. `max-age = 30 min`. | 1. Visitatore A carica la pagina alle `23:50` di Lunedì (la risposta entra in cache CDN/browser).<br>2. Alle `00:10` di Martedì il Visitatore A guarda la scheda o il Visitatore B carica la pagina dalla cache.                                                | Il segmento post-mezzanotte (`00:00–09:00` di Martedì) mostra ancora *"Chiuso · riapre domani alle 09:00"* se "oggi/domani" è stato calcolato rispetto al `now` del render anziché al `from` del segmento.                             | Regola che vincola il calcolo di "today/tomorrow" alla data locale dell'inizio di ciascun segmento (`segment.from`). (Vedi **R-003**).                      |
| **AS-04: DoS sul login tramite Global Rate Cap**             | Sistema in produzione con PIN Cliente attivo.                                           | 1. Un attaccante invia 1 richiesta POST ogni 2 secondi (30 req/min) con PIN errati a `app/`.<br>2. Il Cliente legittimo tenta il login dal proprio smartphone.                                                                                                  | I worker PHP vengono bloccati da `sleep()` (se il delay è sincrono) oppure il Cliente legittimo viene bloccato/rallentato dal cap globale; inoltre lo Store di Roots subisce scritture continue che invalidano l'`ETag` di `view.php`. | Rifiuto immediato `429` senza `sleep()`, isolamento dello storage di throttling dallo Store di Roots e separazione dei bucket. (Vedi **R-004**, **R-011**). |
| **AS-05: Emergenza "Closed today" dopo la mezzanotte**       | Locale con orario Sabato `20:00–02:00`. Orologio: Domenica `00:20`.                     | 1. Il Cliente vuole chiudere subito il locale.<br>2. Apre la PWA e tocca "Closed today".<br>3. Viene creata una Closure per la data locale di oggi (Domenica).                                                                                                  | Poiché lo spill `00:00–02:00` appartiene al Sabato e "survives a Closure on the next day" (§4.1), il sito continua a dire **"Aperto fino alle 02:00"**.                                                                                | Gestione esplicita degli spill attivi quando si invoca "Closed today" dopo la mezzanotte. (Vedi **R-005**).                                                 |
| **AS-06: Chiusura e riapertura nello stesso giorno**         | Negozio normalmente aperto il 18 ottobre.                                               | 1. Alle `09:00` del 18 ottobre il Cliente crea una Closure "until further notice" (`start = 2026-10-18`, `end = null`).<br>2. Alle `15:00` dello stesso giorno tocca "We're open again".                                                                        | Il sistema imposta `end = yesterday` (`2026-10-17`), creando un record invalido con `end < start`.                                                                                                                                     | Cancellazione dell'Exception (anziché `end = yesterday`) quando `start >= today`. (Vedi **R-007**).                                                         |

## 6. Matrice dei requisiti mancanti

| **Area interessata**                                    | **Informazione mancante**                                                                                           | **Perché è necessaria**                                                                                                                         | **Rischio derivante dall'assenza**                                                                                                    | **Domanda da porre allo stakeholder**                                                                                                        | **Criterio con cui verificare la risposta**                                                                                     |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| **Indispensabile** — Dominio Orario (§4.1, §4.6)        | Formato e vincoli della `Schedule key` (caratteri ammessi, lunghezza massima, case-sensitivity).                    | È usata come ID documento nello Store, parametro URL in `view.php` e attributo HTML nello snippet.                                              | Caratteri speciali, spazi o maiuscole possono causare problemi di URL encoding, mismatch case-sensitive tra SQLite/PHP o ID invalidi. | La `Schedule key` segue lo stesso pattern `[a-z][a-z0-9-]*` (es. max 64 char) dei Package ID?                                                | Test di validazione che rifiuta Schedule key fuori dal regex stabilito.                                                         |
| **Indispensabile** — Store API (§3.3)                   | Semantica di ordinamento e filtro per i campi con valore `null` in `Store::list()`.                                 | SQLite e PHP gestiscono `null` nei confronti (`<`, `>`, `ORDER BY`) in modo differente per impostazione predefinita.                            | Il test di contratto (§7.1) o le query di `in.hours` su `exceptions.end` produrranno risultati diversi tra SQLite e flat-file.        | Come devono comportarsi i filtri di range (`<`, `>`) e l'`ORDER BY` quando il campo dichiarato vale `null`?                                  | Test nella Store contract suite con record aventi campi `null`.                                                                 |
| **Indispensabile** — Dominio Orario (§4.4)              | Regola di proiezione dell'anno (`YYYY`) per `Seasons` ed `Exceptions` annuali (`MM-DD`) nella vista `jsonld`.       | Schema.org `validFrom` / `validThrough` richiede date complete `YYYY-MM-DD`.                                                                    | Generazione di JSON-LD sintatticamente invalido (`MM-DD`) o con `validThrough < validFrom` a cavallo di capodanno.                    | Su quale finestra temporale (es. `[today, today + 1 year)`) devono essere proiettate le ricorrenze annuali in `jsonld`?                      | Validazione automatica di tutti i `validFrom`/`validThrough` emessi da `jsonld` contro il formato ISO `YYYY-MM-DD`.             |
| **Differibile** — Sicurezza / Operatività (§3.6, §3.13) | Limite massimo di sessioni attive concorrenti nello Store per Client e Developer, e pulizia delle sessioni scadute. | Senza pruning delle sessioni scadute o limite massimo, lo Store accumula token hash indefinitamente.                                            | Crescita progressiva dello Store di Roots o gonfiamento dopo ripetuti login/test.                                                     | Quando vengono rimosse dallo Store le sessioni scadute (es. a ogni login) e qual è il numero massimo di sessioni attive consentite (es. 20)? | Test che verifica la rimozione dei token scaduti durante il flusso di sign-in.                                                  |
| **Differibile** — Asset serving (§3.5, §3.9, §3.13)     | Dove viene salvata e come viene servita l'icona custom della PWA (PNG ≥ 512px) caricata dal Developer.              | `asset.php` serve solo file statici da `assets/`, mentre l'icona PWA è caricata a runtime dal Developer e salvata nella Data directory / Store. | Se la Data directory è bloccata da HTTP, il browser non può scaricare l'icona della PWA a meno che un endpoint la serva.              | L'icona custom della PWA viene servita da `asset.php` (tramite un caso speciale) o da `app/index.php`?                                       | Test di caricamento di un'icona PNG custom 512x512 e verifica che l'URL nel Web App Manifest risponda `200 OK` con `image/png`. |

## 7. Alternative progettuali

### 7.1 Granularità del backend Flat-File: File per-Collection vs File per-Store

- **Problema affrontato:** Garantire il rispetto del contratto `Store::transaction(callable)` (atomicità, isolamento, rollback e incremento della `Store revision`) nel backend flat-file (§3.2, §3.3, vedi **R-002**).

- **Soluzione attuale:** Un file (e un lock file) separato per ogni collection, con read-modify-write sotto `flock` e `rename` atomico per-collection.

- **Alternativa proposta:** Un singolo file PHP/JSON (e un singolo lock file) per l'intero **Store di un Modulo** (contenente `schema`, `revision` e la mappa `collections`).

- **Vantaggi e svantaggi:**
  
   - *Soluzione attuale:* Impossibile fare commit o rollback atomico su due collection (`schedules` + `exceptions`) con `rename` di singoli file senza implementare un complesso Write-Ahead Log (WAL) custom in PHP; rischio di deadlock se due transazioni acquisiscono i lock delle collection in ordine inverso.
  
   - *Alternativa proposta:* Atomicità, isolamento, incremento di `revision` e rollback su tutte le collection del modulo diventano banali (un solo `flock`, modifiche in-memory, un solo `rename` finale sul file dello Store). Lo svantaggio teorico è leggere/scrivere tutte le collection del modulo insieme, ma per i volumi di Innesto (1–10 Schedule e poche decine di Exceptions, ossia < 20 KB di JSON totale) il costo in PHP è inferiore a 0,5 millisecondi.

- **Impatto su complessità, sicurezza, manutenzione e costi:** Riduce drasticamente la complessità del codice del backend flat-file, elimina una classe intera di bug di concorrenza e garantisce al 100% il superamento della *Store contract suite* (§7.1).

- **Condizioni che giustificano la scelta:** Giustificata incondizionatamente per i volumi di dati di siti piccoli/medi previsti dal progetto.

### 7.2 Gestione della sessione scaduta nella PWA: Refill da `sessionStorage` dopo reload vs PIN Modal in-place

- **Problema affrontato:** Evitare che il Cliente perda i dati inseriti in un form quando preme "Save" dopo che la sessione è scaduta (§3.13, vedi **R-010**).

- **Soluzione attuale:** Salvare i campi del form in `sessionStorage` prima del `fetch`, mostrare il PIN pad, ricaricare/tornare alla schermata e ripopolare i campi da `sessionStorage`.

- **Alternativa proposta:** Poiché il submit avviene già via `fetch` (§3.13), intercettare la risposta di sessione scaduta mostrando il PIN pad come un layer/dialogo sopra il form corrente senza distruggere il DOM della pagina; una volta autenticato via `fetch` (che aggiorna anche il token CSRF), chiudere il layer e lasciare il form già compilato pronto per il secondo tap su "Save". Mantenere `sessionStorage` solo come rete di sicurezza se il tab viene ricaricato dal browser mobile.

- **Vantaggi e svantaggi:**
  
   - *Soluzione attuale:* Fragile sui form con righe dinamiche aggiunte/rimosse lato client (come `Regular hours` con più periodi e stagioni).
  
   - *Alternativa proposta:* Non tocca il DOM del form, preserva perfettamente qualsiasi riga aggiunta o stato di validazione, ed è molto più veloce su connessioni mobili lente.

- **Impatto su complessità, sicurezza, manutenzione e costi:** Minore complessità JavaScript (niente serializzazione/ricostruzione di liste dinamiche nel DOM) e migliore UX mobile.

- **Condizioni che giustificano la scelta:** Giustificata dal fatto che §3.13 usa già `fetch` per l'invio dei form.

## 8. Piano di mitigazione prioritizzato

### Fase 1 — Prima dell'approvazione delle specifiche (Bloccanti P0)

1. **Risoluzione R-001 (Probe HTTP e Flat-File):** Aggiornare §3.2 e §3.11 distinguendo la probe su file `.php` protetto (il cui fallimento è `Fail`) dalla probe su file non-`.php` (il cui fallimento è `Warn` e forza l'uso del backend flat-file con estensione `.php` e guard `<?php exit; ?>` per tutti i file nella Data directory).

2. **Risoluzione R-002 (Transazioni Flat-File):** Aggiornare §3.2 specificando che il backend flat-file persiste ogni Store in un singolo file protetto con un singolo lock file per Store, eseguendo `transaction(callable)` interamente in memoria prima di un unico `rename` atomico.

3. **Risoluzione R-003 (Invariante Status Timeline a mezzanotte):** Aggiornare §4.3 specificando che il testo localizzato di ciascun segmento viene valutato rispetto alla data locale dell'istante `from` di quel segmento (e non rispetto a `now` del render), e includere la data locale della Schedule nell'`ETag` (§3.9).

4. **Risoluzione R-004 e R-011 (Throttling e separazione dello stato runtime):** Aggiornare §3.4 e §3.6 stabilendo che il throttling risponde immediatamente con HTTP `429` (zero `sleep()`), che i bucket `dev/` e `app/` sono separati, e che le scritture di stato operativo (throttling, sliding session expiry, self-check, contatori errori) non incrementano la `Store revision` di Roots usata per l'`ETag` di `view.php`.

### Fase 2 — Prima dell'implementazione delle funzionalità coinvolte (Priorità P1)

5. **Risoluzione R-005 (Spill post-mezzanotte e "Closed today"):** Definire in §4.1 e §4.7 il comportamento di "Closed today" quando è attivo uno spill iniziato il giorno precedente.

6. **Risoluzione R-006 (Bootstrap di `data-path.php`):** Evitare mutazioni irreversibili sulla prima richiesta `GET` non autenticata e gestire il caso in cui `<roots>/` sia in sola lettura ma `../innesto-data/` sia scrivibile.

7. **Risoluzione R-007 ("We're open again" nello stesso giorno):** Specificare in §4.7 che se `start >= today` l'azione elimina l'Exception anziché impostare `end = yesterday`.

8. **Risoluzione R-008 (Filtri Store e campi data di `exceptions`):** Definire la semantica di `null` in `Store::list()` (§3.3) ed evitare di mescolare `YYYY-MM-DD`, `MM-DD` e `null` nello stesso campo filtrato con operatori di range (§4.2).

9. **Risoluzione R-009 (Parametro `lang` negli snippet):** Includere sempre esplicitamente `lang` nello snippet generato in §3.10.

### Fase 3 — Prima del rilascio in produzione (Priorità P2)

10. **Risoluzione R-010 (Sessione scaduta nella PWA):** Gestire il re-login PIN in-place senza distruggere il DOM del form.

11. **Risoluzione R-012 (Proiezione date in `jsonld`):** Formalizzare la proiezione in `YYYY-MM-DD` per le Seasons ed Exceptions annuali in §4.4.

12. **Risoluzione R-013 e R-014 (Lock del log e merge di `class` nel Web Component):** Aggiungere `flock` alla scrittura/rotazione del log (§3.12) e l'unione di `classList` nello swap del Web Component (§3.8).

## 9. Test e criteri di accettazione aggiuntivi

Oltre ai 6 test previsti in §7, i seguenti test concreti devono essere aggiunti per verificare la risoluzione dei rischi critici identificati:

1. **Test di Setup su host senza `.htaccess` e senza directory fuori webroot (Verifica R-001):**
   
    - *Dato* un container con `AllowOverride None` (o `.htaccess` disabilitato) e permessi di scrittura solo dentro `<roots>/`.
   
    - *Quando* il Developer esegue il flusso di setup in `dev/`.
   
    - *Allora* la probe sul file non-PHP deve riportare un **Warn** ("`.htaccess` not honoured / Data directory inside the webroot"), la probe sul file `.php` protetto deve avere successo (0 byte restituiti via HTTP), il backend selezionato deve essere obbligatoriamente **Guarded flat files**, e il setup deve completarsi senza `Fail`.

2. **Test di Rollback Transazionale Multi-Collection su Flat-File (Verifica R-002):**
   
    - *Dato* uno Store inizializzato sul backend flat-file con `revision = 1`, un documento in `schedules` e un documento in `exceptions`.
   
    - *Quando* viene invocata `store->transaction(...)` che modifica il documento in `schedules`, cancella il documento in `exceptions` e poi lancia una `RuntimeException`.
   
    - *Allora* l'eccezione deve propagarsi, ma leggendo lo Store da un nuovo processo sia `schedules`, sia `exceptions`, sia `revision` devono essere rimasti identici allo stato pre-transazione (`revision = 1`).

3. **Test di Invarianza dei Segmenti Post-Mezzanotte sotto Cache (Verifica R-003):**
   
    - *Dato* l'orologio di contesto fissato a Lunedì `23:45` nella timezone della Schedule, con il negozio chiuso che riapre Martedì alle `09:00` e poi Mercoledì alle `09:00`.
   
    - *Quando* viene calcolata la `Status timeline` in quella singola chiamata.
   
    - *Allora* il primo segmento (`23:45–00:00` di Lunedì) deve avere testo `"Closed · reopens tomorrow at 9:00"`, mentre il secondo segmento (`00:00–09:00` di Martedì) **nello stesso payload JSON** deve avere testo `"Closed · opens at 9:00"` (e non `"reopens tomorrow"`).

4. **Test di Isolamento tra Throttling/Sessioni ed ETag Pubblico (Verifica R-004, R-011):**
   
    - *Dato* `in.hours` attivo e una chiamata iniziale a `view.php` che restituisce `ETag: "W1"`.
   
    - *Quando* vengono inviati 10 tentativi di login falliti a `app/` e il Cliente effettua 5 navigazioni autenticate in `app/` e il Developer apre `dev/` (eseguendo i server-side probes del Self-check).
   
    - *Allora* i tentativi di login oltre il quinto devono rispondere immediatamente con HTTP `429` (tempo di risposta < 50 ms, zero `sleep()`), e una successiva chiamata a `view.php` con `If-None-Match: "W1"` deve restituire `304 Not Modified`.

5. **Test di Chiusura e Riapertura nello Stesso Giorno (Verifica R-007):**
   
    - *Dato* l'orologio fissato a `2026-10-18T09:00:00` e una Closure "until further notice" creata oggi (`start = 2026-10-18`, `end = null`).
   
    - *Quando* alle `2026-10-18T14:00:00` il Cliente preme "We're open again".
   
    - *Allora* l'operazione deve avere successo, lo stato deve tornare `Open`, e nello Store non deve esistere alcuna Exception con `end < start`.

## 10. Domande aperte per gli stakeholder

1. **Comportamento di "Closed today" durante un turno notturno iniziato ieri (R-005 — Impatto Alto):**
   
    - *Decisione dipendente:* Logica dell'azione one-tap "Closed today" in §4.7 e regole di precedenza sugli spill in §4.1.
   
    - *Alternative possibili:* (A) Se premuto alle `00:30` durante uno spill `20:00–02:00` iniziato ieri, chiude immediatamente anche il turno in corso (oltre all'intera giornata di oggi); (B) Lascia aperto il locale fino alle `02:00` e chiude solo i turni che iniziano oggi (mostrando però un avviso esplicito all'utente).
   
    - *Rischio nel procedere senza chiarimento:* Un gestore di locale notturno preme "Chiuso oggi" dopo la mezzanotte e il sito continua a dichiarare che il locale è aperto.

2. **Concretizzazione delle date annuali (`MM-DD`) in `jsonld` (R-012 — Impatto Medio):**
   
    - *Decisione dipendente:* Generazione delle proprietà `validFrom` e `validThrough` nella vista `jsonld` (§4.4).
   
    - *Alternative possibili:* (A) Proiettare le `Seasons` e le `Exceptions` annuali sui prossimi 365 giorni a partire dalla data di render; (B) Proiettarle solo sull'orizzonte di 14 giorni (come fa la vista `week`).
   
    - *Rischio nel procedere senza chiarimento:* Emissione di date incomplete `MM-DD` (rifiutate da Google Schema.org) o intervalli invertiti a cavallo di capodanno.

3. **Serving dell'icona PNG custom della PWA (Matrice §6 — Impatto Medio):**
   
    - *Decisione dipendente:* Dove salvare e da quale endpoint servire l'icona quadrata ≥ 512px caricata dal Developer (§3.5, §3.13).
   
    - *Alternative possibili:* (A) Salvarla nella Data directory e servirla tramite `asset.php?module=roots&file=pwa-icon.png` (aggiungendo un'eccezione controllata in `asset.php`); (B) Servirla tramite `app/index.php?icon=1`.
   
    - *Rischio nel procedere senza chiarimento:* Impossibilità per il browser mobile di caricare l'icona del manifest se salvata nella Data directory (che è bloccata da accesso HTTP diretto).

## 11. Valutazione conclusiva

- **Giudizio finale sulla maturità delle specifiche:** Il documento è ben scritto, chiaro nei confini di scopo (§9) e solido nella filosofia progettuale, ma presenta **4 difetti architetturali bloccanti (R-001, R-002, R-003, R-004)** che emergono dall'interazione tra requisiti singolarmente ragionevoli.

- **Criticità che impediscono di procedere:**
  
   - **R-001:** Il blocco del setup (`Fail`) sulla probe HTTP nella Data directory impedisce il funzionamento del fallback a flat-file sugli host in-webroot che ignorano `.htaccess`.
  
   - **R-002:** Il locking/salvataggio per-collection nel backend flat-file non può garantire transazioni e rollback multi-collection.
  
   - **R-003:** Il calcolo delle stringhe relative della Status timeline senza ancoraggio a `segment.from` produce testi errati ("riapre domani" anziché "apre oggi") dopo la mezzanotte quando la risposta è servita dalla cache HTTP.
  
   - **R-004 / R-011:** Il throttling e le sessioni sliding dentro `Roots' own Store` causano DoS sui worker PHP (se implementati con attesa sincrona) e invalidano continuamente l'`ETag` di tutte le viste pubbliche.

- **Rischi residui che possono essere accettati consapevolmente:**
  
   - L'assenza di migrazioni automatiche dello schema e di aggiornamenti in-place (dichiarata esplicitamente fuori scopo in §9 per il *tracer bullet*).
  
   - La codifica Schema.org per le chiusure `until further notice` (`validFrom` senza `validThrough`), già correttamente documentata come non verificata ufficialmente da Google in §4.4.
  
   - La latenza di propagazione fino a `N` minuti (`max-age`) dopo una modifica del Cliente, già gestita a livello di UX tramite il messaggio di conferma in §3.13 e §4.7.

- **Modifiche indispensabili e condizioni minime per una nuova approvazione:** Integrare nel documento `2026-10-17-tracer-bullet-design.md` le mitigazioni della **Fase 1 (R-001..R-004)** e della **Fase 2 (R-005..R-009)** del piano di mitigazione (§8) e aggiungere i 5 test di verifica corrispondenti (§9) alla sezione §7 del documento. Una volta recepite queste correzioni puntuali, la specifica sarà pronta per l'implementazione.

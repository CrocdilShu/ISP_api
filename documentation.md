# Kalender-API - Användarguide

Den här guiden är till för utvecklare som vill skapa händelser, bjuda in kollegor, svara på inbjudningar och hantera förslag via kalender-API:et. För att använda API:et behöver du en användartoken från `loginUser` (se guiden för användar-API:et).

> **Om exemplen:** värden inom vinkelparenteser, till exempel `<event_id>`, är platshållare. Byt ut dem, inklusive parenteserna, mot dina egna värden. De är listade i tabellen nedan.

| Platshållare | Vad du ska skriva dit |
|---|---|
| `<user_token>` | Den `user_token` du fick från `loginUser` |
| `<event_id>` | Numret på en händelse (`ID` i svaret från `getEvents`) |
| `<title>` | Titeln på en händelse |
| `<creator_email>` | E-postadressen till den medarbetare som äger händelsen |
| `<employee_id>` | ID för en medarbetare |
| `<employee_email>` | E-postadressen till en medarbetare |
| `<invite_id>` | Numret på en inbjudan (`invite_id` i svaret från `getInvites`) |
| `<suggestion_id>` | Numret på ett förslag (`suggestion_id` i svaret från `getSuggestions`) |
| `<start_time>`, `<end_time>` | Ett datum och en tid i formatet `YYYY-MM-DD HH:MM:SS` |
| `<text>` | Din egen text |

---

## Översikt

Alla anrop går till en och samma URL. Du väljer vad som ska göras med `?action=` i URL:en.

**Endpoint:** `https://host/services/calender/calender.php?action=ACTION_NAME`

| # | Action | Metod | Vad den gör | Vem får göra det |
|---|---|---|---|---|
| 1 | `getEvents` | POST | Listar händelser | Alla inloggade medarbetare |
| 2 | `createNewEvent` | POST | Skapar en händelse | Alla inloggade medarbetare (blir ägare) |
| 3 | `editEvent` | PUT eller POST | Ändrar en händelse | Ägaren eller RootAdmin |
| 4 | `deleteEvent` | DELETE eller POST | Tar bort en händelse | Ägaren, Admin eller RootAdmin |
| 5 | `getParticipants` | GET eller POST | Listar deltagarna i en händelse | Alla inloggade medarbetare |
| 6 | `addParticipants` | POST | Skickar inbjudningar till en händelse | Ägaren |
| 7 | `getInvites` | GET eller POST | Listar dina väntande inbjudningar | Alla inloggade medarbetare |
| 8 | `respondToInvite` | POST eller PUT | Tackar ja eller nej till en inbjudan | Den inbjudna medarbetaren |
| 9 | `editParticipants` | PUT eller POST | Ersätter eller uppdaterar deltagarlistan via inbjudningar | Ägaren |
| 10 | `getSuggestions` | GET | Listar förslagen på en händelse | Alla inloggade medarbetare |
| 11 | `createSuggestion` | POST | Skriver ett förslag (`addSuggestion` fungerar också) | Deltagare i händelsen |
| 12 | `editSuggestion` | PUT eller POST | Ändrar ett förslag | Alla inloggade medarbetare |
| 13 | `deleteSuggestion` | DELETE eller POST | Tar bort ett förslag | Författaren eller ägaren av händelsen |

Alla svar är JSON. Datum och tider använder alltid formatet `YYYY-MM-DD HH:MM:SS`.

---

## Autentisering

Alla anrop i Kalender-API:et kräver autentisering.

### Företagstoken (hanteras av servern)

Kalendern ber dig inte längre om företagsnyckeln. Vid varje anrop läser servern företagets token från tabellen `root_user` (raden vars ID är `COMPANY_ID`) och frågar leverantören om den fortfarande är giltig och omfattar kalendertjänsten. Om inte misslyckas varje anrop med:

```json
{
  "error": "Company session token is invalid or expired. Please run rootLogin."
}
```

Åtgärd: kör `rootLogin` (i användar-API:et) en gång. Varje kalenderanrop förnyar token i ytterligare 30 minuter, så den går bara ut efter 30 minuter utan några anrop.

### Användartoken (den du skickar)

Medarbetare loggar in med `loginUser` (användar-API:et) och får en `user_token`. Skicka den i följande header vid varje anrop som kräver inloggning:

| Header | Vad det är | Var du får den |
|---|---|---|
| `X-User-Token` | Medarbetarens egen token | `user_token` från `loginUser` |

Token förnyas i 30 minuter varje gång den används. API:et använder den för att veta **vem** som anropar: den som skapar en händelse blir ägare, och behörigheterna kontrolleras mot den medarbetaren (samt roller som Admin / RootAdmin).

**Anrop som kräver användartoken:** Alla anrop (`getEvents`, `createNewEvent`, `editEvent`, `deleteEvent`, `getParticipants`, `addParticipants`, `getInvites`, `respondToInvite`, `editParticipants`, `getSuggestions`, `createSuggestion`, `editSuggestion`, `deleteSuggestion`).

**Om användartoken saknas, är fel eller har gått ut:**
```json
{
  "error": "Unauthorized or invalid user token"
}
```
(Statuskoden är alltid `401`.)

### Uppsättning som används i exemplen

**cURL:** exemplen läser token från en variabel.
```bash
export USER_TOKEN="<user_token>"
```

**JavaScript:** exemplen använder den här lilla hjälpfunktionen.
```javascript
const BASE = 'https://host/services/calender/calender.php';
const userToken = '<user_token>'; // från loginUser

async function calendar(action, { method = 'GET', params = {}, body } = {}) {
  const query = new URLSearchParams({ action, ...params });
  const res = await fetch(`${BASE}?${query}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-User-Token': userToken
    },
    body: body ? JSON.stringify(body) : undefined
  });
  return res.json();
}
```

---

## 🎯 Peka ut en händelse

De flesta anrop gäller en händelse. Du pekar ut den på ett av två sätt:

| Sätt | Vad du skickar |
|---|---|
| Med nummer | `event_id` |
| Med namn och ägare | `title` och `creator_email` (e-postadressen till den medarbetare som äger händelsen) |

Var du skickar det beror på anropet: i **URL:en** för GET-anrop (`&event_id=<event_id>` eller `&title=<title>&creator_email=<creator_email>`), och i **JSON-bodyn** för övriga metoder. `deleteEvent` och `deleteSuggestion` använder bodyn om det finns en, annars URL:en.

Om händelsen inte hittas misslyckas anropet med ett `400`- eller `404`-fel.

---

## 📅 Händelser

### 1. Lista händelser - POST getEvents

Returnerar företagets händelser, tidigaste först. **Kräver inloggning.** Anropet är en **POST** med en JSON-body, och bodyn får vara tom.

**Endpoint:** `POST https://host/services/calender/calender.php?action=getEvents`

**Valfria fält i bodyn:**

| Fält | Vad det gör |
|---|---|
| `user_id` | Bara händelser som den här medarbetaren deltar i (godkända/accepterade deltagare) |
| `from` | Bara händelser som slutar efter den här tiden (återkommande händelser finns kvar tills deras regel upphör) |
| `to` | Bara händelser som börjar före den här tiden |

Ett `from` eller `to` i fel format ignoreras, utan felmeddelande.

**Exempel med cURL:**
```bash
curl -X POST "https://host/services/calender/calender.php?action=getEvents" \
  -H "Content-Type: application/json" \
  -H "X-User-Token: $USER_TOKEN" \
  -d '{"user_id":<employee_id>,"from":"<start_time>","to":"<end_time>"}'
```

**Exempel med JavaScript/Fetch:**
```javascript
const data = await calendar('getEvents', {
  method: 'POST',
  body: { user_id: employeeId, from: fromTime, to: toTime }
});
console.log(data.events);
```

**Svar:**
```json
{
  "events": [
    {
      "ID": <event_id>,
      "start_time": "<start_time>",
      "end_time": "<end_time>",
      "created_at": "<created_at>",
      "FORrecurrence": <recurrence_id or null>,
      "title": "<title>",
      "content": <content or null>,
      "owner_id": <owner_id>,
      "owner_username": "<username>",
      "frequency": "<frequency or null>",
      "interval_step": <interval_step or null>,
      "recurrence_end_date": "<end_date or null>"
    }
  ]
}
```

---

### 2. Skapa en händelse - POST createNewEvent

Den inloggade medarbetaren blir ägare och sätts som automatisk deltagare. Eventuella medarbetare i `participants` får en **inbjudan** (`pending`).

**Endpoint:** `POST https://host/services/calender/calender.php?action=createNewEvent`

**Det du skickar:**
```json
{
  "title": "<title>",
  "content": { "<key>": "<value>" },
  "start_time": "<start_time>",
  "end_time": "<end_time>",
  "recurrence": { "frequency": "<frequency>", "interval_step": <interval_step>, "end_date": "<end_date>" },
  "participants": [<employee_id>, "<employee_email>"]
}
```

| Fält | Obligatoriskt | Beskrivning |
|---|---|---|
| `title` | Ja | Upp till 255 tecken |
| `start_time`, `end_time` | Ja | `end_time` måste vara efter `start_time` |
| `content` | Nej | Valfri JSON, till exempel en beskrivning |
| `recurrence` | Nej | Gör att händelsen återkommer |
| `participants` | Nej | Medarbetare som ska få en inbjudan (via ID eller e-postadress) |

> **Obs:** Alla användare som skickas i `participants` får en inbjudan i läget `pending`. De blir inte fullvärdiga deltagare förrän de svarar ja via `respondToInvite`.

**Exempel med cURL:**
```bash
curl -X POST "https://host/services/calender/calender.php?action=createNewEvent" \
  -H "Content-Type: application/json" \
  -H "X-User-Token: $USER_TOKEN" \
  -d '{"title":"<title>","start_time":"<start_time>","end_time":"<end_time>"}'
```

**Exempel med JavaScript/Fetch:**
```javascript
const result = await calendar('createNewEvent', {
  method: 'POST',
  body: { title, start_time: startTime, end_time: endTime, participants }
});
console.log(result.event_id);
```

**Svar (201):**
```json
{
  "message": "Event created successfully",
  "event_id": <event_id>
}
```

---

### 3. Ändra en händelse - PUT editEvent

Endast ägaren samt **RootAdmin** har behörighet att ändra en händelse. Peka ut händelsen (se [Peka ut en händelse](#-peka-ut-en-händelse)) och skicka bara de fält du vill ändra.

**Endpoint:** `PUT https://host/services/calender/calender.php?action=editEvent`

**Det du skickar:**
```json
{
  "event_id": <event_id>,
  "title": "<title>",
  "start_time": "<start_time>",
  "end_time": "<end_time>"
}
```

Du kan ändra `title`, `content`, `start_time`, `end_time` och `recurrence`. Fält du utelämnar förblir som de är. Två specialvärden:

- `"recurrence": null` gör att händelsen slutar återkomma.
- `"content": null` rensar beskrivningen.

**Exempel med cURL:**
```bash
curl -X PUT "https://host/services/calender/calender.php?action=editEvent" \
  -H "Content-Type: application/json" \
  -H "X-User-Token: $USER_TOKEN" \
  -d '{"event_id":<event_id>,"start_time":"<start_time>","end_time":"<end_time>"}'
```

**Exempel med JavaScript/Fetch:**
```javascript
await calendar('editEvent', {
  method: 'PUT',
  body: { event_id: eventId, start_time: startTime, end_time: endTime }
});
```

**Svar:**
```json
{
  "message": "Event updated successfully"
}
```

---

### 4. Ta bort en händelse - DELETE deleteEvent

Ägaren, `admin` eller `root_admin` kan ta bort en händelse. Det tar också bort händelsens deltagare, inbjudningar och förslag.

**Endpoint:** `DELETE https://host/services/calender/calender.php?action=deleteEvent&event_id=<event_id>`

Du kan också skicka `event_id` (eller `title` och `creator_email`) i en JSON-body.

**Exempel med cURL:**
```bash
curl -X DELETE "https://host/services/calender/calender.php?action=deleteEvent&event_id=<event_id>" \
  -H "X-User-Token: $USER_TOKEN"
```

**Exempel med JavaScript/Fetch:**
```javascript
await calendar('deleteEvent', { method: 'DELETE', params: { event_id: eventId } });
```

**Svar:**
```json
{
  "message": "Event deleted successfully"
}
```

---

## 👥 Deltagare och inbjudningar

Alla deltagare läggs till via inbjudningsflödet (`pending` → `accepted`/`declined`).

### 5. Se vem som deltar - GET getParticipants

Returnerar de godkända deltagarna i händelsen. **Kräver inloggning.** POST fungerar också, med händelsen i bodyn.

**Endpoint:** `GET https://host/services/calender/calender.php?action=getParticipants&event_id=<event_id>`

**Exempel med cURL:**
```bash
curl "https://host/services/calender/calender.php?action=getParticipants&event_id=<event_id>" \
  -H "X-User-Token: $USER_TOKEN"
```

**Exempel med JavaScript/Fetch:**
```javascript
const data = await calendar('getParticipants', { params: { event_id: eventId } });
console.log(data.participants);
```

**Svar:**
```json
{
  "participants": [
    {
      "participant_id": <participant_id>,
      "user_id": <employee_id>,
      "username": "<username>",
      "email": "<employee_email>",
      "pfp_file": "<file or null>"
    }
  ]
}
```

---

### 6. Skicka inbjudningar - POST addParticipants

Endast ägaren. Skapar en **väntande** inbjudan för varje medarbetare och returnerar en lista med de inbjudna användarnas **e-postadresser**.

**Endpoint:** `POST https://host/services/calender/calender.php?action=addParticipants`

**Det du skickar:**
```json
{
  "event_id": <event_id>,
  "participants": [<employee_id>, "<employee_email>"]
}
```
*(Du kan också identifiera händelsen via `"title": "<title>"` och `"creator_email": "<creator_email>"` i bodyn)*

**Exempel med cURL:**
```bash
curl -X POST "https://host/services/calender/calender.php?action=addParticipants" \
  -H "Content-Type: application/json" \
  -H "X-User-Token: $USER_TOKEN" \
  -d '{"event_id":<event_id>,"participants":["<employee_email>"]}'
```

**Exempel med JavaScript/Fetch:**
```javascript
const result = await calendar('addParticipants', {
  method: 'POST',
  body: { event_id: eventId, participants }
});
console.log(result.invited_emails);
```

**Svar:**
```json
{
  "message": "Invitations sent successfully",
  "invited_emails": [
    "user1@company.com",
    "user2@company.com"
  ]
}
```

---

### 7. Se dina inbjudningar - GET getInvites

Returnerar den inloggade medarbetarens **väntande** inbjudningar. Innehåller titeln på händelsen (`event_title`).

**Endpoint:** `GET https://host/services/calender/calender.php?action=getInvites`

**Exempel med cURL:**
```bash
curl "https://host/services/calender/calender.php?action=getInvites" \
  -H "X-User-Token: $USER_TOKEN"
```

**Exempel med JavaScript/Fetch:**
```javascript
const data = await calendar('getInvites');
console.log(data.invites);
```

**Svar:**
```json
{
  "invites": [
    {
      "invite_id": <invite_id>,
      "event_id": <event_id>,
      "status": "pending",
      "created_at": "<created_at>",
      "event_title": "<title>",
      "event_content": <content or null>,
      "start_time": "<start_time>",
      "end_time": "<end_time>",
      "owner_id": <owner_id>,
      "owner_username": "<username>",
      "owner_email": "<creator_email>"
    }
  ]
}
```

---

### 8. Tacka ja eller nej - POST respondToInvite

Endast den medarbetare som inbjudan skickades till kan svara på den.

**Endpoint:** `POST https://host/services/calender/calender.php?action=respondToInvite`

**Det du skickar:**
```json
{
  "invite_id": <invite_id>,
  "action": "accept"
}
```

`action` är `accept` eller `decline`. (`accepted`, `declined` och fältnamnet `status` fungerar också.)

- **Tacka ja:** din status ändras till `accepted` och du blir officiell deltagare i händelsen.
- **Tacka nej:** inbjudan markeras som `declined`, och du tas bort från deltagarlistan om du var med.

**Exempel med cURL:**
```bash
curl -X POST "https://host/services/calender/calender.php?action=respondToInvite" \
  -H "Content-Type: application/json" \
  -H "X-User-Token: $USER_TOKEN" \
  -d '{"invite_id":<invite_id>,"action":"accept"}'
```

**Exempel med JavaScript/Fetch:**
```javascript
await calendar('respondToInvite', {
  method: 'POST',
  body: { invite_id: inviteId, action: 'accept' }
});
```

**Svar:**
```json
{
  "message": "Invitation accepted successfully",
  "invite_id": <invite_id>,
  "event_id": <event_id>,
  "status": "accepted"
}
```

---

### 9. Ändra deltagare - PUT editParticipants

Endast ägaren. Uppdaterar vilka som blir inbjudna/deltagare till händelsen via inbjudningar. Kan identifiera händelsen via `event_id` eller kombinationen `title` och `creator_email`.

**Endpoint:** `PUT https://host/services/calender/calender.php?action=editParticipants`

**Det du skickar:**
```json
{
  "title": "<title>",
  "creator_email": "<creator_email>",
  "participants": [<employee_id>, "<employee_email>"]
}
```

**Exempel med cURL:**
```bash
curl -X PUT "https://host/services/calender/calender.php?action=editParticipants" \
  -H "Content-Type: application/json" \
  -H "X-User-Token: $USER_TOKEN" \
  -d '{"title":"<title>","creator_email":"<creator_email>","participants":["<employee_email>"]}'
```

**Exempel med JavaScript/Fetch:**
```javascript
await calendar('editParticipants', {
  method: 'PUT',
  body: { title: '<title>', creator_email: '<creator_email>', participants }
});
```

**Svar:**
```json
{
  "message": "Participants updated successfully"
}
```

---

## 💬 Förslag

Förslag är korta kommentarer från deltagare på en händelse.

### 10. Läs förslag - GET getSuggestions

**Kräver inloggning.**

**Endpoint:** `GET https://host/services/calender/calender.php?action=getSuggestions&event_id=<event_id>`

**Exempel med cURL:**
```bash
curl "https://host/services/calender/calender.php?action=getSuggestions&event_id=<event_id>" \
  -H "X-User-Token: $USER_TOKEN"
```

**Exempel med JavaScript/Fetch:**
```javascript
const data = await calendar('getSuggestions', { params: { event_id: eventId } });
console.log(data.suggestions);
```

**Svar (äldst först):**
```json
{
  "suggestions": [
    {
      "suggestion_id": <suggestion_id>,
      "created_at": "<created_at>",
      "last_update": "<last_update>",
      "user_id": <employee_id>,
      "username": "<username>",
      "pfp_file": "<file or null>",
      "text": "<text>"
    }
  ]
}
```

---

### 11. Skriv ett förslag - POST createSuggestion

Endast deltagare (ägaren räknas som en). Det gamla namnet `addSuggestion` fungerar också.

**Endpoint:** `POST https://host/services/calender/calender.php?action=createSuggestion`

**Det du skickar:**
```json
{
  "event_id": <event_id>,
  "text": "<text>"
}
```

**Exempel med cURL:**
```bash
curl -X POST "https://host/services/calender/calender.php?action=createSuggestion" \
  -H "Content-Type: application/json" \
  -H "X-User-Token: $USER_TOKEN" \
  -d '{"event_id":<event_id>,"text":"<text>"}'
```

**Exempel med JavaScript/Fetch:**
```javascript
const result = await calendar('createSuggestion', {
  method: 'POST',
  body: { event_id: eventId, text }
});
console.log(result.suggestion_id);
```

**Svar (201):**
```json
{
  "message": "Suggestion added successfully",
  "suggestion_id": <suggestion_id>
}
```

---

### 12. Ändra ett förslag - PUT editSuggestion

Du måste vara inloggad. Du måste skicka både förslaget **och** händelsen det tillhör.

**Endpoint:** `PUT https://host/services/calender/calender.php?action=editSuggestion`

**Det du skickar:**
```json
{
  "suggestion_id": <suggestion_id>,
  "event_id": <event_id>,
  "text": "<text>"
}
```

**Exempel med cURL:**
```bash
curl -X PUT "https://host/services/calender/calender.php?action=editSuggestion" \
  -H "Content-Type: application/json" \
  -H "X-User-Token: $USER_TOKEN" \
  -d '{"suggestion_id":<suggestion_id>,"event_id":<event_id>,"text":"<text>"}'
```

**Svar:**
```json
{
  "message": "Suggestion updated successfully",
  "suggestion_id": <suggestion_id>,
  "event_id": <event_id>,
  "updated_at": "<time>"
}
```

---

### 13. Ta bort ett förslag - DELETE deleteSuggestion

Förslagets författare, eller ägaren av händelsen.

**Endpoint:** `DELETE https://host/services/calender/calender.php?action=deleteSuggestion&suggestion_id=<suggestion_id>&event_id=<event_id>`

**Exempel med cURL:**
```bash
curl -X DELETE "https://host/services/calender/calender.php?action=deleteSuggestion&suggestion_id=<suggestion_id>&event_id=<event_id>" \
  -H "X-User-Token: $USER_TOKEN"
```

**Svar:**
```json
{
  "message": "Suggestion deleted successfully",
  "suggestion_id": <suggestion_id>,
  "event_id": <event_id>
}
```

---

## 🔁 Återkommande händelser

För att få en händelse att återkomma, skicka ett `recurrence`-objekt i `createNewEvent` eller `editEvent`:

```json
{ "frequency": "<frequency>", "interval_step": <interval_step>, "end_date": "<end_date>" }
```

| Fält | Beskrivning |
|---|---|
| `frequency` | `daily`, `weekly`, `monthly` eller `yearly` (gemener) |
| `interval_step` | Var N:e enhet. `2` med `weekly` betyder varannan vecka. Standardvärde `1` |
| `end_date` | När upprepningen slutar (`YYYY-MM-DD HH:MM:SS`). `null` betyder att den återkommer för alltid |

---

## ⏱️ Sessionstimeout

- 🕐 **Användartoken:** giltig i 30 minuter efter att den senast användes. Varje anrop som använder den förnyar den.
- 🏢 **Företagstoken:** förnyas också av varje kalenderanrop. Om ingen har använt kalendern på 30 minuter går den ut, och en admin måste köra `rootLogin` igen.
- ⚠️ **Utgången användartoken:** du får `401`. Logga in igen med `loginUser`.

---

## 🆘 Felsökning

### Statuskoder

| Kod | Betydelse |
|---|---|
| 200 | Lyckades |
| 201 | Händelse eller förslag skapat |
| 400 | Saknade eller ogiltiga uppgifter, eller okända medarbetare |
| 401 | Saknad, felaktig eller utgången användartoken |
| 403 | Problem med företagstoken, eller så får du inte göra detta |
| 404 | Okänd action, händelse, förslag eller inbjudan |
| 405 | Fel HTTP-metod för anropet |
| 500 | Oväntat databasfel vid sparande |
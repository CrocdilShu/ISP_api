# Kalender API Dokumentation

Detta API hanterar alla kalenderrelaterade funktioner såsom händelser, deltagare, inbjudningar och förslag (kommentarer).

## Bas-URL & Autentisering

Alla anrop görs mot huvudfilen för kalendern:
`POST /services/calender/calender.php?action={ACTION}` (vissa slutpunkter accepterar även `GET`, `PUT` och `DELETE`).

### Obligatoriska HTTP-Headers
För att göra anrop mot kalender-API:et krävs två olika tokens:

| Header | Beskrivning |
| :--- | :--- |
| `X-User-Token` | **Användartoken** (utfärdad vid `loginUser`). Identifierar den inloggade medarbetaren som utför handlingen. |

> **Identifiering av händelser:** I alla anrop som kräver en specifik händelse kan du skicka antingen `event_id` **ELLER** en kombination av `title` och `creator_email`.

---

## Översikt över Actions

1. [Händelser (Events)](#1-händelser-events)
   - [`getEvents`](#getevents)
   - [`createNewEvent`](#createnewevent)
   - [`editEvent`](#editevent)
   - [`deleteEvent`](#deleteevent)
2. [Deltagare & Inbjudningar](#2-deltagare--inbjudningar)
   - [`getParticipants`](#getparticipants)
   - [`addParticipants`](#addparticipants)
   - [`getInvites`](#getinvites)
   - [`respondToInvite`](#respondtoinvite)
   - [`editParticipants`](#editparticipants)
3. [Förslag & Kommentarer](#3-förslag--kommentarer)
   - [`getSuggestions`](#getsuggestions)
   - [`createSuggestion` / `addSuggestion`](#createsuggestion--addsuggestion)
   - [`editSuggestion`](#editsuggestion)
   - [`deleteSuggestion`](#deletesuggestion)

---

## 1. Händelser (Events)

### `getEvents`
Hämtar kalenderhändelser för företaget med stöd för paginering (`start_id` och `limit`). Kan även filtreras per användare, e-post eller tidsintervall. Återkommande händelser returneras en gång tillsammans med sin upprepningsregel.

* **Metod:** `POST`
* **Query Parameter:** `action=getEvents`
* **Request Body (valfri):**
```json
{
  "start_id": 0,
  "limit": 100,
  "user_id": 5,
  "email": "user@test.com",
  "from": "2026-10-01 00:00:00",
  "to": "2026-10-31 23:59:59"
}
```

* **Svar (200 OK):**
```json
{
  "start_id": 0,
  "limit": 100,
  "count": 1,
  "events": [
    {
      "ID": 12,
      "start_time": "2026-10-20 10:00:00",
      "end_time": "2026-10-20 11:00:00",
      "created_at": "2026-10-15 08:30:00",
      "FORrecurrence": 3,
      "title": "Veckomöte",
      "content": {
        "description": "Genomgång av projekt"
      },
      "owner_id": 2,
      "owner_username": "alice",
      "frequency": "weekly",
      "interval_step": 1,
      "recurrence_end_date": "2026-12-31"
    }
  ]
}
```

---

### `createNewEvent`
Skapar en ny kalenderhändelse. Skaparen blir automatiskt ägare (`owner`) och deltagare.

* **Metod:** `POST`
* **Query Parameter:** `action=createNewEvent`
* **Request Body:**
```json
{
  "title": "Morgonsync",
  "content": {
    "agenda": "Planera dagen",
    "location": "Rum A"
  },
  "start_time": "2026-10-25 09:00:00",
  "end_time": "2026-10-25 09:30:00",
  "recurrence": {
    "frequency": "daily",
    "interval_step": 1,
    "end_date": "2026-11-01"
  },
  "participants": [3, "bob@test.com"]
}
```

* **Svar (201 Created):**
```json
{
  "message": "Event created successfully",
  "event_id": 15
}
```

---

### `editEvent`
Uppdaterar en befintlig händelse. Endast händelsens ägare har behörighet att redigera.

* **Metod:** `PUT` eller `POST`
* **Query Parameter:** `action=editEvent`
* **Request Body:**
```json
{
  "event_id": 15,
  "title": "Uppdaterad Morgonsync",
  "start_time": "2026-10-25 09:30:00",
  "end_time": "2026-10-25 10:00:00",
  "content": {
    "agenda": "Ny agenda"
  },
  "recurrence": null
}
```

* **Svar (200 OK):**
```json
{
  "message": "Event updated successfully"
}
```

---

### `deleteEvent`
Tar bort en kalenderhändelse. Kan utföras av händelsens ägare eller av en administratör (`admin` / `root_admin`).

* **Metod:** `DELETE` eller `POST`
* **Query Parameter:** `action=deleteEvent`
* **Request Body (eller Query Parameters):**
```json
{
  "event_id": 15
}
```

* **Svar (200 OK):**
```json
{
  "message": "Event deleted successfully"
}
```

---

## 2. Deltagare & Inbjudningar

### `getParticipants`
Hämtar alla aktiva deltagare som har accepterat inbjudan till en händelse med stöd för paginering (`start_id` och `limit`).

* **Metod:** `POST` eller `GET`
* **Query Parameter:** `action=getParticipants`
* **Request Body / Query Params:**
```json
{
  "event_id": 12,
  "start_id": 0,
  "limit": 100
}
```

* **Svar (200 OK):**
```json
{
  "event_id": 12,
  "start_id": 0,
  "limit": 100,
  "count": 1,
  "participants": [
    {
      "ID": 2,
      "username": "alice",
      "email": "alice@test.com",
      "participant_since": "2026-10-15 08:30:00"
    }
  ]
}
```

---

### `addParticipants`
Skickar inbjudningar till användare (sätter status till `pending`). Endast händelsens ägare kan bjud in fler.

* **Metod:** `POST`
* **Query Parameter:** `action=addParticipants`
* **Request Body:**
```json
{
  "event_id": 12,
  "participants": ["bob@test.com", 4]
}
```

* **Svar (200 OK):**
```json
{
  "message": "Invitations sent successfully",
  "invited_user_ids": [3, 4]
}
```

---

### `getInvites`
Hämtar alla väntande (`pending`) inbjudningar för den inloggade användaren.

* **Metod:** `GET` eller `POST`
* **Query Parameter:** `action=getInvites`

* **Svar (200 OK):**
```json
{
  "invites": [
    {
      "invite_id": 8,
      "event_id": 12,
      "status": "pending",
      "created_at": "2026-10-23 10:00:00",
      "event_title": "Veckomöte",
      "event_content": { "agenda": "Genomgång" },
      "start_time": "2026-10-25 10:00:00",
      "end_time": "2026-10-25 11:00:00",
      "owner_id": 2,
      "owner_username": "alice",
      "owner_email": "alice@test.com"
    }
  ]
}
```

---

### `respondToInvite`
Svarar på en inbjudan med entingen godkännande (`accept`) eller avböjande (`decline`).

* **Metod:** `POST` eller `PUT`
* **Query Parameter:** `action=respondToInvite`
* **Request Body:**
```json
{
  "invite_id": 8,
  "action": "accept"
}
```

* **Svar (200 OK):**
```json
{
  "message": "Invitation accepted successfully",
  "invite_id": 8,
  "event_id": 12,
  "status": "accepted"
}
```

---

### `editParticipants`
Skriver över hela deltagarlistan för en händelse direkt utan inbjudan. Endast ägaren har tillgång till denna åtgärd.

* **Metod:** `PUT` eller `POST`
* **Query Parameter:** `action=editParticipants`
* **Request Body:**
```json
{
  "event_id": 12,
  "participants": [2, 3, 5]
}
```

* **Svar (200 OK):**
```json
{
  "message": "Participants updated successfully"
}
```

---

## 3. Förslag & Kommentarer

Förslag sparas i kommentarstabellen kopplad till händelsen.

### `getSuggestions`
Hämtar alla förslag/kommentarer för en viss händelse i kronologisk ordning med stöd för paginering (`start_id` och `limit`).

* **Metod:** `GET`
* **Query Parameter:** `action=getSuggestions&event_id=12&start_id=0&limit=100`

* **Svar (200 OK):**
```json
{
  "event_id": 12,
  "start_id": 0,
  "limit": 100,
  "count": 1,
  "suggestions": [
    {
      "suggestion_id": 3,
      "created_at": "2026-10-23 09:15:00",
      "last_update": null,
      "user_id": 3,
      "username": "bob",
      "pfp_file": "img/bob.png",
      "text": "Kan vi flytta mötet till kl 11:00 istället?"
    }
  ]
}
```

---

### `createSuggestion` / `addSuggestion`
Lägger till ett nytt förslag på en händelse. Kräver att den inloggade användaren är deltagare i händelsen.

* **Metod:** `POST`
* **Query Parameter:** `action=createSuggestion` (eller `addSuggestion`)
* **Request Body:**
```json
{
  "event_id": 12,
  "text": "Kan vi flytta mötet till kl 11:00 istället?"
}
```

* **Svar (201 Created):**
```json
{
  "message": "Suggestion added successfully",
  "suggestion_id": 4
}
```

---

### `editSuggestion`
Redigerar texten i ett förslag.

* **Metod:** `POST` eller `PUT`
* **Query Parameter:** `action=editSuggestion`
* **Request Body:**
```json
{
  "suggestion_id": 4,
  "event_id": 12,
  "content": "Uppdaterad förslagstext"
}
```

* **Svar (200 OK):**
```json
{
  "message": "Suggestion updated successfully",
  "suggestion_id": 4,
  "event_id": 12,
  "updated_at": "2026-10-23 10:20:00"
}
```

---

### `deleteSuggestion`
Tar bort ett förslag. Kan endast göras av förslagets författare eller händelsens ägare.

* **Metod:** `DELETE` eller `POST`
* **Query Parameter:** `action=deleteSuggestion`
* **Request Body (eller Query Params):**
```json
{
  "suggestion_id": 4,
  "event_id": 12
}
```

* **Svar (200 OK):**
```json
{
  "message": "Suggestion deleted successfully",
  "suggestion_id": 4,
  "event_id": 12
}
```

---

## Felhantering & Statuskoder

| Statuskod | Betydelse |
| :--- | :--- |
| `200 OK` | Förfrågan lyckades. |
| `201 Created` | Resursen (händelse/förslag) skapades. |
| `400 Bad Request` | Saknade parametrar eller felaktigt format (t.ex. starttid efter sluttid). |
| `401 Unauthorized` | Saknad eller ogiltig användartoken (`X-User-Token`). |
| `403 Forbidden` | Företagstoken ogiltig, eller användaren saknar behörighet (t.ex. inte ägare/deltagare). |
| `404 Not Found` | Händelsen, inbjudan eller förslaget hittades inte, eller okänd `action`. |
| `500 Internal Server Error` | Databasfel eller internt serverfel. |
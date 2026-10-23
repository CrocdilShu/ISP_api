# Användar-API Dokumentation

Detta API hanterar användarhantering, inloggning, behörigheter och profilhantering för alla medarbetare och administratörer.

## Bas-URL & Autentisering

Alla anrop görs mot användartjänstens huvudfil:
`POST /services/user/user.php?action={ACTION}` (vissa funktioner accepterar även `GET`, `PUT` och `DELETE`).

### HTTP-Headers och Tokens

API:et använder två nivåer av tokens:

| Header | Beskrivning |
| ----- | ----- |
| `X-Session-Key` | **Företagstoken** från leverantören. Verifierar att företaget har behörighet till kalender- och användartjänsten. |
| `X-User-Token` | **Användartoken** (erhålls via `loginUser`). Identifierar och verifierar den specifika medarbetare som utför handlingen. |

---

## Roller och Behörigheter

Systemet tillämpar tre behörighetsnivåer:

| Roll | Nivå | Behörigheter |
| ----- | ----- | ----- |
| `user` | **1** | Kan läsa och redigera sin egen profil samt se andra användares offentliga profiler. |
| `admin` | **2** | Kan skapa, redigera och ta bort vanliga användare (`user`). Kan tilldela roller upp till `admin`. |
| `root_admin` | **3** | Fullständig åtkomst. Kan hantera alla konton, inklusive andra administratörer. |

---

## Översikt över Actions

1. [Autentisering & Sessioner](#1-autentisering--sessioner)
   * [`rootLogin`](#rootlogin)
   * [`loginUser`](#loginuser)
   * [`logoutUser`](#logoutuser)
2. [Användarhantering](#2-användarhantering)
   * [`getUser`](#getuser)
   * [`createNewUser`](#createnewuser)
   * [`editUser`](#edituser)
   * [`deleteUser`](#deleteuser)

---

## 1. Autentisering & Sessioner

### `rootLogin`

Autentiserar företags-/rotkontot mot den externa leverantören och sparar en företagssession (`session_key`) lokalt.

* **Metod:** `POST`
* **Query Parameter:** `action=rootLogin`
* **Inloggningstyp:** Kräver inga tidigare headers.

#### Request Body
```json
{
  "username": "preseeded_admin",
  "password": "mitt_root_lösenord"
}
```

#### Svar (200 OK)
```json
{
  "success": true,
  "user_id": 1,
  "session_key": "prov_sess_abc123xyz...",
  "services": 7,
  "expires_at": "2026-10-23 11:04:00"
}
```

---

### `loginUser`

Loggar in en medarbetare med e-post och lösenord. Returnerar en personlig användartoken (`user_token`) som ska skickas i `X-User-Token` på efterföljande anrop.

* **Metod:** `POST`
* **Query Parameter:** `action=loginUser`

#### Request Body
```json
{
  "email": "medarbetare@test.com",
  "password": "mitt_lösenord"
}
```

#### Svar (200 OK)
```json
{
  "message": "Logged in successfully",
  "user_token": "usr_tok_9876543210abc...",
  "expires_at": "2026-10-23 18:00:00",
  "user": {
    "user_id": 5,
    "username": "alice",
    "role_id": 1,
    "role_name": "user"
  }
}
```

---

### `logoutUser`

Avslutar och makulerar den aktiva användarsessionen som är kopplad till angiven `X-User-Token`.

* **Metod:** `POST`
* **Query Parameter:** `action=logoutUser`
* **Headers:** `X-User-Token: <din_token>`

#### Svar (200 OK)
```json
{
  "message": "Logged out successfully"
}
```

---

## 2. Användarhantering

### `getUser`

Hämtar användarprofiler eller innehåll skapat av användare (blogg, wiki, kalender).

* **Metod:** `GET`
* **Query Parameter:** `action=getUser`

#### Valfria URL-parametrar
* `user_id`: Hämta en specifik användare via ID.
* `email`: Sök och hämta en specifik användare via e-postadress.
* `username` / `name`: Filtrera användare vars användarnamn börjar på angiven sträng.
* `start_id`: Lägsta användar-ID att starta hämtningen från (för paginering).
* `limit`: Maximalt antal användare som returneras (standard: 100).
* `step`: Kliv/mönster vid hämtning (t.ex. `step=2` tar varannan användare).
* `page_type`: Anger om skapat innehåll ska inkluderas. Giltiga värden: `blog`, `wiki`, `calendar`.
* `title` / `titel`: Filtrera titlar på sidor/händelser som börjar med angiven sträng (när `page_type` används).

#### Exempelförfrågan: Hämta användarlista
`GET /services/user/user.php?action=getUser&start_id=1&limit=10`

#### Svar (200 OK)
```json
{
  "start_id": 1,
  "limit": 10,
  "step": 1,
  "count": 2,
  "users": [
    {
      "ID": 1,
      "username": "admin",
      "email": "admin@test.com",
      "description": "Systemadministratör",
      "pfp_file": "pictures/defpfp.png"
    },
    {
      "ID": 2,
      "username": "bob",
      "email": "bob@test.com",
      "description": "Projektledare",
      "pfp_file": "uploads/pfp_2_1697000000.png"
    }
  ]
}
```

*Obs: Om anroparen är en vanlig användare som kollar på andra användare döljs fältet `email` av integritetsskäl.*

---

### `createNewUser`

Skapar en ny användare i systemet.

> **Särskild regeltillämpning:**
> - Om databasen är helt tom blir den första användaren som skapas automatiskt `root_admin` och kräver ingen tidigare inloggning.
> - För alla efterföljande användare krävs en inloggad administratör (`admin` eller `root_admin`).
> - En administratör kan inte tilldela en högre roll än sin egen.

* **Metod:** `POST`
* **Query Parameter:** `action=createNewUser`
* **Format:** `multipart/form-data` eller `application/x-www-form-urlencoded`

#### Request Body (Form Data)
| Fält | Typ | Obligatorisk | Beskrivning |
| ----- | ----- | ----- | ----- |
| `email` | `string` | **Ja** | Unik e-postadress (max 255 tecken). |
| `username` | `string` | **Ja** | Användarnamn (max 255 tecken). |
| `password` | `string` | **Ja** | Lösenord (8–72 tecken). |
| `description` | `string` | Nej | Kort beskrivning/biografi (max 255 tecken). |
| `pfp_file` | `file` / `string` | Nej | Profilbildsfil (uppladdning) eller bildsökväg. Standard: `pictures/defpfp.png`. |
| `FORrole` | `int` | Nej | Roll-ID (1 = `user`, 2 = `admin`, 3 = `root_admin`). Standard: 1. |

#### Svar (201 Created)
```json
{
  "message": "User created successfully",
  "user_id": 8
}
```

---

### `editUser`

Uppdaterar profil- och kontouppgifter för en användare.

> **Behörighetsregler:**
> - En användare kan redigera sin egen profil (dock inte ändra sin egen roll).
> - En administratör kan redigera konton för användare med lägre roll.
> - Användaren som ska uppdateras identifieras med `user_id` eller `target_email`.

* **Metod:** `PUT` eller `POST`
* **Query Parameter:** `action=editUser`
* **Format:** `multipart/form-data` eller `application/x-www-form-urlencoded`

#### Request Body
```json
{
  "user_id": 8,
  "username": "nytt_användarnamn",
  "email": "ny_e-post@test.com",
  "password": "nytt_säkert_lösenord",
  "description": "Uppdaterad profiltext",
  "FORrole": 2
}
```

#### Svar (200 OK)
```json
{
  "message": "User updated successfully"
}
```

---

### `deleteUser`

Rensar användardata eller raderar kontot helt.

> **Särskilda regler:**
> - Du kan radera ditt eget konto, eller användare som har en lägre roll än din egen.
> - Om parametern `remove` skickas med raderas endast specificerade datatyper istället för hela kontot.
> - **Skydd:** Den sista kvarvarande `root_admin` kan inte raderas.

* **Metod:** `DELETE` eller `POST`
* **Query Parameter:** `action=deleteUser`

#### Request Body (Hel radering)
```json
{
  "email": "anvandare@test.com"
}
```

#### Request Body (Selektiv rensning av data)
```json
{
  "email": "anvandare@test.com",
  "remove": ["calendar", "wiki"]
}
```

> Tillåtna värden i `remove`: `calendar` (eller `calendar_event`), `wiki`, `blog`, `account` (eller `user`).

#### Svar (200 OK - Hel radering)
```json
{
  "message": "User and all associated data deleted",
  "email": "anvandare@test.com"
}
```

#### Svar (200 OK - Selektiv rensning)
```json
{
  "message": "Requested user data removed successfully",
  "email": "anvandare@test.com",
  "removed_types": ["calendar", "wiki"]
}
```

---

## Felhantering & Statuskoder

| Statuskod | Betydelse |
| ----- | ----- |
| `200 OK` | Förfrågan lyckades. |
| `201 Created` | Användare har skapats. |
| `400 Bad Request` | Felaktiga eller saknade indataparametrar (t.ex. lösenord kortare än 8 tecken). |
| `401 Unauthorized` | Saknad/ogiltig inloggning eller felaktigt e-post/lösenord. |
| `403 Forbidden` | Otillräcklig behörighet (t.ex. försök att ändra en användare med högre/lika roll). |
| `404 Not Found` | Användaren hittades inte eller okänd `action`. |
| `409 Conflict` | E-postadressen är redan registrerad, eller försök att radera sista `root_admin`. |
| `500 Internal Server Error` | Databasfel eller misslyckad lagring av session. |
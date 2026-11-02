# Wiki API Dokumentation

Detta API hanterar alla wikirelaterade funktioner såsom wikis, sidor, bilder, kommentarer, versionshistorik, behörigheter och administratörer.

## Bas-URL & Autentisering

Wiki-API:et består av fem filer. Till skillnad från kalender-API:et finns ingen `action`-parameter. Vilken åtgärd som utförs bestäms av filen, HTTP-metoden och query-parametrarna.

| Fil | Används till |
| :--- | :--- |
| `/services/wiki/pages.php` | Wikis och sidor (inklusive bilder) |
| `/services/wiki/comments.php` | Kommentarer på sidor |
| `/services/wiki/history.php` | Versionshistorik och återställning av sidor |
| `/services/wiki/permissions.php` | Behörigheter och ägarskap för en wiki |
| `/services/wiki/admins.php` | Administratörer, roller och avstängningar |

### HTTP-Headers

| Header | Beskrivning |
| :--- | :--- |
| `X-User-Token` | **Användartoken** (utfärdad vid `loginUser`). Identifierar den inloggade användaren. **Räcker som enda header** för att få tillgång till wiki-tjänsten. |
| `X-Session-Key` | **Företagstoken** från leverantören. **Valfri.** Används bara som reserv när ingen giltig användartoken skickas (se nedan). |

> **Obs:** Företagstoken är inte längre ett krav. Den som skickar en giltig `X-User-Token` behöver ingen `X-Session-Key`.

### Hur åtkomst kontrolleras

Kontrollen sker i denna ordning vid varje anrop:

1. **Giltig `X-User-Token`** skickas: åtkomst beviljas direkt och företagstoken kontrolleras inte.
2. **Ingen giltig användartoken, men `X-Session-Key` (eller `Authorization`-header) skickas:** företaget kontrolleras mot leverantören för tjänsten `SERVICE_WIKI`. Är den giltig släpps anropet igenom, men utan användare:
   * `pages.php` tillåter då **endast läsning (`GET`)** av **publika** wikis och sidor.
   * Alla skrivande anrop på `pages.php`, samt **alla** anrop mot `comments.php`, `history.php`, `permissions.php` och `admins.php`, svarar `401`.
3. **Ingen av delarna:** svar `403`.

### Logga in och hämta användartoken

Användartoken hämtas via användar-API:et (inget företagstoken krävs för detta anrop):

* **Metod:** `POST`
* **URL:** `/services/user/user.php?action=loginUser`
* **Request Body:**
```json
{
  "email": "alice@test.com",
  "password": "hemligt123"
}
```

* **Svar (200 OK):**
```json
{
  "message": "Logged in successfully",
  "user_token": "a1b2c3d4e5...",
  "expires_at": "2026-10-25 12:00:00",
  "user": {
    "user_id": 2,
    "username": "alice",
    "role_id": 1,
    "role_name": "user"
  }
}
```

Skicka sedan `user_token` som headern `X-User-Token` i alla wiki-anrop. Token skickas i headern, **inte** under fliken Auth i Bruno/Postman.

### Begrepp

* **Wiki** är en samling sidor (en "space") som har en ägare, en synlighet och taggar. I anrop mot `pages.php` anges en wiki med `type=wiki`.
* **Sida** tillhör alltid exakt en wiki och ärver dess synlighet och behörigheter. Sidor är standard i `pages.php` (`type=page`).
* **Synlighet:** `public` (alla kan läsa) eller `private` (endast ägare, personer med behörighet samt administratörer).
* **Roller (konto):** `user`, `admin`, `root_admin` och `banned`.
* **Behörigheter (per wiki):** `owner` (ägare), `co_owner` och `editor`.
* **Innehåll** (`content`) sparas och returneras alltid som ren text.

---

## Översikt över Endpoints

1. [Wikis](#1-wikis)
   - [`GET` Lista wikis](#lista-wikis)
   - [`GET` Hämta en wiki](#hämta-en-wiki)
   - [`POST` Skapa wiki](#skapa-wiki)
   - [`PUT` / `POST` Redigera wiki](#redigera-wiki)
   - [`DELETE` Ta bort wiki](#ta-bort-wiki)
2. [Sidor & Bilder](#2-sidor--bilder)
   - [`GET` Lista sidor](#lista-sidor)
   - [`GET` Hämta en sida](#hämta-en-sida)
   - [`POST` Skapa sida](#skapa-sida)
   - [`PUT` / `POST` Redigera sida](#redigera-sida)
   - [`DELETE` Ta bort sida](#ta-bort-sida)
3. [Kommentarer](#3-kommentarer)
   - [`GET` Lista kommentarer](#lista-kommentarer)
   - [`POST` Skapa kommentar](#skapa-kommentar)
   - [`PUT` Redigera kommentar](#redigera-kommentar)
   - [`DELETE` Ta bort kommentar](#ta-bort-kommentar)
4. [Historik](#4-historik)
   - [`GET` Lista historik / hämta en version](#lista-historik--hämta-en-version)
   - [`POST` Återställ sida](#återställ-sida-rollback)
5. [Behörigheter](#5-behörigheter)
   - [`GET` Lista behörigheter](#lista-behörigheter)
   - [`POST` Ge behörighet / överför ägarskap](#ge-behörighet--överför-ägarskap)
   - [`DELETE` Ta bort behörighet / ägarskap](#ta-bort-behörighet--ägarskap)
6. [Administratörer & Avstängningar](#6-administratörer--avstängningar)
   - [`GET` Lista administratörer / sök användare](#lista-administratörer--sök-användare)
   - [`POST` Ändra roll / stäng av användare](#ändra-roll--stäng-av-användare)
   - [`DELETE` Ta bort adminroll / häv avstängning](#ta-bort-adminroll--häv-avstängning)
7. [Behörighetsmatris](#behörighetsmatris)

---

## 1. Wikis

Alla wiki-anrop går mot `/services/wiki/pages.php` och kräver `type=wiki` som query-parameter (eller `"type": "wiki"` i bodyn vid `POST`/`PUT`/`DELETE`).

### Lista wikis
Hämtar alla wikis som den som anropar får se. Privata wikis visas bara för ägaren, personer med behörighet och administratörer. Utan användartoken visas endast publika wikis.

* **Metod:** `GET`
* **Query Parameters:**

| Parameter | Beskrivning |
| :--- | :--- |
| `type=wiki` | **Obligatorisk.** |
| `search` | Valfri. Söker i titel, beskrivning och ägarens e-post. |
| `tag` | Valfri. Visar bara wikis med denna tagg. |
| `sort` | Valfri. `ASC` eller `DESC` (standard `DESC`). Sorterar på titel. |

* **Exempel:** `GET /services/wiki/pages.php?type=wiki&search=guide&sort=ASC`

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Wikis listed",
  "count": 1,
  "wikis": [
    {
      "wiki_id": 1,
      "titel": "Användarguide",
      "description": "Guider för alla användare",
      "created_at": "2026-10-15 08:30:00",
      "owner_email": "alice@test.com",
      "visibility": "public",
      "tags": ["guide", "support"]
    }
  ]
}
```

---

### Hämta en wiki
Hämtar en enskild wiki.

* **Metod:** `GET`
* **Query Parameters:** `type=wiki&id=1` (`wiki_id` fungerar också istället för `id`)

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Wiki fetched",
  "wiki": {
    "wiki_id": 1,
    "titel": "Användarguide",
    "description": "Guider för alla användare",
    "created_at": "2026-10-15 08:30:00",
    "owner_email": "alice@test.com",
    "visibility": "public",
    "tags": ["guide", "support"]
  }
}
```

* **Fel:** `404` om wikin inte finns, `403` om den är privat och du saknar behörighet att se den.

---

### Skapa wiki
Skapar en ny wiki. Den som skapar wikin blir automatiskt ägare. Alla inloggade användare kan skapa wikis.

* **Metod:** `POST`
* **Query Parameter:** `type=wiki`
* **Request Body:**
```json
{
  "titel": "Användarguide",
  "description": "Guider för alla användare",
  "visibility": "public",
  "tags": ["guide", "support"]
}
```

| Fält | Beskrivning |
| :--- | :--- |
| `titel` | **Obligatorisk.** |
| `description` | Valfri. |
| `visibility` | Valfri. `public` eller `private` (standard `public`). |
| `tags` | Valfri. Lista eller kommaseparerad text. |

* **Svar (201 Created):**
```json
{
  "success": true,
  "message": "Wiki created",
  "wiki": {
    "wiki_id": 5,
    "titel": "Användarguide",
    "description": "Guider för alla användare",
    "created_at": "2026-10-23 10:00:00",
    "owner_email": "alice@test.com",
    "visibility": "public",
    "tags": ["guide", "support"]
  }
}
```

---

### Redigera wiki
Uppdaterar titel, beskrivning, synlighet och taggar. Endast de fält som skickas ändras. Om synligheten ändras uppdateras den även för alla sidor i wikin. Kräver att du är wikins ägare, `co_owner` eller administratör.

* **Metod:** `PUT` eller `POST`
* **Query Parameters:** `type=wiki&id=5` (på `POST` kan wikins id även skickas som `wiki_id` i bodyn)
* **Request Body:**
```json
{
  "titel": "Ny titel",
  "description": "Ny beskrivning",
  "visibility": "private",
  "add_tags": ["intern"],
  "remove_tags": ["support"]
}
```

| Fält | Beskrivning |
| :--- | :--- |
| `titel`, `description`, `visibility` | Valfria. |
| `add_tags` | Taggar som ska läggas till (`tags` fungerar också). |
| `remove_tags` | Taggar som ska tas bort. |

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Wiki updated",
  "wiki": {
    "wiki_id": 5,
    "titel": "Ny titel",
    "description": "Ny beskrivning",
    "created_at": "2026-10-23 10:00:00",
    "owner_email": "alice@test.com",
    "visibility": "private",
    "tags": ["guide", "intern"]
  }
}
```

---

### Ta bort wiki
Tar bort en wiki tillsammans med alla dess sidor. Sidornas bilder tas inte bort direkt utan sparas i 7 dagar innan de raderas permanent. Kan utföras av wikins ägare eller en administratör.

* **Metod:** `DELETE`
* **Query Parameters:** `type=wiki&id=5`

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Wiki deleted",
  "wiki_id": 5
}
```

---

## 2. Sidor & Bilder

Alla sid-anrop går mot `/services/wiki/pages.php`. Sidor är standardtypen, så `type` behöver inte anges.

### Lista sidor
Hämtar alla sidor som den som anropar får se, med taggar och bilder.

* **Metod:** `GET`
* **Query Parameters:**

| Parameter | Beskrivning |
| :--- | :--- |
| `wiki_id` | Valfri. Visar bara sidor i denna wiki. |
| `search` | Valfri. Söker i sidtitel, författarens e-post och wikiägarens e-post. |
| `tag` | Valfri. Visar bara sidor i wikis med denna tagg. |
| `sort` | Valfri. `ASC` eller `DESC` (standard `DESC`). Sorterar på titel. |

* **Exempel:** `GET /services/wiki/pages.php?wiki_id=1&sort=ASC`

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Pages listed",
  "count": 1,
  "pages": [
    {
      "page_id": 12,
      "wiki_id": 1,
      "titel": "Kom igång",
      "content": "Så här börjar du...",
      "created_at": "2026-10-16 09:00:00",
      "author_email": "bob@test.com",
      "owner_email": "alice@test.com",
      "visibility": "public",
      "tags": ["guide", "support"],
      "pictures": [
        {
          "picture_id": 3,
          "filename": "start.png",
          "mime_type": "image/png",
          "file_path": "services/wiki/pics/pages/12/start.png",
          "created_at": "2026-10-16 09:00:00",
          "uploaded_by_email": "bob@test.com"
        }
      ]
    }
  ]
}
```

---

### Hämta en sida
Hämtar en enskild sida.

* **Metod:** `GET`
* **Query Parameters:** `id=12` (`page_id` fungerar också)

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Page fetched",
  "page": {
    "page_id": 12,
    "wiki_id": 1,
    "titel": "Kom igång",
    "content": "Så här börjar du...",
    "created_at": "2026-10-16 09:00:00",
    "author_email": "bob@test.com",
    "owner_email": "alice@test.com",
    "visibility": "public",
    "tags": ["guide", "support"],
    "pictures": []
  }
}
```

* **Fel:** `404` om sidan inte finns, `403` om sidans wiki är privat och du saknar behörighet.

---

### Skapa sida
Skapar en ny sida i en wiki. Sidan ärver wikins synlighet och behörigheter. Kräver att du är wikins ägare, `co_owner`, `editor` eller administratör.

* **Metod:** `POST`
* **Query Parameter:** `wiki_id` kan skickas här eller i bodyn.
* **Content-Type:** `application/json` eller `multipart/form-data` (för bildfiler).

**Alternativ A: JSON**
```json
{
  "wiki_id": 1,
  "titel": "Kom igång",
  "content": "Så här börjar du...",
  "pictures": [
    {
      "filename": "start.png",
      "mime_type": "image/png",
      "data": "data:image/png;base64,iVBORw0KGgo..."
    }
  ]
}
```

**Alternativ B: Multipart (formulärdata)**

| Fält | Beskrivning |
| :--- | :--- |
| `wiki_id` | **Obligatorisk.** |
| `titel` | **Obligatorisk.** |
| `content` | **Obligatorisk.** |
| `pictures` | Valfri. En eller flera bildfiler (`pictures[]`). Tillåtna filändelser: `jpg`, `jpeg`, `png`, `gif`, `webp`. Övriga filer ignoreras. |

> **Bilder i JSON:** `data` kan vara base64-text, med eller utan `data:image/...;base64,`-prefix. Fältet `mime_type` är valfritt (standard `image/png`). Bilderna sparas på servern under `services/wiki/pics/pages/{page_id}/`.

* **Svar (201 Created):**
```json
{
  "success": true,
  "message": "Page created",
  "page": {
    "page_id": 12,
    "wiki_id": 1,
    "titel": "Kom igång",
    "content": "Så här börjar du...",
    "created_at": "2026-10-16 09:00:00",
    "author_email": "bob@test.com",
    "owner_email": "alice@test.com",
    "visibility": "public",
    "tags": ["guide"],
    "pictures": []
  }
}
```

* **Fel:** `400` om `wiki_id`, `titel` eller `content` saknas, `404` om wikin inte finns.

---

### Redigera sida
Uppdaterar en sida. Endast de fält som skickas ändras. Varje redigering sparar den **tidigare** versionen i historiken (se [Historik](#4-historik)). Kräver att du är wikins ägare, `co_owner`, `editor` eller administratör.

* **Metod:** `PUT` eller `POST`
* **Query Parameter:** `id=12` (på `PUT` kan id även skickas som `id` eller `page_id` i bodyn)
* **Request Body:**
```json
{
  "titel": "Uppdaterad titel",
  "content": "Ny text",
  "removed_pic": "start.png",
  "pictures": [
    {
      "filename": "ny.png",
      "data": "data:image/png;base64,iVBORw0KGgo..."
    }
  ]
}
```

| Fält | Beskrivning |
| :--- | :--- |
| `titel`, `content` | Valfria. Utelämnade fält behåller sitt tidigare värde. |
| `removed_pic` | Valfri. `picture_id` eller filnamn på en bild som ska tas bort. Bilden mjukraderas och sparas i 7 dagar. |
| `pictures` | Valfri. Nya bilder att lägga till (samma format som vid skapande). |

> **Filuppladdning:** Bildfiler via `multipart/form-data` fungerar bara med `POST ?id=12`. `PUT` stöder endast JSON.

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Page updated",
  "page": {
    "page_id": 12,
    "wiki_id": 1,
    "titel": "Uppdaterad titel",
    "content": "Ny text",
    "created_at": "2026-10-16 09:00:00",
    "author_email": "bob@test.com",
    "owner_email": "alice@test.com",
    "visibility": "public",
    "tags": ["guide"],
    "pictures": []
  }
}
```

---

### Ta bort sida
Tar bort en sida. Bilderna mjukraderas och sparas i 7 dagar innan de raderas permanent. Kräver att du är wikins ägare, `co_owner` eller administratör (alltså **inte** `editor`).

* **Metod:** `DELETE`
* **Query Parameters:** `id=12` (id kan även skickas som `id` eller `page_id` i bodyn)

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Page deleted (associated pictures soft-deleted for retention period)",
  "page_id": 12
}
```

---

## 3. Kommentarer

Alla kommentar-anrop går mot `/services/wiki/comments.php` och kräver en giltig `X-User-Token`. Kommentarer kan skrivas av alla som får se sidan. Innehållet sparas som ren text.

### Lista kommentarer
Hämtar alla kommentarer för en sida i kronologisk ordning.

* **Metod:** `GET`
* **Query Parameters:** `page_id=12` (`id` fungerar också). Valfritt: `sort=ASC|DESC` (standard `ASC`).

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Comments listed",
  "page_id": 12,
  "count": 1,
  "comments": [
    {
      "comment_id": 4,
      "page_id": 12,
      "author_email": "bob@test.com",
      "content": "Bra guide!",
      "created_at": "2026-10-23 09:15:00",
      "last_update": null
    }
  ]
}
```

---

### Skapa kommentar
Lägger till en kommentar på en sida.

* **Metod:** `POST`
* **Request Body** (`page_id` kan även skickas som query-parameter):
```json
{
  "page_id": 12,
  "content": "Bra guide!"
}
```

* **Svar (201 Created):**
```json
{
  "success": true,
  "message": "Comment created",
  "comment": {
    "comment_id": 5,
    "page_id": 12,
    "author_email": "bob@test.com",
    "content": "Bra guide!"
  }
}
```

* **Fel:** `400` om `page_id` saknas eller texten är tom, `404` om sidan inte finns, `403` om du inte får se sidan.

---

### Redigera kommentar
Redigerar texten i en kommentar. Kan göras av kommentarens författare, wikins ägare, `co_owner` eller en administratör.

* **Metod:** `PUT`
* **Query Parameter:** `id=5` (kan även skickas som `id` eller `comment_id` i bodyn)
* **Request Body:**
```json
{
  "content": "Uppdaterad kommentar"
}
```

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Comment updated",
  "comment_id": 5,
  "content": "Uppdaterad kommentar"
}
```

---

### Ta bort kommentar
Tar bort en kommentar. Samma behörighet som vid redigering.

* **Metod:** `DELETE`
* **Query Parameter:** `id=5` (kan även skickas som `id` eller `comment_id` i bodyn)

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Comment deleted",
  "comment_id": 5
}
```

---

## 4. Historik

Alla historik-anrop går mot `/services/wiki/history.php` och kräver en giltig `X-User-Token`. En historikpost skapas automatiskt varje gång en sida redigeras och innehåller sidans **tidigare** titel och text.

### Lista historik / hämta en version
Hämtar en enskild historikpost eller en lista. Du ser en post om du är administratör, om du själv gjorde ändringen, eller om du får se sidan den tillhör.

* **Metod:** `GET`
* **Query Parameters:**

| Parameter | Beskrivning |
| :--- | :--- |
| `id` | Valfri. Hämtar en enskild historikpost (`history_id` fungerar också). |
| `page_id` | Valfri. Visar bara historik för denna sida. |
| `email` | Valfri. Visar bara ändringar gjorda av denna användare. |
| `sort` | Valfri. `ASC` eller `DESC` (standard `DESC`). Sorterar på ändringstid. |

* **Svar, lista (200 OK):**
```json
{
  "success": true,
  "message": "History listed",
  "count": 1,
  "history": [
    {
      "history_id": 7,
      "page_id": 12,
      "edited_by_email": "bob@test.com",
      "update_at": "2026-10-23 10:20:00",
      "old_titel": "Kom igång",
      "old_content": "Så här börjar du...",
      "removed_pic": "start.png"
    }
  ]
}
```

* **Svar, enskild post (200 OK):** samma fält, men under nyckeln `entry` med `"message": "History entry fetched"`.

---

### Återställ sida (rollback)
Återställer en sida till en tidigare version. Återställningen räknas som en ny redigering och sparar alltså den nuvarande versionen i historiken. Om den valda historikposten tog bort en bild återställs även bilden. Kräver att du är wikins ägare, `co_owner`, `editor` eller administratör.

* **Metod:** `POST`
* **Query Parameter:** `history_id=7` (kan även skickas som `history_id` eller `id` i bodyn)
* **Request Body (alternativ):**
```json
{
  "history_id": 7
}
```

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Page rolled back",
  "history_id": 7,
  "page": {
    "page_id": 12,
    "wiki_id": 1,
    "titel": "Kom igång",
    "content": "Så här börjar du...",
    "created_at": "2026-10-16 09:00:00",
    "author_email": "bob@test.com",
    "owner_email": "alice@test.com",
    "visibility": "public",
    "tags": ["guide"],
    "pictures": []
  }
}
```

* **Fel:** `404` om historikposten eller sidan inte längre finns, `403` om du saknar redigeringsrätt.

---

## 5. Behörigheter

Alla behörighets-anrop går mot `/services/wiki/permissions.php` och kräver en giltig `X-User-Token`. Behörigheter gäller en hel wiki och därmed alla dess sidor.

### Lista behörigheter
Hämtar alla som har särskild behörighet i en wiki. Kräver att du får se wikin.

* **Metod:** `GET`
* **Query Parameters:** `wiki_id=1` (`id` fungerar också)

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Wiki permissions fetched",
  "wiki_id": 1,
  "visibility": "private",
  "owner_email": "alice@test.com",
  "acting_user_email": "bob@test.com",
  "permissions": [
    { "email": "carol@test.com", "role": "co_owner" },
    { "email": "dave@test.com", "role": "editor" }
  ]
}
```

---

### Ge behörighet / överför ägarskap
Ger en användare rollen `editor` eller `co_owner`, eller överför ägarskapet till en annan användare.

* **Metod:** `POST`
* **Request Body** (`wiki_id` kan även skickas som query-parameter):
```json
{
  "wiki_id": 1,
  "email": "dave@test.com",
  "role": "editor"
}
```

| Fält | Beskrivning |
| :--- | :--- |
| `wiki_id` | **Obligatorisk.** |
| `email` | **Obligatorisk.** E-post till användaren som ska få rollen. |
| `role` | Valfri. `editor` (standard), `co_owner` eller `owner`. |

| Roll | Vem får ge den |
| :--- | :--- |
| `editor`, `co_owner` | Wikins ägare, `co_owner` eller administratör |
| `owner` (överföring) | Endast wikins nuvarande ägare eller administratör |

* **Svar (201 Created)** för `editor` och `co_owner`:
```json
{
  "success": true,
  "message": "Permission granted",
  "wiki_id": 1,
  "email": "dave@test.com",
  "role": "editor"
}
```

* **Svar (200 OK)** vid överföring av ägarskap:
```json
{
  "success": true,
  "message": "Wiki ownership transferred",
  "wiki_id": 1,
  "email": "dave@test.com",
  "role": "owner"
}
```

* **Fel:** `400` om användaren inte finns, är avstängd från wiki-tjänsten, redan är ägare, eller om rollen är ogiltig. `404` om wikin inte finns.

---

### Ta bort behörighet / ägarskap
Tar bort en användares behörighet, eller tar bort ägarskapet.

* **Metod:** `DELETE`
* **Query Parameters (eller Request Body):** `wiki_id`, `email` och/eller `remove_ownership`

**Ta bort en behörighet** (ägare, `co_owner` eller administratör):
```json
{
  "wiki_id": 1,
  "email": "dave@test.com"
}
```
* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Permission removed",
  "wiki_id": 1,
  "email": "dave@test.com"
}
```

**Ta bort ägarskapet** (endast wikins ägare eller administratör). Görs genom att skicka `remove_ownership` som sant, eller genom att ange ägarens egen e-post. Ägarskapet går då till wikins äldsta `co_owner` (som inte är avstängd). Finns ingen sådan tilldelas wikin systemanvändaren `deleted@system.local`.
```json
{
  "wiki_id": 1,
  "remove_ownership": true
}
```
* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Wiki ownership removed",
  "wiki_id": 1,
  "new_owner_email": "carol@test.com"
}
```

---

## 6. Administratörer & Avstängningar

Alla admin-anrop går mot `/services/wiki/admins.php` och kräver en giltig `X-User-Token`.

### Lista administratörer / sök användare
Utan `search` listas alla administratörer (`admin` och `root_admin`). Med `search` söks alla användare på e-post eller användarnamn. Varje användare visas med vilka tjänster den är avstängd från.

* **Metod:** `GET`
* **Query Parameters:** Valfritt `search=alice` och `sort=ASC|DESC` (standard `ASC`, sorterar på e-post).

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Users listed",
  "acting_user_email": "root@test.com",
  "acting_user_role": "root_admin",
  "count": 1,
  "users": [
    {
      "email": "alice@test.com",
      "role": "admin",
      "banned_services": []
    }
  ]
}
```

---

### Ändra roll / stäng av användare
Ändrar en användares roll eller stänger av användaren från en tjänst.

* **Metod:** `POST`
* **Request Body** (`email` kan även skickas som query-parameter):
```json
{
  "email": "bob@test.com",
  "role": "admin",
  "service": "wiki"
}
```

| Fält | Beskrivning |
| :--- | :--- |
| `email` | **Obligatorisk.** Måste vara en annan användare än du själv. |
| `role` | Valfri (standard `admin`). `admin`, `root_admin`, `user` eller `banned`. |
| `service` | Valfri, används bara tillsammans med `role: banned`. `wiki`, `blog` eller `all` (standard `wiki`). |

**Ändra roll** (`admin`, `root_admin`, `user`): kräver `root_admin`.

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "User role updated",
  "email": "bob@test.com",
  "role": "admin"
}
```

**Stäng av användare** (`role: banned`): kräver `admin` eller `root_admin`.
* Du kan inte stänga av en `root_admin`.
* Du kan bara stänga av en `admin` om du själv är `root_admin`.
* Användarens wikis flyttas till en `co_owner` eller, om sådan saknas, till `deleted@system.local`.
* Med `service: all` får användaren även rollen `banned` och loggas ut överallt.

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "User banned from wiki",
  "email": "bob@test.com",
  "banned_service": "wiki",
  "transferred_spaces": 2
}
```

---

### Ta bort adminroll / häv avstängning
Tar bort en användares adminroll eller häver en avstängning.

* **Metod:** `DELETE`
* **Request Body (eller Query Parameter `email`):**

**Häva avstängning** (kräver `admin` eller `root_admin`):
```json
{
  "email": "bob@test.com",
  "unban_service": "wiki"
}
```
`unban_service` kan vara `wiki`, `blog` eller `all`. Med `all` återfår användaren även rollen `user`.

* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "User unbanned from wiki",
  "email": "bob@test.com",
  "unbanned_service": "wiki"
}
```

**Ta bort adminroll** (kräver `root_admin`). Utan `unban_service` sätts användarens roll tillbaka till `user`:
```json
{
  "email": "alice@test.com"
}
```
* **Svar (200 OK):**
```json
{
  "success": true,
  "message": "Admin role revoked",
  "email": "alice@test.com",
  "role": "user"
}
```

---

## Behörighetsmatris

Gäller åtgärder i en specifik wiki. Administratörer (`admin` och `root_admin`) får alltid göra allt som en ägare får.

| Åtgärd | Anonym (endast företagstoken) | Inloggad användare | `editor` | `co_owner` | Ägare | Admin |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| Läsa publik wiki/sida | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Läsa privat wiki/sida | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| Skapa wiki | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Skapa/redigera sida | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| Återställa sida (historik) | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| Ta bort sida | ❌ | ❌ | ❌ | ✅ | ✅ | ✅ |
| Redigera wiki (titel, synlighet, taggar) | ❌ | ❌ | ❌ | ✅ | ✅ | ✅ |
| Ge/ta bort `editor` och `co_owner` | ❌ | ❌ | ❌ | ✅ | ✅ | ✅ |
| Överföra eller ta bort ägarskap | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |
| Ta bort wiki | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |
| Kommentera (på sidor man får se) | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Redigera/ta bort kommentar | ❌ | Egen | Egen | ✅ | ✅ | ✅ |

> **Konto-nivå (`admins.php`):** Ändra roller kräver `root_admin`. Stänga av eller häva avstängning kräver `admin` eller `root_admin`. En `admin` kan inte stänga av en annan `admin` eller en `root_admin`.

---

## Felhantering & Statuskoder

Alla svar är JSON och innehåller `"success": true` eller `"success": false`. Vid fel finns beskrivningen i fältet `error`.

```json
{
  "success": false,
  "error": "Page not found"
}
```

| Statuskod | Betydelse |
| :--- | :--- |
| `200 OK` | Förfrågan lyckades. |
| `201 Created` | Resursen (wiki, sida, kommentar eller behörighet) skapades. |
| `400 Bad Request` | Saknade parametrar eller felaktigt format (t.ex. ogiltig synlighet, tom kommentar eller ogiltig roll). |
| `401 Unauthorized` | Företagstoken godkändes men ingen giltig användare kunde identifieras, så ett anrop som kräver inloggning nekas (felet anger orsaken, t.ex. `Missing X-User-Token header`). |
| `403 Forbidden` | Ingen åtkomst till wiki-tjänsten (varken giltig användartoken eller företagstoken skickades), eller så saknar användaren behörighet för åtgärden (t.ex. privat wiki, inte ägare). |
| `404 Not Found` | Wikin, sidan, kommentaren, historikposten eller användaren hittades inte. |
| `405 Method Not Allowed` | HTTP-metoden stöds inte av filen. |
| `500 Internal Server Error` | Databasfel eller internt serverfel. |

> **Tips vid felsökning:** En saknad, ogiltig eller utgången `X-User-Token` utan företagstoken ger `403` (inte `401`), eftersom åtkomstkontrollen inte godkänner anropet. Detsamma gäller en avstängd användare. Logga in igen via `loginUser` och använd den nya token.

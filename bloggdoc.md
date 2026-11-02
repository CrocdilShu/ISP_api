# Blogg- & Tagg-API Dokumentation

Detta API hanterar skapande, redigering, hämtning och radering av bloggar, inlägg, kommentarer och tillhörande taggar.

## Bas-URL & Autentisering

Alla anrop görs mot bloggtjänstens huvudfil:  
`POST /services/bloggapi.php?action={ACTION}` (vissa funktioner accepterar även `GET`, `PUT` och `DELETE`).

### HTTP-Headers och Tokens

API:et använder tokens för att verifiera behörighet vid ändringar i systemet:

| Header | Beskrivning |
| ----- | ----- |
| `X-User-Token` | **Användartoken** (erhålls vid inloggning via `user.php`). Identifierar och verifierar den specifika medarbetare som utför handlingen. |

---

## Roller och Behörigheter

Systemet tillämpar följande behörighetsregler för innehåll:

| Roll / Relation | Behörigheter |
| ----- | ----- |
| `Alla (Även oinloggade)` | Kan läsa alla publika bloggar, inlägg och kommentarer. |
| `Inloggad användare` | Kan skapa egna bloggar, inlägg och kommentarer. |
| `Ägare / Admin` | Kan uppdatera och radera sina egna bloggar, inlägg eller kommentarer. Administratörer kan även ändra/radera andras innehåll. |

---

## Översikt över Actions

1. [Blogghantering](#1-blogghantering)
   * [`listBlogs`](#listblogs)
   * [`createBlog`](#createblog)
   * [`updateBlog`](#updateblog)
   * [`deleteBlog`](#deleteblog)
2. [Inläggshantering](#2-inläggshantering)
   * [`getBlog`](#getblog)
   * [`createPost`](#createpost)
   * [`updatePost`](#updatepost)
   * [`deletePost`](#deletepost)
3. [Kommentarshantering](#3-kommentarshantering)
   * [`addComment`](#addcomment)
   * [`updateComment`](#updatecomment)
   * [`deleteComment`](#deletecomment)

---

## 1. Blogghantering

### `listBlogs`

Hämtar en lista över alla publicerade bloggar och deras tillhörande taggar. Kan även filtreras på en specifik tagg.

* **Metod:** `GET`
* **Query Parameter:** `action=listBlogs`

#### Valfria URL-parametrar
* `tag`: Filtrerar listan till att endast visa bloggar kopplade till den angivna taggen (t.ex. `tag=php`).

#### Exempelförfrågan: Filtrera på tagg
`GET /services/bloggapi.php?action=listBlogs&tag=php`

#### Svar (200 OK)
```json
{
  "status": "success",
  "data": [
    {
      "blog_id": 1,
      "titel": "Exempelblogg om Webbprogrammering",
      "description": "En beskrivning av vad bloggen handlar om.",
      "created_at": "2026-10-27 10:15:00",
      "owner": "testuser1",
      "tags": [
        "webb",
        "php",
        "backend"
      ]
    }
  ]
}
```

---

### `createBlog`

Skapar en ny blogg och kopplar angivna taggar till den.

> **Behörighetsregler:**
> - Kräver inloggad användare (`X-User-Token`).

* **Metod:** `POST`
* **Query Parameter:** `action=createBlog`
* **Headers:** `X-User-Token: <din_token>`
* **Format:** `application/json`

#### Request Body
```json
{
  "titel": "Min Nya Techblogg",
  "description": "Saker jag lär mig under utveckling.",
  "visibility": "public",
  "tags": [
    "php",
    "mysql",
    "api"
  ]
}
```

#### Svar (200 OK)
```json
{
  "status": "success",
  "message": "Blogg skapad med taggar!",
  "blog_id": 4,
  "tags": [
    "php",
    "mysql",
    "api"
  ]
}
```

---

### `updateBlog`

Uppdaterar titeln, beskrivningen, synligheten och taggarna för en befintlig blogg.

> **Behörighetsregler:**
> - Endast bloggens ägare eller en administratör kan uppdatera bloggen.

* **Metod:** `PUT`
* **Query Parameter:** `action=updateBlog`
* **Headers:** `X-User-Token: <din_token>`
* **Format:** `application/json`

#### Request Body
```json
{
  "blog_id": 4,
  "titel": "Min Nya Techblogg (Uppdaterad)",
  "description": "Ny och förbättrad beskrivning.",
  "visibility": "public",
  "tags": [
    "php",
    "backend",
    "ddev"
  ]
}
```

#### Svar (200 OK)
```json
{
  "status": "success",
  "message": "Bloggen har uppdaterats!",
  "tags": [
    "php",
    "backend",
    "ddev"
  ]
}
```

---

### `deleteBlog`

Borttager en blogg och alla dess kopplingar.

> **Behörighetsregler:**
> - Endast bloggens ägare eller en administratör kan ta bort bloggen.

* **Metod:** `DELETE`
* **Query Parameter:** `action=deleteBlog`
* **Headers:** `X-User-Token: <din_token>`

#### Request Body
```json
{
  "blog_id": 4
}
```

#### Svar (200 OK)
```json
{
  "status": "success",
  "message": "Bloggen har tagits bort!"
}
```

---

## 2. Inläggshantering

### `getBlog`

Hämtar ett eller flera inlägg baserat på sökning (titel), blogg-ID eller specifikt inläggs-ID.

* **Metod:** `GET`

#### Valfria URL-parametrar
* `action=getBlog&title={titel}`: Söker efter inlägg med en titel som matchar söksträngen.
* `blog_id`: Hämtar alla inlägg tillhörande en specifik blogg.
* `id`: Hämtar ett enskilt inlägg via dess unika inläggs-ID.
* `limit`: Begränsar antalet kommentarer som inkluderas per inlägg.

#### Exempelförfrågan: Hämta inlägg för blogg
`GET /services/bloggapi.php?blog_id=1`

#### Svar (200 OK)
```json
{
  "status": "success",
  "data": [
    {
      "page_id": 12,
      "titel": "Mitt Första Inlägg",
      "content": "Detta är brödtexten i inlägget.",
      "created_at": "2026-10-27 12:00:00",
      "owner_id": 1,
      "owner_username": "testuser1",
      "blog_id_rel": 5,
      "blog_ref_id": 1,
      "visibility": "public",
      "pictures": [
        "uploads/a1b2c3d4e5.png"
      ],
      "comments": []
    }
  ]
}
```

---

### `createPost`

Skapar ett nytt blogginlägg kopplat till en specifik blogg. Stöder bilduppladdning.

> **Behörighetsregler:**
> - Kräver inloggad användare (`X-User-Token`).

* **Metod:** `POST`
* **Query Parameter:** `action=createPost`
* **Headers:** `X-User-Token: <din_token>`
* **Format:** `multipart/form-data` eller `application/x-www-form-urlencoded` / `application/json`

#### Request Body (Form Data)
| Fält | Typ | Obligatorisk | Beskrivning |
| ----- | ----- | ----- | ----- |
| `blog_id` | `int` | **Ja** (eller `blog_title`) | ID för bloggen inlägget ska tillhöra. |
| `blog_title` | `string` | **Ja** (eller `blog_id`) | Titeln på bloggen om ID inte anges. |
| `titel` | `string` | **Ja** | Titeln på inlägget. |
| `content` | `string` | **Ja** | Inläggets textinnehåll. |
| `visibility` | `string` | Nej | `public` eller `private`. Standard: `public`. |
| `picture` | `file` | Nej | Valfri bildfil (JPG, PNG, GIF, WEBP - max 5MB). |

#### Svar (200 OK)
```json
{
  "status": "success",
  "message": "Inlägg skapat!",
  "page_id": 12,
  "blog_id": 1
}
```

---

### `updatePost`

Uppdaterar titeln, innehållet eller bilderna för ett befintligt inlägg.

> **Behörighetsregler:**
> - Endast inläggets ägare eller en administratör kan uppdatera inlägget.

* **Metod:** `PUT`
* **Query Parameter:** Ingen specifik action krävs (standard för PUT).
* **Headers:** `X-User-Token: <din_token>`
* **Format:** `application/json`

#### Request Body
```json
{
  "page_id": 12,
  "titel": "Uppdaterad inläggstitel",
  "content": "Uppdaterad textinnehåll i inlägget."
}
```

#### Svar (200 OK)
```json
{
  "status": "success",
  "message": "Inlägget har uppdaterats!"
}
```

---

### `deletePost`

Raderar ett blogginlägg permanent.

> **Behörighetsregler:**
> - Endast inläggets ägare eller en administratör har rätt att radera inlägget.

* **Metod:** `DELETE`
* **Headers:** `X-User-Token: <din_token>`

#### Request Body
```json
{
  "page_id": 12
}
```

#### Svar (200 OK)
```json
{
  "status": "success",
  "message": "Inlägg raderat permanent!"
}
```

---

## 3. Kommentarshantering

### `addComment`

Skriver en kommentar på ett specifikt blogginlägg.

> **Behörighetsregler:**
> - Kräver inloggad användare (`X-User-Token`).

* **Metod:** `POST`
* **Query Parameter:** `action=addComment`
* **Headers:** `X-User-Token: <din_token>`
* **Format:** `application/json`

#### Request Body
```json
{
  "page_id": 12,
  "comment": "Bra skrivit inlägg!"
}
```

#### Svar (200 OK)
```json
{
  "status": "success",
  "message": "Kommentar tillagd!",
  "comment_id": 8,
  "page_id": 12
}
```

---

### `updateComment`

Uppdaterar texten i en tidigare skriven kommentar.

> **Behörighetsregler:**
> - Endast kommentarförfattaren, inläggets ägare eller en administratör kan uppdatera kommentaren.

* **Metod:** `PUT`
* **Query Parameter:** `action=updateComment`
* **Headers:** `X-User-Token: <din_token>`
* **Format:** `application/json`

#### Request Body
```json
{
  "comment_id": 8,
  "comment": "Bra skrivit inlägg! (Uppdaterad)"
}
```

#### Svar (200 OK)
```json
{
  "status": "success",
  "message": "Kommentaren har uppdaterats!"
}
```

---

### `deleteComment`

Tar bort en kommentar.

> **Behörighetsregler:**
> - Endast kommentarförfattaren, inläggets ägare eller en administratör kan ta bort kommentaren.

* **Metod:** `DELETE`
* **Query Parameter:** `action=deleteComment`
* **Headers:** `X-User-Token: <din_token>`

#### Request Body
```json
{
  "comment_id": 8
}
```

#### Svar (200 OK)
```json
{
  "status": "success",
  "message": "Kommentaren har tagits bort!"
}
```

---

## Felhantering & Statuskoder

| Statuskod | Betydelse |
| ----- | ----- |
| `200 OK` | Förfrågan lyckades. |
| `400 Bad Request` | Felaktiga eller saknade indataparametrar (t.ex. saknad titel eller blog_id). |
| `401 Unauthorized` | Saknad eller ogiltig `X-User-Token`. |
| `403 Forbidden` | Otillräcklig behörighet (t.ex. försök att redigera/radera en annan användares blogg). |
| `404 Not Found` | Bloggen, inlägget eller kommentaren hittades inte. |
| `405 Method Not Allowed` | Ogiltig HTTP-metod anropades. |
| `500 Internal Server Error` | Databasfel eller serverrelaterade problem. |
```

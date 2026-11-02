# Prenumerations-API Dokumentation

Detta API hanterar tilldelning och borttagning av tjänstebehörigheter (prenumerationer) för tjänsterna `wiki`, `blog` och `calendar`.

## Bas-URL & Autentisering

Alla anrop görs mot prenumerationstjänstens huvudfil:
`POST /services/prem/prem.php?action={ACTION}`

### HTTP-Headers och Tokens

Anrop kräver giltig autentisering på root_admin nivå:

| Header | Beskrivning |
| ----- | ----- |
| `X-User-Token` | **Användartoken** (erhålls via `loginUser`). Identifierar användaren som utför handlingen. |

### Åtkomstkontroll

Alla funktioner i prenumerations-API:et kräver rollen **`root_admin`**.

---

## Översikt över Actions

* [`sendPrem`](#sendprem)
* [`deletePrem`](#deleteprem)

---

## 1. Prenumerationshantering

### `sendPrem`

Tilldelar och aktiverar tillgång till en specifik tjänst (`wiki`, `blog` eller `calendar`).

* **Metod:** `POST`
* **Query Parameter:** `action=sendPrem`
* **Headers:** `X-User-Token`

#### Tillåtna värden för `service`
* `wiki`
* `blog`
* `calendar` (eller `calender`)

#### Request Body
```json
{
  "service": "calendar"
}
```

#### Svar (200 OK)
```json
{
  "status": "success",
  "message": "Successfully provisioned access to the calendar service.",
  "service": "calendar",
  "activated_at": "2026-11-02 12:00:00"
}
```

---

### `deletePrem`

Tar bort och inaktiverar tillgång till en specifik tjänst (`wiki`, `blog` eller `calendar`).

* **Metod:** `POST`
* **Query Parameter:** `action=deletePrem`
* **Headers:** `X-User-Token: <root_admin_token>`

#### Tillåtna värden för `service`
* `wiki`
* `blog`
* `calendar` (eller `calender`)

#### Request Body
```json
{
  "service": "wiki"
}
```

#### Svar (200 OK)
```json
{
  "status": "success",
  "message": "Successfully deleted access to the wiki service.",
  "service": "wiki",
  "canceled_at": "2026-11-02 12:00:00"
}
```

---

## Felhantering & Statuskoder

| Statuskod | Betydelse |
| ----- | ----- |
| `200 OK` | Förfrågan lyckades. |
| `400 Bad Request` | Felaktig eller ogiltig tjänst angiven i request body. |
| `401 Unauthorized` | Saknad eller ogiltig användartoken i `X-User-Token`. |
| `403 Forbidden` | Otillräcklig behörighet (endast `root_admin` är tillåten) eller ogiltig företagssession. |
| `404 Not Found` | Okänd `action`. |
| `500 Internal Server Error` | Databasfel eller serverfel. |
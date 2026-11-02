# Företags-API Dokumentation

Detta API hanterar administration av företagskonton, kontextväxling samt koppling mot den externa leverantören.

## Bas-URL & Autentisering

Alla anrop görs mot företagstjänstens huvudfil:
`POST /services/company/company.php?action={ACTION}`

### Åtkomstkontroll

Alla funktioner i `company.php` kräver behörighetsnivån **`root_admin`**.

---

## Översikt över Actions

  * [`addCompany`](#addcompany)
  * [`editCompany`](#editcompany)
  * [`changeCompany`](#changecompany)

---

## 1. Företagsadministration

### `addCompany`

Skapar ett nytt företagskonto i systemet med användarnamn och ett säkert hashat lösenord.

* **Metod:** `POST`
* **Query Parameter:** `action=addCompany`

#### Request Body
```json
{
  "username": "username",
  "password": "lösenord"
}
```

#### Svar (201 Created)
```json
{
  "success": true,
  "company_id": 1,
  "username": "username"
}
```

---

### `editCompany`

Uppdaterar användarnamn och/eller lösenord för ett befintligt företagskonto.

* **Metod:** `PUT` eller `POST`
* **Query Parameter:** `action=editCompany`

#### Request Body
```json
{
  "company_id": 1,
  "username": "username_nytt",
  "password": "nytt_lösenord"
}
```

#### Svar (200 OK)
```json
{
  "success": true,
  "company_id": 1,
  "message": "Company updated successfully"
}
```

---

### `changeCompany`

Växlar det aktiva systemomfattande företags-ID:t (`COMPANY_ID`) genom att slå upp kontot via användarnamnet och uppdatera konfigurationsfilen (`current_company.json`). Ingen session används.

* **Metod:** `POST`
* **Query Parameter:** `action=changeCompany`

#### Request Body
```json
{
  "username": "username"
}
```

#### Svar (200 OK)
```json
{
  "success": true,
  "message": "Active COMPANY_ID updated successfully",
  "company_id": 1,
  "username": "username"
}
```

---

## Felhantering & Statuskoder

| Statuskod | Betydelse |
| ----- | ----- |
| `200 OK` | Förfrågan lyckades. |
| `201 Created` | Företagskontot har skapats. |
| `400 Bad Request` | Felaktigt eller saknat användarnamn eller lösenord. |
| `401 Unauthorized` | Inloggning krävs. |
| `403 Forbidden` | Otillräcklig behörighet. |
| `404 Not Found` | Företaget hittades inte eller okänd `action`. |
| `409 Conflict` | Användarnamnet är redan registrerat. |
| `500 Internal Server Error` | Misslyckades att skriva till `current_company.json` eller databasfel. |
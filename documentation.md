# Calendar API - User Guide

This guide is for developers who want to create events, invite colleagues and handle suggestions through the calendar API. To use it you first need a session key from `/login` (see `USER.md`).

> **About the examples:** values in angle brackets, such as `<event_id>`, are placeholders. Replace them, brackets included, with your own values. They are listed in the table below.

| Placeholder | What to put there |
|---|---|
| `<session_key>` | The `session_key` you got from `POST /login` |
| `<user_id>` | The ID of the employee using your app (from your `user` table) |
| `<employee_id>` | The ID of an employee you want to invite |
| `<event_id>` | The number of an event (`ID` in the `getEvents` answer) |
| `<suggestion_id>` | The number of a suggestion (`suggestion_id` in the `getSuggestions` answer) |
| `<start_time>`, `<end_time>` | A date and time in the format `YYYY-MM-DD HH:MM:SS` |
| `<title>`, `<text>` | Your own text |

## 📋 Overview

All calls go to one URL. You choose what to do with `?action=` in the URL.

**Endpoint:** `http://host/services/calender/calender.php?action=ACTION_NAME`

| # | Action | Method | What it does | Who can do it |
|---|---|---|---|---|
| 1 | `getEvents` | GET | List events | Anyone with the key |
| 2 | `createNewEvent` | POST | Create an event | Any employee (becomes the owner) |
| 3 | `editEvent` | PUT | Change an event | The owner |
| 4 | `getParticipants` | GET | List who is invited | Anyone with the key |
| 5 | `addParticipants` | POST | Invite more employees | The owner |
| 6 | `editParticipants` | PUT | Replace the guest list | The owner |
| 7 | `getSuggestions` | GET | List suggestions on an event | Anyone with the key |
| 8 | `createSuggestion` | POST | Write a suggestion | Participants |
| 9 | `editSuggestion` | PUT | Edit a suggestion | The author |
| 10 | `deleteSuggestion` | DELETE | Remove a suggestion | The author or the owner |

All answers are JSON. Dates and times always use the format `YYYY-MM-DD HH:MM:SS`.

The edit actions (`editEvent`, `editParticipants`, `editSuggestion`) and `deleteSuggestion` also accept POST. `createSuggestion` can also be called `addSuggestion`.

---

## 🔐 Authentication

Every calendar call needs two headers:

| Header | What it is | Where you get it |
|---|---|---|
| `X-Session-Key` | The shared company key | `session_key` from `POST /login`. The account must include the calendar service (`services` = 1, 3, 5 or 7) |
| `X-User-Id` | The employee making the call | The employee's `ID` in your own `user` table |

> **Note:** the `user_id` in the `/login` answer belongs to the company account, not to an employee. The calendar does not use it.

The company key only proves that your app may use the calendar. It cannot say which employee is using your app, so you tell the API with `X-User-Id`. The API uses it to decide who owns a new event and who may change what.

Instead of headers you can also send `session_key` and `user_id` in the URL (`?session_key=<session_key>&user_id=<user_id>`) or in the JSON body, but headers are recommended.

### Setup used in the examples

**cURL:** the examples read the key and the employee from two variables.
```bash
export SESSION_KEY="<session_key>"
export USER_ID="<user_id>"
```

**JavaScript:** the examples use this small helper.
```javascript
const BASE = 'http://host/services/calender/calender.php';
const sessionKey = '<session_key>'; // from /login
const userId = '<user_id>';         // the employee using your app

async function calendar(action, { method = 'GET', params = {}, body } = {}) {
  const query = new URLSearchParams({ action, ...params });
  const res = await fetch(`${BASE}?${query}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-Session-Key': sessionKey,
      'X-User-Id': String(userId)
    },
    body: body ? JSON.stringify(body) : undefined
  });
  return res.json();
}
```

In the JavaScript examples, names like `eventId`, `title` or `startTime` are variables that hold the values from your app.

**If the key is missing, expired or has no calendar access:**
```json
{
  "error": "no access"
}
```

---

## 📅 Events

### 1. List events - GET getEvents

Returns the events of the company, earliest first.

**Endpoint:** `GET http://host/services/calender/calender.php?action=getEvents`

**Optional URL parameters:**

| Parameter | What it does |
|---|---|
| `user_id` | Only events that this employee takes part in |
| `from` | Only events that end after this time (repeating events stay until their rule ends) |
| `to` | Only events that start before this time |

**Example with cURL:** (write the space between date and time as `%20`)
```bash
curl "http://host/services/calender/calender.php?action=getEvents&user_id=<employee_id>&from=<start_time>&to=<end_time>" \
  -H "X-Session-Key: $SESSION_KEY" -H "X-User-Id: $USER_ID"
```

**Example with JavaScript/Fetch:**
```javascript
const data = await calendar('getEvents', {
  params: { user_id: employeeId, from: fromTime, to: toTime }
});
console.log(data.events);
```

**Response:**
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

- `ID` is the event number. Use it as `event_id` in the other calls.
- `frequency`, `interval_step` and `recurrence_end_date` are `null` if the event does not repeat.
- A repeating event is returned once (first occurrence plus the rule). Your app creates the repeats, see [Repeating events](#-repeating-events).

---

### 2. Create an event - POST createNewEvent

The employee in `X-User-Id` becomes the owner and is invited automatically.

**Endpoint:** `POST http://host/services/calender/calender.php?action=createNewEvent`

**What to send:**
```json
{
  "title": "<title>",
  "content": { "<key>": "<value>" },
  "start_time": "<start_time>",
  "end_time": "<end_time>",
  "recurrence": { "frequency": "<frequency>", "interval_step": <interval_step>, "end_date": "<end_date>" },
  "participants": [<employee_id>, <employee_id>]
}
```

| Field | Required | Description |
|---|---|---|
| `title` | Yes | Up to 255 characters |
| `start_time`, `end_time` | Yes | `end_time` must be after `start_time` |
| `content` | No | Any JSON, for example a description |
| `recurrence` | No | Makes the event repeat, see [Repeating events](#-repeating-events) |
| `participants` | No | Employee IDs to invite |

**Example with cURL:**
```bash
curl -X POST "http://host/services/calender/calender.php?action=createNewEvent" \
  -H "Content-Type: application/json" \
  -H "X-Session-Key: $SESSION_KEY" -H "X-User-Id: $USER_ID" \
  -d '{"title":"<title>","start_time":"<start_time>","end_time":"<end_time>","participants":[<employee_id>]}'
```

**Example with JavaScript/Fetch:**
```javascript
const result = await calendar('createNewEvent', {
  method: 'POST',
  body: {
    title,
    start_time: startTime,
    end_time: endTime,
    participants: employeeIds
  }
});
console.log(result.event_id);
```

**Response (201):**
```json
{
  "message": "event created",
  "event_id": <event_id>
}
```

Everything is saved together: if one part fails (for example an employee ID that does not exist), nothing is saved.

---

### 3. Change an event - PUT editEvent

Owner only. Send `event_id` and only the fields you want to change.

**Endpoint:** `PUT http://host/services/calender/calender.php?action=editEvent`

**What to send:**
```json
{
  "event_id": <event_id>,
  "title": "<title>",
  "start_time": "<start_time>",
  "end_time": "<end_time>"
}
```

You can change `title`, `content`, `start_time`, `end_time` and `recurrence`. Fields you leave out stay as they are. Two special values:

- `"recurrence": null` makes the event stop repeating (an object replaces the old rule).
- `"content": null` clears the description.

**Example with cURL:**
```bash
curl -X PUT "http://host/services/calender/calender.php?action=editEvent" \
  -H "Content-Type: application/json" \
  -H "X-Session-Key: $SESSION_KEY" -H "X-User-Id: $USER_ID" \
  -d '{"event_id":<event_id>,"start_time":"<start_time>","end_time":"<end_time>"}'
```

**Example with JavaScript/Fetch:**
```javascript
await calendar('editEvent', {
  method: 'PUT',
  body: { event_id: eventId, start_time: startTime, end_time: endTime }
});
```

**Response:**
```json
{
  "message": "event updated"
}
```

---

## 👥 Participants

### 4. See who is invited - GET getParticipants

**Endpoint:** `GET http://host/services/calender/calender.php?action=getParticipants&event_id=<event_id>`

**Example with cURL:**
```bash
curl "http://host/services/calender/calender.php?action=getParticipants&event_id=<event_id>" \
  -H "X-Session-Key: $SESSION_KEY" -H "X-User-Id: $USER_ID"
```

**Example with JavaScript/Fetch:**
```javascript
const data = await calendar('getParticipants', { params: { event_id: eventId } });
console.log(data.participants);
```

**Response:**
```json
{
  "participants": [
    {
      "participant_id": <participant_id>,
      "user_id": <employee_id>,
      "username": "<username>",
      "pfp_file": "<file or null>"
    }
  ]
}
```

---

### 5. Invite more employees - POST addParticipants

Owner only. Employees who are already invited are skipped.

**Endpoint:** `POST http://host/services/calender/calender.php?action=addParticipants`

**What to send:**
```json
{
  "event_id": <event_id>,
  "user_ids": [<employee_id>, <employee_id>]
}
```

**Example with cURL:**
```bash
curl -X POST "http://host/services/calender/calender.php?action=addParticipants" \
  -H "Content-Type: application/json" \
  -H "X-Session-Key: $SESSION_KEY" -H "X-User-Id: $USER_ID" \
  -d '{"event_id":<event_id>,"user_ids":[<employee_id>]}'
```

**Example with JavaScript/Fetch:**
```javascript
const result = await calendar('addParticipants', {
  method: 'POST',
  body: { event_id: eventId, user_ids: employeeIds }
});
console.log(result.added);
```

**Response:**
```json
{
  "message": "participants added",
  "added": [<employee_id>, <employee_id>]
}
```

`added` lists only the employees that were really added now.

---

### 6. Replace the guest list - PUT editParticipants

Owner only. The list you send **replaces** the whole guest list: anyone not in it is removed. The owner always stays. To add people without touching the rest, use `addParticipants`.

**Endpoint:** `PUT http://host/services/calender/calender.php?action=editParticipants`

**What to send:**
```json
{
  "event_id": <event_id>,
  "user_ids": [<employee_id>, <employee_id>]
}
```

**Example with cURL:**
```bash
curl -X PUT "http://host/services/calender/calender.php?action=editParticipants" \
  -H "Content-Type: application/json" \
  -H "X-Session-Key: $SESSION_KEY" -H "X-User-Id: $USER_ID" \
  -d '{"event_id":<event_id>,"user_ids":[<employee_id>]}'
```

**Example with JavaScript/Fetch:**
```javascript
await calendar('editParticipants', {
  method: 'PUT',
  body: { event_id: eventId, user_ids: employeeIds }
});
```

**Response:**
```json
{
  "message": "participants updated"
}
```

---

## 💬 Suggestions

Suggestions are short comments from participants on an event.

### 7. Read suggestions - GET getSuggestions

**Endpoint:** `GET http://host/services/calender/calender.php?action=getSuggestions&event_id=<event_id>`

**Example with cURL:**
```bash
curl "http://host/services/calender/calender.php?action=getSuggestions&event_id=<event_id>" \
  -H "X-Session-Key: $SESSION_KEY" -H "X-User-Id: $USER_ID"
```

**Example with JavaScript/Fetch:**
```javascript
const data = await calendar('getSuggestions', { params: { event_id: eventId } });
console.log(data.suggestions);
```

**Response (oldest first):**
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

### 8. Write a suggestion - POST createSuggestion

Participants only (the owner counts as one).

**Endpoint:** `POST http://host/services/calender/calender.php?action=createSuggestion`

**What to send:**
```json
{
  "event_id": <event_id>,
  "text": "<text>"
}
```

`text` is required, up to 5000 characters.

**Example with cURL:**
```bash
curl -X POST "http://host/services/calender/calender.php?action=createSuggestion" \
  -H "Content-Type: application/json" \
  -H "X-Session-Key: $SESSION_KEY" -H "X-User-Id: $USER_ID" \
  -d '{"event_id":<event_id>,"text":"<text>"}'
```

**Example with JavaScript/Fetch:**
```javascript
const result = await calendar('createSuggestion', {
  method: 'POST',
  body: { event_id: eventId, text }
});
console.log(result.suggestion_id);
```

**Response (201):**
```json
{
  "message": "suggestion added",
  "suggestion_id": <suggestion_id>
}
```

---

### 9. Edit a suggestion - PUT editSuggestion

Author only, and the author must still be a participant.

**Endpoint:** `PUT http://host/services/calender/calender.php?action=editSuggestion`

**What to send:**
```json
{
  "suggestion_id": <suggestion_id>,
  "text": "<text>"
}
```

**Example with cURL:**
```bash
curl -X PUT "http://host/services/calender/calender.php?action=editSuggestion" \
  -H "Content-Type: application/json" \
  -H "X-Session-Key: $SESSION_KEY" -H "X-User-Id: $USER_ID" \
  -d '{"suggestion_id":<suggestion_id>,"text":"<text>"}'
```

**Example with JavaScript/Fetch:**
```javascript
await calendar('editSuggestion', {
  method: 'PUT',
  body: { suggestion_id: suggestionId, text }
});
```

**Response:**
```json
{
  "message": "suggestion updated"
}
```

---

### 10. Remove a suggestion - DELETE deleteSuggestion

The author or the owner of the event.

**Endpoint:** `DELETE http://host/services/calender/calender.php?action=deleteSuggestion&suggestion_id=<suggestion_id>`

`suggestion_id` goes in the URL. (Sending it in a JSON body also works.)

**Example with cURL:**
```bash
curl -X DELETE "http://host/services/calender/calender.php?action=deleteSuggestion&suggestion_id=<suggestion_id>" \
  -H "X-Session-Key: $SESSION_KEY" -H "X-User-Id: $USER_ID"
```

**Example with JavaScript/Fetch:**
```javascript
await calendar('deleteSuggestion', { method: 'DELETE', params: { suggestion_id: suggestionId } });
```

**Response:**
```json
{
  "message": "suggestion deleted"
}
```

---

## 🔁 Repeating events

To make an event repeat, send a `recurrence` object in `createNewEvent` or `editEvent`:

```json
{ "frequency": "<frequency>", "interval_step": <interval_step>, "end_date": "<end_date>" }
```

| Field | Description |
|---|---|
| `frequency` | `daily`, `weekly`, `monthly` or `yearly` (lowercase) |
| `interval_step` | Every N units. `2` with `weekly` means every second week. Default `1` |
| `end_date` | When the repeating stops (`YYYY-MM-DD HH:MM:SS`). `null` means it repeats forever |

Examples: `daily` with step 1 is every day, `weekly` with step 2 is every second week, and `monthly` with step 1 and an end date is every month until that date.

**The API does not create the repeats.** `getEvents` returns one event with its rule. Your app calculates the repeats: start from the event's `start_time` and `end_time`, add `interval_step` units of `frequency` again and again, and stop at `recurrence_end_date` (or at the end of the period you are showing, if it is `null`).

Changing a repeating event changes all its repeats, not a single day.

---

## 📱 Common use cases

The variables in these examples (`title`, `startTime`, `eventId` and so on) hold values from your app.

### Use Case 1: Create a repeating event and invite colleagues

```javascript
// 1. Create the event (the employee in userId becomes the owner)
const created = await calendar('createNewEvent', {
  method: 'POST',
  body: {
    title,
    start_time: startTime,
    end_time: endTime,
    recurrence: { frequency, interval_step: intervalStep, end_date: null },
    participants: employeeIds
  }
});

if (created.error) {
  console.log('❌ Could not create the event:', created.error);
} else {
  // 2. Invite one more colleague later
  await calendar('addParticipants', {
    method: 'POST',
    body: { event_id: created.event_id, user_ids: [extraEmployeeId] }
  });
  console.log('✅ Event created and colleague invited');
}
```

### Use Case 2: Show one employee's calendar for a period

```javascript
const data = await calendar('getEvents', {
  params: { user_id: employeeId, from: fromTime, to: toTime }
});

for (const event of data.events) {
  const repeats = event.frequency
    ? ` (repeats ${event.frequency}, every ${event.interval_step})`
    : '';
  console.log(`${event.start_time} - ${event.title}${repeats}, owner: ${event.owner_username}`);
}
```

### Use Case 3: Discuss a time change with suggestions

```javascript
// An invited employee proposes something (their ID is in X-User-Id)
await calendar('createSuggestion', {
  method: 'POST',
  body: { event_id: eventId, text }
});

// The owner reads the suggestions and changes the event
const { suggestions } = await calendar('getSuggestions', { params: { event_id: eventId } });
console.log(suggestions);

await calendar('editEvent', {
  method: 'PUT',
  body: { event_id: eventId, start_time: newStartTime, end_time: newEndTime }
});
```

In a real app the invited employee and the owner are different users, so each call uses that employee's `X-User-Id`.

---

## ⏱️ Session timeout

- 🕐 **Timeout:** the session key expires after 30 minutes of inactivity (see `USER.md`).
- 🔄 **Auto-renew:** every calendar call checks your key with `/verify`, so each call also renews the key for another 30 minutes.
- ⚠️ **Expired key:** you get `"no access"` and must log in again with `/login`.

---

## 🆘 Troubleshooting

### Status codes

| Code | Meaning |
|---|---|
| 200 | Success |
| 201 | Event or suggestion created |
| 400 | Missing or invalid input, or the `user_id` is missing or unknown |
| 403 | Bad key, or you are not allowed to do this (not the owner, not a participant, not the author) |
| 404 | Unknown action, event or suggestion |
| 405 | Wrong HTTP method for the action |
| 500 | Unexpected database error while saving |

Errors always look like this:
```json
{
  "error": "message"
}
```

### Error messages

| Message | Cause | What to do |
|---|---|---|
| `no access` | The key is missing, wrong or expired, or the account has no calendar service | Log in again with `/login`. Check that `services` includes 1 |
| `user_id (the employee making the request) is required` | No `X-User-Id` was sent | Send the header on every call |
| `unknown user_id` | The employee does not exist in your `user` table | Check the ID |
| `unknown user ids` | Someone in `participants` or `user_ids` is not a registered employee | See `unknown_user_ids` in the answer for which numbers |
| `only the owner of the event can do this` | You called `editEvent`, `addParticipants` or `editParticipants` as someone other than the owner | Use the owner's ID (`owner_id` in `getEvents`) |
| `only participants of the event can do this` | You are not on the guest list, so you cannot write suggestions | Ask the owner to invite you with `addParticipants` |
| `you can only edit your own suggestions` | The suggestion was written by someone else | Write your own suggestion instead |
| `only the author or the owner of the event can delete a suggestion` | You are neither the writer nor the owner | Ask one of them |
| `end_time must be after start_time` | The times are in the wrong order. When you edit only one time, it is compared with the saved value of the other | Check both times |
| `title is required (max 255 characters)` | The title is empty or too long | Send a title of 1-255 characters |
| `frequency must be one of: daily, weekly, monthly, yearly` | The repeat type is not one of the four | Use one of them, in lowercase |
| `event not found` / `suggestion not found` | The number is wrong or it was removed. Deleting an employee also deletes their events and suggestions | Check the number |
| `use GET`, `use POST`, `use PUT or POST` or `use DELETE or POST` | The action needs another HTTP method | See the method column in the table at the top |

---

## 🔒 Security tips

✅ **Do this:**
- Keep the session key on your server (your backend) and let the browser talk to your backend
- Check who the employee is (for example with your own login) before you send their `X-User-Id`
- Use HTTPS, never plain HTTP
- Treat the session key like a password

⛔ **Don't do this:**
- Put the session key in JavaScript that runs in the browser: anyone could then read and change every event
- Let the browser choose its own `X-User-Id`: the API believes the ID it receives, so one employee could pretend to be another
- Share the session key with people who should not have access to the calendar
- Hardcode the key in your code
# Blogg API – Bruno collection

Tests for `services/bloggapi.php`.

## Setup
1. In Bruno: **Open Collection** and pick this folder.
2. Select the **Local** environment and edit it:
   - `baseUrl`: full URL to `bloggapi.php`
   - `token`: X-User-Token for user 1 (owns everything created)
   - `token2`: X-User-Token for a *different, non-admin* user (used for the 403 tests)
3. Run the whole collection with the **Runner**. Folders and requests run in order and pass IDs along through variables (`blogId`, `pageId`, `imagePageId`, `commentId`).

## Folders
| Folder | What it covers |
|---|---|
| 1 - Blogs | list, create, update, ownership/404/400 checks |
| 2 - Posts | create (plain, image, bad file), all GET lookups, update, 403/400/404 |
| 3 - Comments | add (by id / by title), update, delete, 403/400 |
| 4 - Delete | delete posts and blog, then 404 on the gone blog |
| 5 - Errors and CORS | missing/invalid token, unknown action, 405, OPTIONS |

## Notes
- Post `content` is sent as a JSON string because the API does `json_decode` on it when reading.
- `getBlog` searches by **post** title (that is how the endpoint is written), so it lives in the Posts folder.
- The Update/Delete 403 tests need `token2` to belong to a user who is not an admin.
- If you run a single request on its own, run **Create blog** and **Create post** first so the variables exist.

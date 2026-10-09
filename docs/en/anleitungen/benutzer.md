# Users in the household

**English** · [Deutsch](../../anleitungen/benutzer.md)

[← Compendium index](../README.md)

Since **v3.2.0** every person in the household can sign in on their own — with
their own name, their own experience level and their own language. Everyone
shares the household’s data: meters, readings, contracts and insights exist
only once. Two roles decide who manages access and who “just” records.

People only exist **with sign-in switched on**. Without sign-in everything
stays as before: whoever reaches the address may do everything, and the
experience level applies to the whole installation. When sign-in is worth it
is explained in
[Security & network operation](../betrieb/sicherheit.md#1-the-operating-model-in-one-sentence).

---

## 1. Two ways: password or proxy

| Mode | Who signs people in | How people are created |
|---|---|---|
| **Password sign-in** | the Energietracker itself: name and password | someone who manages the installation adds them under Settings → Access |
| **Sign-in via upstream proxy** | a sign-in service in front of the app, such as Authelia, Authentik or Home Assistant under Ingress | automatically on the first visit, under the name the proxy reports |

Switching it on: the password under Settings → Access → “Sign-in & access”
([Security § 3](../betrieb/sicherheit.md#3-switching-sign-in-on)), the proxy
with `ET_AUTH=proxy` and `ET_TRUSTED_PROXIES`
([Security § 6](../betrieb/sicherheit.md#6-sign-in-via-an-upstream-proxy)).

The page **Settings → Access** belongs to the “Expert” level. At a lower level
it is missing from the navigation; the address `#/settings/access` still opens
it — with a note and the button to switch
([Experience levels](../einstieg/einrichtung.md#3-experience-levels)).

---

## 2. The first person who manages

**With a password.** The password you switch sign-in on with belongs to the
person **“admin”** with the role “Admin”. This also applies to installations
that already had a password before v3.2.0: after the update nothing changes as
long as nobody adds further people — sign-in only asks for the password.

With the first additional person, sign-in also asks for the **name**. “admin”
then signs in with the name “admin” or with an empty name field and the
previous password.

Prefer a name of your own? Add yourself as a person with the role “Admin”,
sign in with it and delete “admin”. After that, signing in without a name no
longer works.

If the password is set in the environment variable `ET_ADMIN_PASSWORD_HASH`,
it belongs to “admin” as well. The app cannot change it then, and “admin”
cannot be deleted.

**With a proxy.** Whoever arrives first through the proxy manages; everyone
after that is a member. Roles can be changed afterwards under Settings →
Access. A person “admin” from earlier password operation stays in the list but
does not count as an admin in proxy operation.

---

## 3. Adding people

Only in password mode and only for someone who manages the installation:

1. **Settings → Access → “Users and roles”**.
2. Under **“Add a person”**:
   - **Name** — up to 40 characters. Upper and lower case do not matter when
     signing in; two people with the same name are not possible.
   - **New password** — at least 8 characters.
   - **Role** — “Member” (preselected) or “Admin”.
3. **“Add”.** The app reports “… can now sign in.”

The table above lists everyone with their role; “(you)” marks yourself. You
change a role directly in the row’s selector.

**Signing in:** the sign-in screen asks for “Name” and “Password”. Each
browser then stays signed in for 30 days; “Sign out” is in the top bar.

**Deleting:** “Delete person” in the row. The person can no longer sign in,
their sessions end at once; the household’s data stays. Your own person is
deleted by another person who manages. In proxy operation deleting does not
lock anyone out: if the person comes back through the proxy, the app creates
them again — locking out is the proxy’s job.

---

## 4. Admin and member

| | Admin | Member |
|---|---|---|
| record and change meter readings, deliveries, contracts, bills, reminders | ✓ | ✓ |
| see everything, annual report, CSV export, download a backup | ✓ | ✓ |
| household settings (floor area, utilities, weather, calculation parameters …) | ✓ | ✓ |
| own experience level, own language, own password | ✓ | ✓ |
| add people, change roles, reset passwords, delete people | ✓ | — |
| switch sign-in on and off, API keys, Home Assistant token | ✓ | — |
| restore a backup, restore a snapshot, load a sample household | ✓ | — |
| addresses in the network: embedding, text recognition, evcc | ✓ | — |

Restoring and loading replace all data; the addresses make the server talk to
other machines. That is why they are reserved for admins. If a member tries
anyway, the app answers “Only someone who manages this installation may do
that.” Under Settings → Access, members only see the note on who manages
access.

**At least one person manages.** The last person with the role “Admin” can
neither be made a member nor be deleted.

**API keys belong to no person.** A key with the permission “Manage” may do
everything an admin may; “Read” may only fetch data
([Security § 5](../betrieb/sicherheit.md#5-api-keys-for-scripts)).

---

## 5. Forgotten password

**Someone who manages resets it:** Settings → Access → “People in the
household” → “Reset password” in the row → new password (at least 8
characters) → “Save”. For “admin” this changes the password for signing in
without a name. People from proxy operation have no password here; they
change it at the sign-in service.

A new password signs the person out on every device — after a lost device,
that locks it out for sure. The other people stay signed in.

**If the only person who manages forgets their password,** only the server
helps:

1. In `data/auth.json` set the value of `"mode"` to `"off"` (in the Docker
   container `ET_AUTH=off` does the same). The app is then reachable without
   sign-in — with all rights.
2. Look up the person’s ID and reset the password:

   ```bash
   curl https://energie.example.org/api.php/api/users
   curl -X PATCH -H "Content-Type: application/json" \
     -d '{"password": "new-password"}' \
     https://energie.example.org/api.php/api/users/u_1a2b3c4d
   ```

3. Set `"mode"` back to `"password"` (or remove `ET_AUTH=off`).

---

## 6. Your own account: password, level, language

Every person sets these under **Settings → General**:

- **Password** — card “My account: …” with your own role: enter “Current
  password” and “New password” → “Change password”. This browser stays signed
  in; your other devices sign in again. In proxy operation the card says to change the password at the service
  in front of the app.
- **Experience level** — card “Experience level and setup” or the selector in
  the top bar. With sign-in it is your level on all your devices (“Applies to
  you (…) on all devices.”); the installation’s level stays as it is.
- **Language** — “Language on this device” in the card “Language & country”.
  With sign-in it becomes your language on all your devices. The “Default
  language of the installation” still applies to the annual report, CSV
  files, Home Assistant and everyone who has not chosen one.

Anyone without a level or language of their own sees the installation’s.

---

## 7. What is not in the backup

People, roles, password hashes and each person’s own settings are stored in
`data/auth.json` — like the password, API keys and the Home Assistant token.
This file is **not** part of the backup or the snapshots:

- Restoring a backup does not change any people.
- After moving with a backup, set up sign-in and people again. Moving the
  whole data directory (e.g. the Docker volume) takes them along.
- Users need no schema step; the household data stays as it is.

---

## 8. If something does not work

| Message | Cause and fix |
|---|---|
| “Only someone who manages this installation may do that.” | A member tried an admin task (table in § 4). Someone who manages does it — or changes the role. |
| “At least one person must manage the installation.” | The last person with “Admin” was to become a member or be deleted. Make a second person an admin first. |
| “You cannot delete yourself — …” | Another person who manages deletes you. |
| “This name already exists.” | Names are unique, regardless of upper and lower case. |
| “The name must not be empty and may have at most 40 characters.” | Shorten or fill in the name. |
| “The password needs at least 8 characters.” | Choose a longer password. |
| “The role must be “Admin” or “Member” (API: admin, member).” | A different role was sent via the API. |
| “This person no longer exists.” | Someone deleted them in the meantime. Reload the page. |
| “Wrong password.” | Name or password is wrong — the app deliberately does not say which. |
| “Too many failed attempts – please wait 5 minutes.” | Five failed attempts within 15 minutes lock sign-in for 5 minutes — for the whole installation, not per person. |
| “When you sign in through the service in front of the app, change the password there.” | In proxy operation the app knows no password. |
| “The password is set via the environment (ET_ADMIN_PASSWORD_HASH) and cannot be changed here.” | The password of “admin” is in the environment; change it there. “admin” cannot be deleted then either. |
| “Without sign-in there are no personal settings — the level then applies to the whole installation.” | Personal settings via the API without a signed-in person (e.g. with an API key). |

---

## 9. Via the API

```text
GET    /api/session               mode, authenticated, … plus named_login, user, role
POST   /api/session               sign in {name?, password}
PATCH  /api/session/me            own settings {ui_level?, language?} — null = like the installation
POST   /api/session/me/password   own password {current, password}
GET    /api/users                 people (admins only)
POST   /api/users                 {name, password, role: admin|member}
PATCH  /api/users/{id}            {name?, role?, password?}
DELETE /api/users/{id}
```

`named_login` is `true` as soon as sign-in asks for the name. Status codes and
error codes (`errors.users.*`, `errors.auth.adminOnly`):
[API reference](../referenz/api.md).

---

[← Compendium index](../README.md)

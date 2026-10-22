# Users in the household

**English** · [Deutsch](../../anleitungen/benutzer.md)

[← Compendium index](../README.md)

Since **v3.2.0** every person in the household can sign in on their own — with
their own name, their own experience level and their own language. Everyone
shares the household’s data: meters, readings, contracts and insights exist
only once. Two roles decide who manages access and who “just” records.

People only exist **with sign-in switched on**. Without sign-in everything
stays as before: whoever reaches the address may do everything, and the
experience level applies to the whole installation. Since v3.6.0 the “Users
and roles” card says so in the app too and jumps to the “Sign-in & access”
card with “Switch sign-in on”. When sign-in is worth it
is explained in
[Security & network operation](../betrieb/sicherheit.md#1-the-operating-model-in-one-sentence).
Since **v3.6.0** the app also keeps every person in a **register of people**
with a running number, which is part of the backup (§ 7) — so the people of a
sample household can be chosen under “Responsible” even without sign-in.

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
     signing in; two people with the same name are not possible — since v3.6.0
     across all people, including those without sign-in (deleted ones do not
     count). If a person without sign-in has the name, the new account takes
     them over (see below).
   - **New password** — at least 8 characters.
   - **Role** — “Member” (preselected) or “Admin”.
3. **“Add”.** The app reports “… can now sign in.”

The table above lists everyone with their role; “(you)” marks yourself,
**“No. …”** is their running number *(v3.6.0)*. You change a role directly in
the row’s selector. **“Rename”** *(v3.6.0)* changes the name — for accounts with
a password the person then signs in with the new name. Open reminders show the
new name, completed items keep the old one. A name someone already has (even a
person without sign-in) is rejected. Accounts via a proxy have no button: the
proxy supplies their name.

**Signing in:** the sign-in screen asks for “Name” and “Password”. Each
browser then stays signed in for 30 days; “Sign out” is in the top bar.

### Deleting people

**Only admins** see “Delete person” in the row; under Settings → Access,
members only see the note that admins take care of it. Via the API the app
answers a member with **403** (`errors.auth.adminOnly`). Your own person is
deleted by another person who manages.

The person can no longer sign in, their sessions end at once; the household’s
data stays. Since **v3.6.0** also:

- **Open reminders pass to you**, the person deleting — including reminders
  with a free name that matches theirs. The dialog says beforehand how many
  and to whom.
- *(v3.7.0)* **Meters and contracts** they were responsible for pass to you as
  well; the dialog gives their number
  ([Responsible for meters and contracts](hinweise-erledigen.md#meters-and-contracts-v370)).
  Without sign-in the person stays there with “(deleted)”; with the tick
  “former member” that is shown instead.
- **Completed items keep their name** with **“(deleted)”** — in the history,
  on completed reminders, in the CSV file and in the calendar. The name stays
  as it was when the item was done. The dialog states the number of entries.
- With the tick **“former member”** (default: no) the app replaces the name on
  completed items entirely instead
  ([Ticking off notices](hinweise-erledigen.md#5-retention-clearing-privacy)).
- **The number stays taken.** The register keeps counting: the next person
  gets the next number, never that of a deleted one. Someone added later with
  the same name is a new person.
- A deleted person can no longer be assigned to a reminder, meter or contract
  and is no longer offered for selection.

In proxy operation deleting does not lock anyone out: if the person comes back
through the proxy, the app creates them again (with a new number) — locking
out is the proxy’s job. If an API key deletes, nobody takes over: open
reminders, meters and contracts then stay with “(deleted)” until someone
reassigns them.

### People without sign-in

People from a backup or a sample household have no account — they are in the
register of people but not in `auth.json`. The table shows them with their
number and the note **“no sign-in”**. Deleting works as above, with the same
handover and the same dialog (“This person will no longer be offered for
selection”).

**Changing name and role** (since v3.6.0, admins only): switch the role in the
row’s selection and the name with **“Rename”**. The name follows the same rules
as for an account (at most 40 characters, not twice — not even the name of an
account). The person’s open reminders then show the new name; completed
reminders and the history keep the name the person had when the item was done.
The role takes effect once the person gets an account; “Create account” takes
it over. A member gets `403`, as with deleting.

**“Create account”** (password mode only) enters their name and role under
“Add a person”; with a password this becomes an account that takes over the
person’s id and number — their reminders and history entries then belong to
it.

**Without sign-in** (for example after loading a sample household), Settings →
Access shows the card “Users and roles” as soon as the register knows people.
Anyone who reaches the address may use it then — like every setting without
sign-in. Because nobody is signed in, nobody takes over when deleting: open
reminders stay with “(deleted)” until someone reassigns them; the dialog says
so beforehand.

---

## 4. Admin and member

| | Admin | Member |
|---|---|---|
| record and change meter readings, deliveries, contracts, bills, reminders | ✓ | ✓ |
| see everything, annual report, CSV export, download a backup | ✓ | ✓ |
| household settings (floor area, utilities, weather, calculation parameters …) | ✓ | ✓ |
| own experience level, own language, own password | ✓ | ✓ |
| tick off notices, undo, note in the history *(v3.4.0)* | ✓ | ✓ |
| clear the history, retention of the history *(v3.4.0)* | ✓ | — |
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

Accounts, password hashes and each person’s own settings are stored in
`data/auth.json` — like the password, API keys and the Home Assistant token.
This file is **not** part of the backup or the snapshots:

- Restoring a backup does not change any accounts: whoever could sign in still
  can.
- After moving with a backup, set up sign-in and accounts again. Moving the
  whole data directory (e.g. the Docker volume) takes them along.
- Users need no schema step; the household data stays as it is.

*(v3.6.0)* The **register of people** `data/people.json`, on the other hand,
is part of the backup (pot `people`): for each person their number, name, role
and — for deleted ones — when and by whom, without passwords. Existing people
get their number on the first read after the update, in the order they were
added. A backup brings its register along; the counter does not go back. After
a move, the people from the backup can be chosen under “Responsible” and appear
under Access with “no sign-in”; if you add an account with the same name (or
use “Create account”), it takes over the person (ID and number), and their
reminders and history entries belong to them again
([People without sign-in](#people-without-sign-in)).
- *(v3.4.0)* The **history** of done notices, on the other hand, is part of the
  backup — and with it the names of the people who did something, as a
  snapshot. Whoever passes a backup on passes them on too.

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
GET    /api/users                 people (admins only), since v3.6.0 with number `no` and
                                  `has_account` (people without sign-in: `false`, `source: "none"`)
POST   /api/users                 {name, password, role: admin|member}
PATCH  /api/users/{id}            {name?, role?, password?}; v3.6.0 also for people without sign-in
                                  ({name?, role?}; a password: 400 errors.users.noAccountPassword)
GET    /api/users/{id}/handover   (v3.6.0) before deleting: {person, to, open, done};
                                  v3.7.0 plus meters, contracts
DELETE /api/users/{id}            ?anonymize_activity=1 — replace the name in the history (v3.4.0);
                                  v3.6.0: handed_over, handed_over_to, kept;
                                  v3.7.0: records_handed_over, records_kept (meters and contracts)
GET    /api/people                (v3.5.0) ID and name for “Responsible”; v3.6.0 from the register
```

`named_login` is `true` as soon as sign-in asks for the name. Status codes and
error codes (`errors.users.*`, `errors.auth.adminOnly`):
[API reference](../referenz/api.md).

---

[← Compendium index](../README.md)

# MC Team Board — features

English · [Українська](features.uk.md) · [Back to README](../README.md)

## Overview

The board shows every team as a card with its members and their positions (by default Supervisor, Leader, Vice Leader and Member). A month strip and a date picker let you look at the team composition on any day: yesterday, today or next quarter. **You can plan team composition in advance**: schedule who joins, leaves or changes position on a future date, and review the plan on the board before it takes effect. Everything you plan is stored as a period with a start date and an optional end date, so the full history of who was where is always available.

### Teams and people from EspoCRM — and your own

- **System teams and users** are read live from EspoCRM. Names, photos and activity are never copied into a separate profile.
- **Board-only teams and people** can be created for work that has no place in the CRM (a task force, a contractor). They never create a CRM `User` or `Team`.
- A CRM user can also be assigned to a board-only team for a period of time.
- **Reserve** collects active CRM users who have no system team (or no default team) and board-only people without an active assignment.
- The **All / System only / Board only** switch filters records by origin without changing anyone's actual assignment.

### Plan ahead with Draft and Confirmed

- Every planned move is either **Draft** (a proposal, shown with a pale avatar, does not change the CRM) or **Confirmed**.
- A confirmed move takes effect on its date: the person's system team membership and default team in EspoCRM are updated by a scheduled job. A move that ends a period without a successor clears the default team and puts the person in Reserve.
- A **Draft** that is still unconfirmed on its start date is not applied, and the unconfirmed Draft is removed the next day.
- Seven days before a Draft starts, editors get a system notification and a warning badge appears on the avatar. The counter next to the board title shows how many people have an unconfirmed Draft.
- Manual changes in the CRM always win: if someone's default team is changed by hand, the board adopts it from that date and sends older future plans of that person back to Draft for re-confirmation.

### Positions and history

- Each team has its own ordered list of positions. The first three can be held by one person at a time (a new holder takes over from the date and the previous one moves down the list; nothing is rewritten in the past).
- Period ends are exclusive: a move on 15 October ends the previous period on 14 October.
- The person card shows a photo or initials, a note, and a **History** table (newest first) with team, position, dates and status. A status can be changed inline.

### Working on the board

- **Drag a person** to another team or position, or into Reserve; **drag a team card header** to reorder cards. Cancelling a drag changes nothing.
- **Resize cards** the same way as dashlets on the EspoCRM Home page. Card order and sizes are personal (stored in the user's preferences); **Auto arrange teams** resets them.
- **Manage users** — a native EspoCRM-style list of CRM and board-only people with search, sorting and filters (active, Board only, System, archived). **Manage teams** — create, recolor, archive and restore board-only teams.
- A photo can be added, cropped, replaced or removed for board-only people; a CRM user's own photo always takes priority and is never changed.
- Forms, fields, date pickers and photo upload use standard EspoCRM components. The board follows the active EspoCRM theme and adapts from a wide multi-column grid to a single column on phones.

### Access control

The board uses the standard EspoCRM permission model; nothing is bypassed.

| Role | What it can do |
|---|---|
| Admin | Everything, including changes to today's composition |
| Board editor | Plans and confirms moves for **future** dates, edits people and notes |
| Viewer | Read-only: sees the board, history and notes |
| No board access, Portal users | No access; every API call is refused |

Denied actions return a clear message in the user's language. Writing to a CRM user's teams still requires the usual EspoCRM permission to edit that user.

## Requirements

- EspoCRM 10.0.8 or later (tested on 10.0.8 and 10.0.9)
- PHP 8.3 or later
- Cron configured for EspoCRM (the extension adds two scheduled jobs)

## Upgrade and build

Installation steps are in the [README](../README.md).

### Upgrading from version 1

Version 2 installs over version 1. Existing data is kept and linked to the new records; repeating the installation does not create duplicates. Take a database backup first, as for any extension upgrade.

### Build from source

```bash
npm ci
npm run extension      # creates build/team-board-2.0.0.zip
```

## Scheduled jobs

| Job (daily) | Purpose |
|---|---|
| Team Board: apply due assignments (00:10) | Applies confirmed moves on their date, ends periods that reached their end date and sends the seven-day Draft reminders |
| Team Board: clean up missed drafts (00:20) | Removes Drafts that were not confirmed on their start day |

## Known limitations

- The board shows "today" in the time zone configured in EspoCRM (Administration → Settings), the same one the scheduled jobs use — not the browser's time zone.
- Messages produced directly by EspoCRM core (for example "Access denied" on routes outside the extension) stay in English.
- Very long team names without any spaces can make the History table in the person card wider than its window; the page itself does not overflow.
- Concurrent edits of the same record by two users: the last save wins.

License: [GNU AGPL v3](../LICENSE).

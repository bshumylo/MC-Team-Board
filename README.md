# MC Team Board

**English** · [Українська](README.uk.md)

<img width="1280" height="640" alt="preview-en" src="https://github.com/user-attachments/assets/df68de7c-789c-4897-99d4-5952eebce113" />


A Kanban board of teams: columns are Teams, cards are members grouped by their
position within the team. The position list is dynamic — it is taken from each
team's `positionList` field (with a fallback set: Supervisor / Leader /
Vice Leader / Member). Drag & drop between columns changes the team, between
groups of one column changes the position, and within a group changes the
personal card order. Everything is saved instantly, with a native toast
confirmation.

## Installation

1. **Administration → Extensions** → upload `team-board-X.Y.Z.zip` → Install.
2. **Administration → Rebuild** (runs automatically, but do it manually if needed).
3. Access: **Administration → Roles** → enable the **Team Board** scope
   (enabled) for the relevant role. Administrators have access right away.
4. Menu item: **Administration → User Interface → Tab List** → add **Team Board**
   (the platform's standard navigation mechanism).
5. A **Team Board** button also appears next to **Create Team** on the Teams
   list page.

## Features

### Board

- Columns are teams; cards are users grouped by position.
- **Dynamic position list**: positions are taken from each team's `positionList`
  field; if it is empty, a fallback set is used (Supervisor / Leader /
  Vice Leader / Member). Only the top position of the hierarchy is highlighted
  with an accent bar; the rest are shown on one level.
- **Supervisors** are shown as small avatars in the top-right corner of the
  column header (tooltip with the name, click opens the profile). The supervisor
  corner appears only when the team has the Supervisor position.
- **Position visibility menu** (⋮ in the header): toggles individual positions
  on/off in the column; the choice is stored per user in Preferences
  (`teamBoardHiddenPositions`). The team counter counts only visible positions.
- **Adding a user without a team** (the “+” icon): a menu listing active users
  who belong to no team (respecting the User ACL); available only with manage
  rights.
- **Personal card order**: dragging within a single group changes the order;
  it is stored per user in Preferences (`teamBoardMemberOrder`).
- **Clickable links**: the team name opens the Team; a user's name and a
  supervisor's avatar open the User profile.
- **Unique-people counter**: the badge to the right of the “Team Board” title —
  the number of unique users across all visible teams.
- **Column order**: columns are dragged by their header; the order is stored
  per user in Preferences (the `teamBoardOrder` field).
- **Native EspoCRM UI**: theme panel classes, `EspoCRM.Ui`, EspoCRM avatars, no
  third-party libraries.
- **Mobile screen**: columns stack vertically, the header collapses a column;
  touch drag & drop is a long press (300 ms) on a card.

### Drag & drop

- Between columns — move to another team; within a column, between groups —
  change the position; within a single group — change the personal card order
  (insert-before/after indicators). Saved instantly, with a toast confirmation
  or an error message.
- **Multiple teams**: a user can belong to several teams (as in EspoCRM). Adding
  without removing from the current team — Ctrl/Alt + drop, or the “+ Team” menu
  item.
- **Removing from a team**: drag a card onto empty space outside the columns or
  onto the drop zone at the bottom of the screen. There is also a “Remove from
  team” card menu item. Only the team link is removed — the user record itself
  is never deleted.
- **Assigning a Supervisor**: drag a card onto the column header corner, or use
  the card menu. Supervisor badges are draggable too: into a group — change the
  position; off the board — remove from the team.
- The card menu is not clipped by the board (it is positioned relative to the
  window and opens upward near the bottom edge of the screen).

### Data model and access

- **Position is stored in the User↔Team relationship** — in the native `role`
  column of the `team_user` table (the same mechanism as the standard team
  position in EspoCRM: the `teamRole`/`userRole` attributes). No DB schema changes.
  A single user can hold different positions in different teams (for example,
  Supervisor in several).
- **Position ≠ Role (ACL)**: global EspoCRM roles are not touched and are not
  synchronized with positions.
- The board shows only the teams and users visible under the current user's ACL
  (the same rules as for the Team and User entities).
- **Only the top position is exclusive**: assigning a new holder automatically
  moves the previous one to the bottom position of the same team's position
  list (within one team).
- Cards can be dragged by a user with **edit rights on User**. Note: standard
  EspoCRM roles allow only `own`/`no` edit levels for User, so in practice other
  people's cards can be moved **only by an administrator**; a user with edit
  `own` can move only themselves. For everyone else the board is available in
  read-only mode.

## API

| Endpoint | Method | Description |
|---|---|---|
| `TeamBoard/data` | GET | Board data (teams, members, positions), ACL-aware |
| `TeamBoard/move` | POST | `{userId, teamId, fromTeamId?, position}` — move/change position; without `fromTeamId`, adds to the team without removing from the current one |
| `TeamBoard/removeMember` | POST | `{userId, teamId}` — remove a user from a team |

## Tests

The extension was live-tested on a real EspoCRM 10.0.0 (docker-compose in this
folder): installation and upgrade via Extensions, all API endpoints, ACL (403
without the role, read-only without move rights, edit `own` — self only),
validation (400/404), drag & drop in all scenarios, saving the column order,
mobile touch mode; the console and EspoCRM logs showed no errors.

### Automated tests (ext-template)

Integration tests are written but require a full dev instance. To run:

```bash
cd extension/team-board-src
npm install
cp config-default.json config.json   # set real DB credentials
node build --all                     # deploys a dev EspoCRM instance into ./site
npm run unit-tests
npm run integration-tests
```

## Requirements coverage

| Requirement | Implementation | Verification |
|---|---|---|
| Columns = teams, cards by the dynamic position list | `board.tpl`, `views/team-board/board.js` | manually in the UI; `BoardTest::testGetDataReturnsTeamsAndMembers` |
| Dynamic position list from `Team.positionList` | `Tools/Board/Position::listFor()`, `Service.php` | `PositionTest`, manually in the UI |
| Supervisor separate, as avatars in the header corner | `board.tpl` (`tb-sups`), only when the Supervisor position exists | manually in the UI |
| Per-user position visibility | `togglePositionVisibility()`, Preferences `teamBoardHiddenPositions` | manually in the UI |
| Per-user card order | DnD within a group, Preferences `teamBoardMemberOrder` | manually in the UI |
| Adding a user without a team | the “+” menu, `Service::findFreeUsers()` (with User ACL) | manually in the UI |
| Position in the User↔Team relationship, not on User | the `role` column of the `team_user` relation; `Tools/Board/Service.php` | `testSupervisorInMultipleTeams` |
| Role ≠ Position | positions do not read or write ACL roles | code review: `Service.php` does not touch Roles |
| DnD between columns = change team | `initDragAndDrop`/`initTouchDragAndDrop` → `POST TeamBoard/move` | `testMoveBetweenTeams` |
| DnD within a column = change Position | drop into a group of the same team | `testMoveChangesPositionWithinTeam` |
| Instant save + toast/error | `move()` → `Espo.Ui.success` / `Espo.Ui.error` | manually in the UI |
| Button next to Create Team | `clientDefs/Team.json` → `menu.list.buttons` | manually in the UI |
| Side-menu item via standard settings | `scopes/TeamBoard.json` → `"tab": true` | manually: Admin → User Interface |
| Access via standard ACL | the `TeamBoard` scope (`"acl": "boolean"`) + Team/User ACL filtering in the service | `testNoAccessWithoutAclScope`, `testAccessWithAclScope` |
| Only the top position is exclusive, previous → bottom | `Service::demoteOthers()`, `Position::topOf()`/`bottomOf()` | `testPromotingLeaderDemotesPreviousLeader` |
| Position validation | `Service::move()` | `testBadPositionIsRejected` |
| Responsiveness (desktop scroll / mobile stack + touch DnD) | media query in `board.tpl`, touch logic in the view | manually on a device |
| Native EspoCRM UI | theme panel classes, `Espo.Ui`, Espo avatars, no third-party libraries | manually in the UI |

## Version compatibility

`acceptableVersions: ">=9.3.0"`. Live-tested on
[EspoCRM 10.0.0](https://github.com/espocrm/espocrm/releases/tag/10.0.0). The
conventions used (ESM frontend, `Api\Action` backend) are current for the 9.3+
and 10.x branches.

## License

AGPL-3.0 — see [LICENSE](LICENSE).

Author: bshumylo

# MC Team Board

An [EspoCRM](https://www.espocrm.com/) extension (version **2.0.0**): a kanban-style board of teams with member positions, a timeline of assignments and drag-and-drop management.

- Reads CRM users and teams dynamically; board-only people and squads never create a CRM `User` or `Team`.
- Draft and confirmed periods, a leader/supervisor/vice-leader model, a reserve, per-user board layout.
- Roles: admin, board editor (future dates), read-only viewers; no access for users without Team Board rights or for Portal users.
- Languages: English and Ukrainian.

Requirements: EspoCRM 10.0.8 or later, PHP 8.3 or later.

## Install

Build the package and install the ZIP from **Administration → Extensions**:

```bash
npm ci
npm run extension           # creates build/team-board-2.0.0.zip
```

## License

GNU AGPL v3, see [LICENSE](LICENSE).

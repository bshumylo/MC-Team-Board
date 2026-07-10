# MC Team Board
<img width="1280" height="640" alt="cover" src="https://github.com/user-attachments/assets/446904c1-e77b-4b58-92b5-f4a1907b09e9" />

Kanban-дошка команд: колонки — команди (Teams), картки — учасники, згруповані за
позицією в команді (Supervisor / Leader / Vice Leader / Member). Drag & drop між
колонками змінює команду, між групами однієї колонки — позицію. Зберігається
одразу, з нативним toast-підтвердженням EspoCRM.

## Встановлення

1. **Administration → Extensions** → завантажити `team-board-X.Y.Z.zip` → Install.
2. **Administration → Rebuild** (виконується автоматично, але за потреби — вручну).
3. Доступ: **Administration → Roles** → у потрібній ролі увімкнути scope
   **Team Board** (enabled). Адміністратори мають доступ одразу.
4. Пункт меню: **Administration → User Interface → Tab List** → додати **Team Board**
   (стандартний механізм навігації EspoCRM).
5. Кнопка **Team Board** також з'являється поруч із **Create Team** на сторінці
   списку Teams.

## Функціонал

### Дошка

- Колонки — команди, картки — користувачі, згруповані за позицією.
- **Ієрархічний дизайн колонки**: Leader з акцентною смугою, Vice Leader і
  Members — з відступами-рівнями.
- **Supervisor-и** показуються маленькими аватарами у правому верхньому куті
  заголовка колонки (tooltip з ім'ям, клік відкриває профіль).
- **Клікабельність**: назва команди відкриває Team, ім'я користувача та аватар
  супервізора — профіль User.
- **Лічильник унікальних людей**: бейдж праворуч від назви «Team Board» —
  кількість унікальних користувачів в усіх видимих командах.
- **Порядок колонок**: колонки перетягуються за заголовок; порядок зберігається
  per-user у Preferences (поле `teamBoardOrder`).
- **Нативний UI EspoCRM**: panel-класи теми, `Espo.Ui`, аватари EspoCRM,
  без сторонніх бібліотек.
- **Мобільний екран**: колонки складаються вертикально, заголовок згортає
  колонку; drag & drop на дотику — довге натискання (300 мс) на картці.

### Drag & drop

- Між колонками — перенесення в іншу команду; в межах колонки між групами —
  зміна позиції. Збереження одразу, з toast-підтвердженням або повідомленням
  про помилку.
- **Кілька команд**: користувач може бути в кількох командах (як у EspoCRM).
  Додавання без вилучення з поточної — Ctrl/Alt + drop або пункт меню «+ Команда».
- **Викидання з команди**: перетягніть картку на порожнє поле поза колонками
  або на зону внизу екрана. Також пункт «Прибрати з команди» в меню картки.
- **Призначення Supervisor-а**: перетягніть картку на кут заголовка колонки
  або скористайтесь меню картки. Бейджі супервізорів теж перетягуються:
  у групу — зміна позиції, поза дошку — вилучення з команди.
- Меню картки не обрізається дошкою (позиціонування відносно вікна,
  біля нижнього краю екрана відкривається догори).

### Модель даних і доступ

- **Position зберігається у зв'язку User↔Team** — у нативній колонці `role`
  таблиці `team_user` (той самий механізм, що й стандартна командна позиція
  EspoCRM: атрибути `teamRole`/`userRole`). Жодних змін схеми БД. Один користувач
  може мати різні позиції в різних командах (наприклад, Supervisor у кількох).
- **Position ≠ Role (ACL)**: глобальні ролі EspoCRM не зачіпаються і не
  синхронізуються з позиціями.
- Дошка показує лише команди та користувачів, видимі за ACL поточного
  користувача (ті ж правила, що для сутностей Team і User).
- Leader і Vice Leader — ексклюзивні: призначення нового автоматично переводить
  попереднього в Member (в межах однієї команди).
- Перетягувати картки може користувач із правом **edit на User**. Увага:
  стандартні ролі EspoCRM дозволяють для User лише рівні edit `own`/`no`,
  тому фактично чужі картки може рухати **лише адміністратор**; користувач
  із edit `own` може перемістити тільки самого себе. Для інших дошка
  доступна в режимі перегляду.

## API

| Endpoint | Метод | Опис |
|---|---|---|
| `TeamBoard/data` | GET | Дані дошки (команди, учасники, позиції) з урахуванням ACL |
| `TeamBoard/move` | POST | `{userId, teamId, fromTeamId?, position}` — перенесення/зміна позиції; без `fromTeamId` — додавання в команду без вилучення з поточної |
| `TeamBoard/removeMember` | POST | `{userId, teamId}` — вилучення користувача з команди |

## Тести

Розширення протестовано наживо на реальному EspoCRM 10.0.0 (docker-compose
у цій папці): встановлення й upgrade через Extensions, усі API-ендпоінти, ACL
(403 без ролі, read-only без права move, edit `own` — лише себе), валідація
(400/404), drag & drop у всіх сценаріях, збереження порядку колонок,
мобільний touch-режим; консоль і логи EspoCRM без помилок.

### Автоматичні тести (ext-template)

Інтеграційні тести написані, але потребують повного dev-інстансу. Запуск:

```bash
cd extension/team-board-src
npm install
cp config-default.json config.json   # вказати реальні креденшели БД
node build --all                     # розгорне dev-інстанс EspoCRM у ./site
npm run unit-tests
npm run integration-tests
```

## Покриття вимог

| Вимога | Реалізація | Перевірка |
|---|---|---|
| Колонки = команди, картки за ієрархією позицій | `board.tpl`, `views/team-board/board.js` | вручну в UI; `BoardTest::testGetDataReturnsTeamsAndMembers` |
| Supervisor окремо/приглушено | група `tb-group-supervisor` (opacity) угорі колонки | вручну в UI |
| Position у зв'язку User↔Team, не в User | колонка `role` зв'язку `team_user`; `Tools/Board/Service.php` | `testSupervisorInMultipleTeams` |
| Role ≠ Position | позиції не читають і не пишуть ACL-ролі | code review: `Service.php` не звертається до Roles |
| DnD між колонками = зміна команди | `initDragAndDrop`/`initTouchDragAndDrop` → `POST TeamBoard/move` | `testMoveBetweenTeams` |
| DnD в межах колонки = зміна Position | drop у групу тієї ж команди | `testMoveChangesPositionWithinTeam` |
| Збереження одразу + toast/помилка | `move()` → `Espo.Ui.success` / `Espo.Ui.error` | вручну в UI |
| Кнопка біля Create Team | `clientDefs/Team.json` → `menu.list.buttons` | вручну в UI |
| Пункт бокового меню через стандартні налаштування | `scopes/TeamBoard.json` → `"tab": true` | вручну: Admin → User Interface |
| Доступ через стандартний ACL | scope `TeamBoard` (`"acl": "boolean"`) + фільтрація Team/User ACL у сервісі | `testNoAccessWithoutAclScope`, `testAccessWithAclScope` |
| Один Leader / Vice Leader на команду | `Service::demoteOthers()` | `testPromotingLeaderDemotesPreviousLeader` |
| Валідація позиції | `Service::move()` | `testBadPositionIsRejected` |
| Адаптивність (desktop скрол / mobile стек + touch DnD) | media query в `board.tpl`, touch-логіка у view | вручну на пристрої |
| Нативний UI EspoCRM | panel-класи теми, `Espo.Ui`, аватари EspoCRM, без сторонніх бібліотек | вручну в UI |

## Сумісність версій

`acceptableVersions: ">=9.3.0"`. Протестовано наживо на
[EspoCRM 10.0.0](https://github.com/espocrm/espocrm/releases/tag/10.0.0).
Використані конвенції (ESM frontend, `Api\Action` backend) актуальні для
гілок 9.3+ і 10.x.

## Ліцензія

GNU General Public License v2 — див. [LICENSE](LICENSE).

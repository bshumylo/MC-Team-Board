# Team Board — розширення для EspoCRM

Kanban-дошка команд: колонки — команди (Teams), картки — учасники, згруповані за
позицією в команді (Supervisor / Leader / Vice Leader / Member). Drag & drop між
колонками змінює команду, між групами однієї колонки — позицію. Зберігається
одразу, з нативним toast-підтвердженням EspoCRM.

> Сумісність: `acceptableVersions: ">=9.3.0"`, PHP `>=8.3`.

## Встановлення

1. Завантажте `team-board-x.y.z.zip` зі сторінки
   [**Releases**](https://github.com/bshumylo/Team-Board/releases).
2. **Administration → Extensions** → завантажити цей `.zip` → Install.
3. **Administration → Rebuild** (виконується автоматично, але за потреби — вручну).
4. Доступ: **Administration → Roles** → у потрібній ролі увімкнути scope
   **Team Board** (enabled). Адміністратори мають доступ одразу.
5. Пункт меню: **Administration → User Interface → Tab List** → додати **Team Board**
   (стандартний механізм навігації EspoCRM).
6. Кнопка **Team Board** також з'являється поруч із **Create Team** на сторінці
   списку Teams.

## Як це працює

- **Position зберігається у зв'язку User↔Team** — у нативній колонці `role`
  таблиці `team_user` (той самий механізм, що й стандартна командна позиція
  EspoCRM: атрибути `teamRole`/`userRole`). Жодних змін схеми БД. Один користувач
  може мати різні позиції в різних командах (наприклад, Supervisor у кількох).
- **Position ≠ Role (ACL)**: глобальні ролі EspoCRM не зачіпаються і не
  синхронізуються з позиціями.
- Дошка показує лише команди та користувачів, видимі за ACL поточного
  користувача (ті ж правила, що для сутностей Team і User).
- Перетягувати картки може користувач із правом **edit на User**. Увага:
  стандартні ролі EspoCRM дозволяють для User лише рівні edit `own`/`no`,
  тому фактично чужі картки може рухати **лише адміністратор**; користувач
  із edit `own` може перемістити тільки самого себе. Для інших дошка
  доступна в режимі перегляду.
- Leader і Vice Leader — ексклюзивні: призначення нового автоматично переводить
  попереднього в Member (в межах однієї команди).
- Supervisor-и показуються приглушеною групою вгорі колонки — вони куратори,
  а не пряме керівництво.
- Мобільний екран: колонки складаються вертикально, заголовок згортає колонку;
  drag & drop на дотику — довге натискання (300 мс) на картці.

## API

| Endpoint | Метод | Опис |
|---|---|---|
| `TeamBoard/data` | GET | Дані дошки (команди, учасники, позиції) з урахуванням ACL |
| `TeamBoard/move` | POST | `{userId, teamId, fromTeamId?, position}` — перенесення/зміна позиції |

## Збірка з вихідників

Репозиторій — це проєкт на базі офіційного
[ext-template](https://github.com/espocrm/ext-template) EspoCRM. Щоб зібрати
інсталяційний `.zip` самостійно:

```bash
npm install
cp config-default.json config.json   # за потреби вкажіть креденшели БД dev-інстансу
node build --extension                # збере .zip у ./build
```

Готовий архів з'явиться в теці `build/`.

## Тести

### Живе тестування (2026-07-06, EspoCRM у Docker, v0.0.3)

Розширення встановлено в реальний EspoCRM і протестовано наживо. Результати:

- Встановлення/оновлення через Administration → Extensions — OK (після
  upgrade потрібен Rebuild, інакше маршрути повертають 404).
- `GET TeamBoard/data` — OK: команди, учасники, позиції, `canManage`.
- `POST TeamBoard/move`: зміна позиції в команді, перенесення між командами
  (з `fromTeamId`), Supervisor одночасно у двох командах — OK.
- Ексклюзивність Leader / Vice Leader: попередній автоматично стає Member — OK.
- Валідація: невідома позиція → 400, неіснуючий користувач → 404 — OK.
- ACL: без ролі → 403; роль зі scope Team Board (read-only) → 200,
  `canManage=false`, move → 403; edit `own` → move себе 200, чужого 403 — OK.
- UI: дошка рендериться, drag & drop у межах колонки та між колонками
  зберігає одразу з toast-підтвердженням; кнопка **Team Board** на сторінці
  Teams і пункт у Tab List працюють — OK.
- Логи EspoCRM: нових ERROR після тестів немає, конфліктів з ядром не виявлено.

Виправлено в процесі (v0.0.3): `Team::getName()` не існує в
`Espo\Entities\Team` — замінено на `$team->get('name')` (це спричиняло 500
на всіх запитах дошки у v0.0.1).

### Автоматичні тести (ext-template)

Інтеграційні тести написані, але потребують повного dev-інстансу. Запуск:

```bash
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

`acceptableVersions: ">=9.3.0"`

## Ліцензія

GNU General Public License v2 — див. [LICENSE](LICENSE).

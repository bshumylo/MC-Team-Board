import View from 'view';

/**
 * Team Board — kanban-style board of teams.
 *
 * Columns are teams; cards are team members grouped by their position
 * within the team (stored in the Team-User relationship). The position
 * list is dynamic — taken from the `positionList` field of each team
 * (with a fallback to the default list). Only the top position of the
 * hierarchy is visually highlighted; all other positions are shown on
 * the same level. Supervisors are shown as small avatars in the column
 * header corner (only when the team has the Supervisor position).
 *
 * Drag & drop between columns changes the team; drag & drop between
 * groups of one column changes the position; drag & drop within one
 * group changes the personal visual order (saved in Preferences);
 * drag & drop onto the empty area outside the columns removes the user
 * from the team (the user record itself is never deleted); holding
 * Ctrl/Alt while dropping adds the user to the target team without
 * removing from the source one. Columns themselves can be reordered by
 * dragging their headers; the order is saved per user (Preferences).
 * Per-column position visibility is configurable (gear menu, saved in
 * Preferences); the member counter counts only visible positions.
 */
class TeamBoardView extends View {

    template = 'team-board:team-board/board'

    /** Distance in pixels before a mouse drag starts. */
    DRAG_THRESHOLD = 6

    /** Long-press delay in ms before a touch drag starts. */
    TOUCH_DRAG_DELAY = 300

    /** The special out-of-hierarchy position shown in the header corner. */
    SUPERVISOR = 'Supervisor'

    events = {
        /** @this TeamBoardView */
        'click [data-action="setPosition"]': function (e) {
            const el = e.currentTarget;

            this.move(
                el.dataset.userId,
                el.dataset.teamId,
                el.dataset.teamId,
                el.dataset.position
            );
        },
        /** @this TeamBoardView */
        'click [data-action="moveToTeam"]': function (e) {
            const el = e.currentTarget;

            this.move(
                el.dataset.userId,
                el.dataset.toTeamId,
                el.dataset.fromTeamId,
                el.dataset.position
            );
        },
        /** @this TeamBoardView */
        'click [data-action="addToTeam"]': function (e) {
            const el = e.currentTarget;

            this.move(
                el.dataset.userId,
                el.dataset.toTeamId,
                null,
                el.dataset.position,
                {add: true}
            );
        },
        /** @this TeamBoardView */
        'click [data-action="addFreeUser"]': function (e) {
            const el = e.currentTarget;

            this.move(
                el.dataset.userId,
                el.dataset.teamId,
                null,
                el.dataset.position,
                {add: true}
            );
        },
        /** @this TeamBoardView */
        'click [data-action="removeFromTeam"]': function (e) {
            const el = e.currentTarget;

            this.removeMember(el.dataset.userId, el.dataset.teamId);
        },
        /** @this TeamBoardView */
        'click [data-action="togglePosition"]': function (e) {
            // Keep the dropdown open for multi-toggling.
            e.stopPropagation();

            const el = e.currentTarget;

            this.togglePositionVisibility(el.dataset.teamId, el.dataset.position, el);
        },
        /** @this TeamBoardView */
        'click [data-action="toggleColumn"]': function (e) {
            if (
                e.target.closest('.tb-card') ||
                e.target.closest('.dropdown-menu') ||
                e.target.closest('.btn-group') ||
                e.target.closest('a') ||
                e.target.closest('.tb-sups')
            ) {
                return;
            }

            e.currentTarget.closest('.tb-col').classList.toggle('tb-collapsed');
        },
    }

    setup() {
        this.boardData = {
            teams: [],
            positionList: [],
            canManage: false,
            totalUnique: 0,
            freeUsers: [],
        };

        this.wait(
            Espo.Ajax.getRequest('TeamBoard/data')
                .then(data => {
                    this.boardData = data;
                })
        );
    }

    data() {
        const canManage = this.boardData.canManage;

        const teams = this.applyOrder(this.boardData.teams).map(team => {
            const positionList = this.teamPositionList(team);
            const hidden = this.getHiddenPositions(team.id);

            const hasSupervisorPosition = positionList.includes(this.SUPERVISOR);

            const supervisors = !hasSupervisorPosition ? [] : team.members
                .filter(member =>
                    this.normalizePosition(member.position, positionList) === this.SUPERVISOR)
                .sort((a, b) => (a.name || '').localeCompare(b.name || ''))
                .map(member => ({
                    id: member.id,
                    teamId: team.id,
                    canDrag: canManage,
                    tooltip: this.translate(this.SUPERVISOR, 'positions', 'TeamBoard') +
                        ': ' + member.name,
                    avatarHtml: this.getHelper().getAvatarHtml(member.id, 'small', 20, 'tb-sup-img'),
                }));

            const bottomPosition = this.bottomPosition(positionList);

            const freeUsers = (this.boardData.freeUsers || []).map(user => ({
                id: user.id,
                name: user.name,
                teamId: team.id,
                position: bottomPosition,
            }));

            const settingsPositions = positionList.map(position => ({
                value: position,
                teamId: team.id,
                checked: !hidden.includes(position),
                label: this.positionLabelOf(position),
            }));

            return {
                id: team.id,
                name: team.name,
                count: this.visibleCount(team, positionList, hidden),
                supervisors: supervisors,
                hasSupervisors: supervisors.length > 0,
                hasSupervisorPosition: hasSupervisorPosition,
                supervisorsHidden: hidden.includes(this.SUPERVISOR),
                groups: this.composeGroups(team, positionList, hidden, canManage),
                settingsPositions: settingsPositions,
                freeUsers: freeUsers,
                hasFreeUsers: freeUsers.length > 0,
            };
        });

        return {
            title: this.translate('Team Board', 'labels', 'TeamBoard'),
            totalUnique: this.boardData.totalUnique || 0,
            uniqueLabel: this.translate('Unique members', 'labels', 'TeamBoard'),
            noTeams: teams.length === 0,
            noTeamsLabel: this.translate('No teams', 'labels', 'TeamBoard'),
            removeDropLabel: this.translate('Drop here to remove', 'labels', 'TeamBoard'),
            settingsLabel: this.translate('Visible positions', 'labels', 'TeamBoard'),
            addMemberLabel: this.translate('Add member', 'labels', 'TeamBoard'),
            noFreeUsersLabel: this.translate('No users without a team', 'labels', 'TeamBoard'),
            canManage: canManage,
            teams: teams,
        };
    }

    /**
     * Position list of a team; falls back to the default list.
     *
     * @private
     */
    teamPositionList(team) {
        const list = (team.positionList || []).filter(item => !!item);

        if (list.length) {
            return list;
        }

        return this.boardData.positionList || [];
    }

    /**
     * Hierarchy positions of a team — without the special Supervisor.
     *
     * @private
     */
    bodyPositions(positionList) {
        return positionList.filter(position => position !== this.SUPERVISOR);
    }

    /**
     * The top (highlighted, exclusive) position.
     *
     * @private
     */
    topPosition(positionList) {
        return this.bodyPositions(positionList)[0] || null;
    }

    /**
     * The bottom position — the default one for new members.
     *
     * @private
     */
    bottomPosition(positionList) {
        const body = this.bodyPositions(positionList);

        if (body.length) {
            return body[body.length - 1];
        }

        return positionList[positionList.length - 1] || 'Member';
    }

    /**
     * Any value not in the team position list (legacy/custom/null)
     * is treated as the bottom position.
     *
     * @private
     */
    normalizePosition(position, positionList) {
        if (position && positionList.includes(position)) {
            return position;
        }

        return this.bottomPosition(positionList);
    }

    /**
     * @private
     */
    positionLabelOf(position) {
        if (position === 'Member') {
            return this.translate('Members', 'labels', 'TeamBoard');
        }

        return this.translate(position, 'positions', 'TeamBoard');
    }

    /**
     * Number of team members on visible (not hidden) positions.
     *
     * @private
     */
    visibleCount(team, positionList, hidden) {
        return team.members
            .filter(member =>
                !hidden.includes(this.normalizePosition(member.position, positionList)))
            .length;
    }

    /**
     * Sorts teams by the order saved in user preferences.
     * Teams not present in the saved order go to the end
     * (in the server order, i.e. alphabetically).
     *
     * @private
     */
    applyOrder(teams) {
        const saved = this.getPreferences().get('teamBoardOrder');

        if (!Array.isArray(saved) || saved.length === 0) {
            return teams;
        }

        const index = id => {
            const i = saved.indexOf(id);

            return i === -1 ? saved.length : i;
        };

        return [...teams].sort((a, b) => index(a.id) - index(b.id));
    }

    /**
     * @private
     */
    getMemberOrderMap() {
        return this.getPreferences().get('teamBoardMemberOrder') || {};
    }

    /**
     * Sorts members of one group by the personal order saved in
     * preferences; the rest — by name.
     *
     * @private
     */
    sortMembers(members, teamId, position) {
        const saved = (this.getMemberOrderMap()[teamId] || {})[position] || [];

        const index = id => {
            const i = saved.indexOf(id);

            return i === -1 ? Number.MAX_SAFE_INTEGER : i;
        };

        return [...members].sort((a, b) =>
            (index(a.id) - index(b.id)) ||
            (a.name || '').localeCompare(b.name || ''));
    }

    /**
     * @private
     */
    saveMemberOrder(teamId, position, userIds) {
        const map = {...this.getMemberOrderMap()};

        map[teamId] = {...(map[teamId] || {})};
        map[teamId][position] = userIds;

        this.getPreferences().save({teamBoardMemberOrder: map}, {patch: true});
    }

    /**
     * @private
     */
    getHiddenPositions(teamId) {
        const map = this.getPreferences().get('teamBoardHiddenPositions') || {};

        return map[teamId] || [];
    }

    /**
     * Toggles visibility of a position in a column. Updates the DOM
     * in place (no re-render) so the settings dropdown stays open.
     *
     * @private
     */
    togglePositionVisibility(teamId, position, itemElement) {
        const map = {...(this.getPreferences().get('teamBoardHiddenPositions') || {})};

        const hidden = [...(map[teamId] || [])];
        const i = hidden.indexOf(position);

        if (i === -1) {
            hidden.push(position);
        }
        else {
            hidden.splice(i, 1);
        }

        map[teamId] = hidden;

        this.getPreferences().save({teamBoardHiddenPositions: map}, {patch: true});

        const check = itemElement.querySelector('.tb-check');

        if (check) {
            check.classList.toggle('fa-check-square', i !== -1);
            check.classList.toggle('fa-square', i === -1);
        }

        const col = this.element
            .querySelector(`.tb-col[data-team-id="${teamId}"]`);

        if (!col) {
            return;
        }

        const target = position === this.SUPERVISOR ?
            col.querySelector('.tb-sups') :
            col.querySelector(`.tb-group[data-position="${CSS.escape(position)}"]`);

        if (target) {
            target.classList.toggle('tb-hidden', i === -1);
        }

        const team = this.boardData.teams.find(item => item.id === teamId);

        if (team) {
            const countEl = col.querySelector('.tb-count');

            if (countEl) {
                countEl.textContent = this.visibleCount(
                    team, this.teamPositionList(team), hidden);
            }
        }
    }

    /**
     * Groups of one column — all hierarchy positions of the team.
     * Hidden ones are rendered with a hiding class, so visibility can
     * be toggled without a re-render.
     *
     * @private
     */
    composeGroups(team, positionList, hidden, canManage) {
        const top = this.topPosition(positionList);

        return this.bodyPositions(positionList).map(position => {
            const members = this.sortMembers(
                team.members.filter(member =>
                    this.normalizePosition(member.position, positionList) === position),
                team.id,
                position
            ).map(member => this.composeMember(member, team, positionList, canManage));

            return {
                position: position,
                teamId: team.id,
                isTop: position === top,
                isHidden: hidden.includes(position),
                label: this.positionLabelOf(position),
                members: members,
                isEmpty: members.length === 0,
                dropHereLabel: this.translate('Drop here', 'labels', 'TeamBoard'),
            };
        });
    }

    /**
     * @private
     */
    composeMember(member, team, positionList, canManage) {
        const position = this.normalizePosition(member.position, positionList);

        const positionLabel = member.position && member.position !== position ?
            member.position :
            this.translate(position, 'positions', 'TeamBoard');

        const menuPositions = positionList
            .filter(item => item !== position)
            .map(item => ({
                value: item,
                userId: member.id,
                teamId: team.id,
                label: this.translate(item, 'positions', 'TeamBoard'),
            }));

        const memberTeamIds = this.boardData.teams
            .filter(item => item.members.some(m => m.id === member.id))
            .map(item => item.id);

        const menuTeams = this.boardData.teams
            .filter(item => item.id !== team.id)
            .map(item => ({
                toTeamId: item.id,
                fromTeamId: team.id,
                userId: member.id,
                position: this.bottomPosition(this.teamPositionList(item)),
                label: item.name,
            }));

        const menuAddTeams = this.boardData.teams
            .filter(item => !memberTeamIds.includes(item.id))
            .map(item => ({
                toTeamId: item.id,
                userId: member.id,
                position: this.bottomPosition(this.teamPositionList(item)),
                label: item.name,
            }));

        return {
            id: member.id,
            teamId: team.id,
            position: position,
            name: member.name,
            positionLabel: positionLabel,
            avatarHtml: this.getHelper().getAvatarHtml(member.id, 'small', 32, 'tb-avatar-img'),
            isTop: position === this.topPosition(positionList),
            canManage: canManage,
            menuPositions: menuPositions,
            menuTeams: menuTeams,
            hasMenuTeams: menuTeams.length > 0,
            menuAddTeams: menuAddTeams,
            hasMenuAddTeams: menuAddTeams.length > 0,
            removeLabel: this.translate('Remove from team', 'labels', 'TeamBoard'),
        };
    }

    afterRender() {
        this.resetDragState();
        this.initColumnReorder();
        this.initFloatingScrollbar();
        this.initMenuPositionFix();

        if (!this.boardData.canManage) {
            return;
        }

        this.initDragAndDrop();
        this.initTouchDragAndDrop();
    }

    onRemove() {
        this.destroyFloatingScrollbar();
    }

    /**
     * A fixed horizontal scrollbar at the bottom of the window,
     * synchronized with the board container — so the board can be
     * scrolled without scrolling the page down first.
     *
     * @private
     */
    initFloatingScrollbar() {
        this.destroyFloatingScrollbar();

        const container = this.element.querySelector('.team-board');

        if (!container) {
            return;
        }

        const el = document.createElement('div');

        el.className = 'tb-hscroll';

        const spacer = document.createElement('div');

        el.appendChild(spacer);
        document.body.appendChild(el);

        const update = () => {
            const rect = container.getBoundingClientRect();

            const isColumn =
                getComputedStyle(container).flexDirection === 'column';

            const needed = !isColumn &&
                container.scrollWidth > container.clientWidth + 2;

            el.style.display = needed ? 'block' : 'none';

            if (!needed) {
                return;
            }

            el.style.left = `${rect.left}px`;
            el.style.width = `${container.clientWidth}px`;

            spacer.style.width = `${container.scrollWidth}px`;

            if (el.scrollLeft !== container.scrollLeft) {
                el.scrollLeft = container.scrollLeft;
            }
        };

        el.addEventListener('scroll', () => {
            if (container.scrollLeft !== el.scrollLeft) {
                container.scrollLeft = el.scrollLeft;
            }
        });

        container.addEventListener('scroll', () => {
            if (el.scrollLeft !== container.scrollLeft) {
                el.scrollLeft = container.scrollLeft;
            }
        });

        const onResize = () => update();

        window.addEventListener('resize', onResize);

        this._hscroll = {el: el, onResize: onResize};

        update();
    }

    /**
     * @private
     */
    destroyFloatingScrollbar() {
        if (!this._hscroll) {
            return;
        }

        window.removeEventListener('resize', this._hscroll.onResize);
        this._hscroll.el.remove();

        this._hscroll = null;
    }

    /**
     * The board container scrolls horizontally, so absolutely positioned
     * dropdown menus get clipped by it. Re-position an opened card or
     * column menu as fixed, relative to the viewport (flipping up when
     * there is not enough space below).
     *
     * @private
     */
    initMenuPositionFix() {
        const container = this.element.querySelector('.team-board');

        if (!container) {
            return;
        }

        container.addEventListener('click', e => {
            const toggle = e.target.closest(
                '.tb-menu .dropdown-toggle, .tb-col-menu .dropdown-toggle');

            if (!toggle) {
                return;
            }

            setTimeout(() => {
                const group = toggle.closest('.btn-group');
                const menu = group ? group.querySelector('.dropdown-menu') : null;

                if (!group || !menu || !group.classList.contains('open')) {
                    return;
                }

                const rect = toggle.getBoundingClientRect();

                menu.style.position = 'fixed';
                menu.style.left = 'auto';
                menu.style.right =
                    `${document.documentElement.clientWidth - rect.right}px`;
                menu.style.top = `${rect.bottom + 2}px`;

                const menuHeight = menu.offsetHeight;

                if (rect.bottom + 2 + menuHeight > window.innerHeight) {
                    menu.style.top = `${Math.max(8, rect.top - menuHeight - 2)}px`;
                }
            }, 0);
        });
    }

    /**
     * Desktop drag & drop of member cards (native HTML5).
     *
     * @private
     */
    initDragAndDrop() {
        const root = this.element;
        const container = root.querySelector('.team-board');

        if (!container) {
            return;
        }

        container.addEventListener('dragstart', e => {
            const card = e.target.closest('.tb-card, .tb-sup[draggable="true"]');

            if (!card) {
                return;
            }

            this._drag = {
                userId: card.dataset.userId,
                fromTeamId: card.dataset.teamId,
                position: card.dataset.position,
            };

            e.dataTransfer.setData('text/plain', card.dataset.userId);
            e.dataTransfer.effectAllowed = 'copyMove';

            const drag = this._drag;

            // Guarded: a fast or immediately-cancelled drag can finish
            // before this timeout runs; adding the classes then would
            // leave the board in a stuck visual state.
            setTimeout(() => {
                if (this._drag !== drag) {
                    return;
                }

                card.classList.add('tb-dragging');
                root.classList.add('tb-drag-active');
            }, 0);
        });

        container.addEventListener('dragend', () => {
            this.resetDragState();
        });

        if (this._rootDndBound) {
            return;
        }

        this._rootDndBound = true;

        root.addEventListener('dragover', e => {
            if (!this._drag) {
                return;
            }

            e.preventDefault();

            // Reordering within the same group.
            const overCard = this.reorderTargetFromElement(e.target);

            if (overCard) {
                e.dataTransfer.dropEffect = 'move';

                const rect = overCard.getBoundingClientRect();
                const before = e.clientY < rect.top + rect.height / 2;

                this.clearDropHighlight();
                this.clearCardIndicator();

                overCard.classList.add(
                    before ? 'tb-card-insert-before' : 'tb-card-insert-after');

                this._cardInsert = {card: overCard, before: before};

                return;
            }

            this.clearCardIndicator();
            this._cardInsert = null;

            const target = this.dropTargetFromElement(e.target);

            if (target) {
                e.dataTransfer.dropEffect = (e.ctrlKey || e.altKey || e.metaKey) ? 'copy' : 'move';

                this.highlightElement(target);

                return;
            }

            // Empty area outside the columns — remove intent.
            e.dataTransfer.dropEffect = 'move';

            this.highlightRemoveZone();
        });

        root.addEventListener('drop', e => {
            const drag = this._drag;
            const cardInsert = this._cardInsert;

            this.resetDragState();

            if (!drag) {
                return;
            }

            e.preventDefault();

            if (cardInsert) {
                this.reorderCard(drag, cardInsert);

                return;
            }

            const target = this.dropTargetFromElement(e.target);

            if (target) {
                this.dropTo(drag, target, {
                    add: e.ctrlKey || e.altKey || e.metaKey,
                });

                return;
            }

            this.removeMember(drag.userId, drag.fromTeamId);
        });
    }

    /**
     * A card of the same team & position group (other than the dragged
     * one) — a target for personal reordering.
     *
     * @private
     */
    reorderTargetFromElement(element) {
        if (!this._drag || !element || !(element instanceof Element)) {
            return null;
        }

        const card = element.closest('.tb-card');

        if (
            !card ||
            card.dataset.userId === this._drag.userId ||
            card.dataset.teamId !== this._drag.fromTeamId ||
            card.dataset.position !== this._drag.position
        ) {
            return null;
        }

        return card;
    }

    /**
     * Moves the dragged card before/after the target card in the DOM
     * and saves the personal order in preferences.
     *
     * @private
     */
    reorderCard(drag, insert) {
        const group = insert.card.closest('.tb-group');

        if (!group) {
            return;
        }

        const dragged = group.querySelector(
            `.tb-card[data-user-id="${CSS.escape(drag.userId)}"]`);

        if (!dragged) {
            return;
        }

        group.insertBefore(
            dragged,
            insert.before ? insert.card : insert.card.nextSibling
        );

        const userIds = [...group.querySelectorAll('.tb-card')]
            .map(card => card.dataset.userId);

        this.saveMemberOrder(drag.fromTeamId, drag.position, userIds);
    }

    /**
     * @private
     */
    clearCardIndicator() {
        this.element
            .querySelectorAll('.tb-card-insert-before, .tb-card-insert-after')
            .forEach(el => el.classList.remove(
                'tb-card-insert-before', 'tb-card-insert-after'));
    }

    /**
     * A drop target is a position group, the supervisors corner
     * or the explicit remove zone.
     *
     * @private
     */
    dropTargetFromElement(element) {
        if (!element || !(element instanceof Element)) {
            return null;
        }

        return element.closest('.tb-group, .tb-sups, .tb-remove-zone');
    }

    /**
     * Touch drag & drop — long-press to start dragging,
     * so regular touch scrolling still works.
     *
     * @private
     */
    initTouchDragAndDrop() {
        const root = this.element;
        const container = root.querySelector('.team-board');

        if (!container) {
            return;
        }

        let touchDrag = null;
        let timer = null;
        let startX = 0;
        let startY = 0;

        const cleanup = () => {
            clearTimeout(timer);
            timer = null;

            if (touchDrag) {
                touchDrag.ghost.remove();
                touchDrag.card.classList.remove('tb-dragging');
                touchDrag = null;
            }

            root.classList.remove('tb-drag-active');
            this.clearDropHighlight();
        };

        container.addEventListener('touchstart', e => {
            const card = e.target.closest('.tb-card, .tb-sup[draggable="true"]');

            if (!card || e.touches.length !== 1) {
                return;
            }

            const touch = e.touches[0];

            startX = touch.clientX;
            startY = touch.clientY;

            timer = setTimeout(() => {
                timer = null;

                const ghost = this.createGhost(card, touch.clientX, touch.clientY);

                touchDrag = {
                    card: card,
                    ghost: ghost,
                    userId: card.dataset.userId,
                    fromTeamId: card.dataset.teamId,
                    position: card.dataset.position,
                };

                card.classList.add('tb-dragging');
                root.classList.add('tb-drag-active');
            }, this.TOUCH_DRAG_DELAY);
        }, {passive: true});

        container.addEventListener('touchmove', e => {
            const touch = e.touches[0];

            if (!touchDrag) {
                if (
                    timer &&
                    (
                        Math.abs(touch.clientX - startX) > this.DRAG_THRESHOLD ||
                        Math.abs(touch.clientY - startY) > this.DRAG_THRESHOLD
                    )
                ) {
                    // The user is scrolling, not long-pressing.
                    clearTimeout(timer);
                    timer = null;
                }

                return;
            }

            e.preventDefault();

            touchDrag.ghost.style.left = `${touch.clientX + 8}px`;
            touchDrag.ghost.style.top = `${touch.clientY + 8}px`;

            const target = this.targetFromPoint(touch.clientX, touch.clientY);

            this.clearDropHighlight();

            if (target) {
                this.highlightElement(target);
            }
        }, {passive: false});

        const end = e => {
            clearTimeout(timer);
            timer = null;

            if (!touchDrag) {
                return;
            }

            const touch = e.changedTouches[0];
            const target = this.targetFromPoint(touch.clientX, touch.clientY);
            const drag = touchDrag;

            cleanup();

            if (target) {
                this.dropTo(drag, target, {add: false});
            }
        };

        container.addEventListener('touchend', end);
        container.addEventListener('touchcancel', () => cleanup());
    }

    /**
     * Column reordering by dragging the column header.
     * The order is saved in user preferences.
     *
     * @private
     */
    initColumnReorder() {
        const container = this.element.querySelector('.team-board');

        if (!container) {
            return;
        }

        container.addEventListener('dragstart', e => {
            if (e.target.closest('.tb-card') || e.target.closest('.tb-sup')) {
                return;
            }

            const head = e.target.closest('.tb-col-head');

            if (!head) {
                return;
            }

            const col = head.closest('.tb-col');

            this._colDrag = col;
            this._colInsert = null;

            e.dataTransfer.setData('text/plain', col.dataset.teamId);
            e.dataTransfer.effectAllowed = 'move';

            // Use the whole column as the drag image so its full content
            // is shown while dragging (like dashboard dashlets), instead
            // of just the grabbed header.
            if (e.dataTransfer.setDragImage) {
                const rect = col.getBoundingClientRect();

                e.dataTransfer.setDragImage(
                    col,
                    e.clientX - rect.left,
                    e.clientY - rect.top
                );
            }

            setTimeout(() => {
                if (this._colDrag !== col) {
                    return;
                }

                col.classList.add('tb-col-dragging');
            }, 0);
        });

        container.addEventListener('dragover', e => {
            const dragged = this._colDrag;

            if (!dragged) {
                return;
            }

            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            const over = e.target.closest('.tb-col');

            if (!over || over === dragged) {
                return;
            }

            const rect = over.getBoundingClientRect();

            const horizontal =
                getComputedStyle(container).flexDirection !== 'column';

            const before = horizontal ?
                e.clientX < rect.left + rect.width / 2 :
                e.clientY < rect.top + rect.height / 2;

            // The dragged column must not be moved in the DOM while the
            // drag is in progress — the browser would abort the drag
            // without a dragend. Only an insertion indicator is shown;
            // the actual move happens on drop/dragend.
            this.clearColumnIndicator();

            over.classList.add(
                before ? 'tb-col-insert-before' : 'tb-col-insert-after');

            this._colInsert = {over: over, before: before};
        });

        const finish = () => {
            const dragged = this._colDrag;
            const insert = this._colInsert;

            this._colDrag = null;
            this._colInsert = null;

            this.clearColumnIndicator();

            if (!dragged) {
                return;
            }

            dragged.classList.remove('tb-col-dragging');

            if (insert && insert.over.parentElement === container) {
                container.insertBefore(
                    dragged,
                    insert.before ? insert.over : insert.over.nextSibling
                );

                this.saveColumnOrder();
            }
        };

        container.addEventListener('drop', e => {
            if (this._colDrag) {
                e.preventDefault();
            }

            finish();
        });

        container.addEventListener('dragend', () => finish());
    }

    /**
     * @private
     */
    clearColumnIndicator() {
        this.element.querySelectorAll('.tb-col-insert-before, .tb-col-insert-after')
            .forEach(el => el.classList.remove(
                'tb-col-insert-before', 'tb-col-insert-after'));
    }

    /**
     * Clears all drag-related state and classes. Safe to call at any
     * time; used as a failsafe because the browser does not deliver
     * `dragend` when the drag source has been re-rendered (detached)
     * in the meantime.
     *
     * @private
     */
    resetDragState() {
        this._drag = null;
        this._colDrag = null;
        this._colInsert = null;
        this._cardInsert = null;

        if (!this.element) {
            return;
        }

        this.element.classList.remove('tb-drag-active');

        this.element
            .querySelectorAll(
                '.tb-dragging, .tb-col-dragging, .tb-over, ' +
                '.tb-col-insert-before, .tb-col-insert-after, ' +
                '.tb-card-insert-before, .tb-card-insert-after'
            )
            .forEach(el => el.classList.remove(
                'tb-dragging', 'tb-col-dragging', 'tb-over',
                'tb-col-insert-before', 'tb-col-insert-after',
                'tb-card-insert-before', 'tb-card-insert-after'
            ));
    }

    /**
     * @private
     */
    saveColumnOrder() {
        const container = this.element.querySelector('.team-board');

        if (!container) {
            return;
        }

        const order = [...container.querySelectorAll('.tb-col')]
            .map(col => col.dataset.teamId)
            .filter(id => !!id);

        this.getPreferences().save({teamBoardOrder: order}, {patch: true});
    }

    /**
     * @private
     */
    createGhost(card, x, y) {
        const ghost = card.cloneNode(true);

        ghost.classList.add('tb-ghost');
        ghost.style.width = `${card.offsetWidth}px`;
        ghost.style.left = `${x + 8}px`;
        ghost.style.top = `${y + 8}px`;

        document.body.appendChild(ghost);

        return ghost;
    }

    /**
     * @private
     */
    targetFromPoint(x, y) {
        const element = document.elementFromPoint(x, y);

        return this.dropTargetFromElement(element);
    }

    /**
     * @private
     */
    highlightElement(element) {
        if (!element.classList.contains('tb-over')) {
            this.clearDropHighlight();

            element.classList.add('tb-over');
        }
    }

    /**
     * @private
     */
    highlightRemoveZone() {
        const zone = this.element.querySelector('.tb-remove-zone');

        if (zone) {
            this.highlightElement(zone);
        }
    }

    /**
     * @private
     */
    clearDropHighlight() {
        this.element.querySelectorAll('.tb-over')
            .forEach(element => element.classList.remove('tb-over'));
    }

    /**
     * @private
     */
    dropTo(drag, target, options) {
        if (target.classList.contains('tb-remove-zone')) {
            this.removeMember(drag.userId, drag.fromTeamId);

            return;
        }

        const toTeamId = target.dataset.teamId;
        const position = target.dataset.position;

        if (!toTeamId || !position) {
            return;
        }

        if (drag.fromTeamId === toTeamId && drag.position === position) {
            return;
        }

        const add = !!(options && options.add) && drag.fromTeamId !== toTeamId;

        this.move(
            drag.userId,
            toTeamId,
            add ? null : drag.fromTeamId,
            position,
            {add: add}
        );
    }

    /**
     * Saves immediately, then shows a success toast or an error.
     *
     * @private
     */
    move(userId, teamId, fromTeamId, position, options) {
        Espo.Ui.notifyWait();

        Espo.Ajax
            .postRequest('TeamBoard/move', {
                userId: userId,
                teamId: teamId,
                fromTeamId: fromTeamId || null,
                position: position,
            })
            .then(data => {
                this.boardData = data;

                this.resetDragState();

                const team = data.teams.find(item => item.id === teamId);
                const member = (team ? team.members : [])
                    .find(item => item.id === userId);

                const messageKey = options && options.add ? 'added' : 'moved';

                const positionList = team ?
                    this.teamPositionList(team) :
                    (this.boardData.positionList || []);

                const message = this.translate(messageKey, 'messages', 'TeamBoard')
                    .replace('{name}', member ? member.name : '')
                    .replace('{team}', team ? team.name : '')
                    .replace('{position}', this.translate(
                        this.normalizePosition(position, positionList),
                        'positions', 'TeamBoard'));

                return this.reRender()
                    .then(() => Espo.Ui.success(message));
            })
            .catch(xhr => {
                if (xhr) {
                    xhr.errorIsHandled = true;
                }

                Espo.Ui.error(
                    this.translate('moveError', 'messages', 'TeamBoard'));

                this.resetDragState();
                this.reRender();
            });
    }

    /**
     * Removes a user from a team (drag-out or menu action).
     * Only the team membership is removed — the user record
     * itself is never deleted.
     *
     * @private
     */
    removeMember(userId, teamId) {
        if (!userId || !teamId) {
            return;
        }

        const team = this.boardData.teams.find(item => item.id === teamId);
        const member = (team ? team.members : []).find(item => item.id === userId);

        Espo.Ui.notifyWait();

        Espo.Ajax
            .postRequest('TeamBoard/removeMember', {
                userId: userId,
                teamId: teamId,
            })
            .then(data => {
                this.boardData = data;

                this.resetDragState();

                const message = this.translate('removed', 'messages', 'TeamBoard')
                    .replace('{name}', member ? member.name : '')
                    .replace('{team}', team ? t
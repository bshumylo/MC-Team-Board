import View from 'view';

/**
 * Team Board — kanban-style board of teams.
 *
 * Columns are teams; cards are team members grouped by their position
 * within the team (stored in the Team-User relationship): Supervisor
 * (muted, advisory), Leader, Vice Leader, Members.
 *
 * Drag & drop between columns changes the team; drag & drop between
 * groups of one column changes the position. Saved immediately.
 */
class TeamBoardView extends View {

    template = 'team-board:team-board/board'

    /** Distance in pixels before a mouse drag starts. */
    DRAG_THRESHOLD = 6

    /** Long-press delay in ms before a touch drag starts. */
    TOUCH_DRAG_DELAY = 300

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
                'Member'
            );
        },
        /** @this TeamBoardView */
        'click [data-action="toggleColumn"]': function (e) {
            if (e.target.closest('.tb-card') || e.target.closest('.dropdown-menu')) {
                return;
            }

            e.currentTarget.closest('.tb-col').classList.toggle('tb-collapsed');
        },
    }

    setup() {
        this.boardData = {teams: [], positionList: [], canManage: false};

        this.wait(
            Espo.Ajax.getRequest('TeamBoard/data')
                .then(data => {
                    this.boardData = data;
                })
        );
    }

    data() {
        const canManage = this.boardData.canManage;

        const teams = this.boardData.teams.map(team => {
            return {
                id: team.id,
                name: team.name,
                count: team.members.length,
                groups: this.composeGroups(team, canManage),
            };
        });

        return {
            title: this.translate('Team Board', 'labels', 'TeamBoard'),
            noTeams: teams.length === 0,
            noTeamsLabel: this.translate('No teams', 'labels', 'TeamBoard'),
            canManage: canManage,
            teams: teams,
        };
    }

    /**
     * @private
     */
    composeGroups(team, canManage) {
        const defs = [
            {position: 'Supervisor', labelKey: 'Supervisors', cls: 'tb-group-supervisor'},
            {position: 'Leader', labelKey: 'Leader', cls: 'tb-group-leader'},
            {position: 'Vice Leader', labelKey: 'Vice Leader', cls: 'tb-group-vice'},
            {position: 'Member', labelKey: 'Members', cls: 'tb-group-member'},
        ];

        return defs.map(def => {
            const members = team.members
                .filter(member => this.normalizePosition(member.position) === def.position)
                .sort((a, b) => (a.name || '').localeCompare(b.name || ''))
                .map(member => this.composeMember(member, team, canManage));

            const label = def.position === 'Supervisor' || def.position === 'Member' ?
                this.translate(def.labelKey, 'labels', 'TeamBoard') :
                this.translate(def.position, 'positions', 'TeamBoard');

            return {
                position: def.position,
                teamId: team.id,
                cls: def.cls,
                label: label,
                members: members,
                isEmpty: members.length === 0,
                dropHereLabel: this.translate('Drop here', 'labels', 'TeamBoard'),
            };
        });
    }

    /**
     * @private
     */
    composeMember(member, team, canManage) {
        const position = this.normalizePosition(member.position);

        const positionLabel = member.position && position === 'Member' && member.position !== 'Member' ?
            member.position :
            this.translate(position, 'positions', 'TeamBoard');

        const menuPositions = this.boardData.positionList
            .filter(item => item !== position)
            .map(item => ({
                value: item,
                userId: member.id,
                teamId: team.id,
                label: this.translate(item, 'positions', 'TeamBoard'),
            }));

        const menuTeams = this.boardData.teams
            .filter(item => item.id !== team.id)
            .map(item => ({
                toTeamId: item.id,
                fromTeamId: team.id,
                userId: member.id,
                label: item.name,
            }));

        return {
            id: member.id,
            teamId: team.id,
            position: position,
            name: member.name,
            positionLabel: positionLabel,
            avatarHtml: this.getHelper().getAvatarHtml(member.id, 'small', 32, 'tb-avatar-img'),
            isLeader: position === 'Leader',
            isSupervisor: position === 'Supervisor',
            canManage: canManage,
            menuPositions: menuPositions,
            menuTeams: menuTeams,
            hasMenuTeams: menuTeams.length > 0,
        };
    }

    /**
     * Any value not in the fixed position list (legacy/custom/null)
     * is treated as Member.
     *
     * @private
     */
    normalizePosition(position) {
        if (position && ['Supervisor', 'Leader', 'Vice Leader'].includes(position)) {
            return position;
        }

        return 'Member';
    }

    afterRender() {
        if (!this.boardData.canManage) {
            return;
        }

        this.initDragAndDrop();
        this.initTouchDragAndDrop();
    }

    /**
     * Desktop drag & drop (native HTML5).
     *
     * @private
     */
    initDragAndDrop() {
        const container = this.element.querySelector('.team-board');

        if (!container) {
            return;
        }

        container.addEventListener('dragstart', e => {
            const card = e.target.closest('.tb-card');

            if (!card) {
                return;
            }

            this._drag = {
                userId: card.dataset.userId,
                fromTeamId: card.dataset.teamId,
                position: card.dataset.position,
            };

            e.dataTransfer.setData('text/plain', card.dataset.userId);
            e.dataTransfer.effectAllowed = 'move';

            setTimeout(() => card.classList.add('tb-dragging'), 0);
        });

        container.addEventListener('dragend', e => {
            const card = e.target.closest('.tb-card');

            if (card) {
                card.classList.remove('tb-dragging');
            }

            this._drag = null;
            this.clearDropHighlight();
        });

        container.addEventListener('dragover', e => {
            if (!this._drag) {
                return;
            }

            const group = e.target.closest('.tb-group');

            if (!group) {
                return;
            }

            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            this.highlightGroup(group);
        });

        container.addEventListener('drop', e => {
            const group = e.target.closest('.tb-group');
            const drag = this._drag;

            this._drag = null;
            this.clearDropHighlight();

            if (!group || !drag) {
                return;
            }

            e.preventDefault();

            this.dropTo(drag, group);
        });
    }

    /**
     * Touch drag & drop — long-press to start dragging,
     * so regular touch scrolling still works.
     *
     * @private
     */
    initTouchDragAndDrop() {
        const container = this.element.querySelector('.team-board');

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

            this.clearDropHighlight();
        };

        container.addEventListener('touchstart', e => {
            const card = e.target.closest('.tb-card');

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

            const group = this.groupFromPoint(touch.clientX, touch.clientY);

            this.clearDropHighlight();

            if (group) {
                this.highlightGroup(group);
            }
        }, {passive: false});

        const end = e => {
            clearTimeout(timer);
            timer = null;

            if (!touchDrag) {
                return;
            }

            const touch = e.changedTouches[0];
            const group = this.groupFromPoint(touch.clientX, touch.clientY);
            const drag = touchDrag;

            cleanup();

            if (group) {
                this.dropTo(drag, group);
            }
        };

        container.addEventListener('touchend', end);
        container.addEventListener('touchcancel', () => cleanup());
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
    groupFromPoint(x, y) {
        const element = document.elementFromPoint(x, y);

        return element ? element.closest('.tb-group') : null;
    }

    /**
     * @private
     */
    highlightGroup(group) {
        if (!group.classList.contains('tb-over')) {
            this.clearDropHighlight();

            group.classList.add('tb-over');
        }
    }

    /**
     * @private
     */
    clearDropHighlight() {
        this.element.querySelectorAll('.tb-group.tb-over')
            .forEach(element => element.classList.remove('tb-over'));
    }

    /**
     * @private
     */
    dropTo(drag, group) {
        const toTeamId = group.dataset.teamId;
        const position = group.dataset.position;

        if (drag.fromTeamId === toTeamId && drag.position === position) {
            return;
        }

        this.move(drag.userId, toTeamId, drag.fromTeamId, position);
    }

    /**
     * Saves immediately, then shows a success toast or an error.
     *
     * @private
     */
    move(userId, teamId, fromTeamId, position) {
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

                const team = data.teams.find(item => item.id === teamId);
                const member = (team ? team.members : [])
                    .find(item => item.id === userId);

                const message = this.translate('moved', 'messages', 'TeamBoard')
                    .replace('{name}', member ? member.name : '')
                    .replace('{team}', team ? team.name : '')
                    .replace('{position}', this.translate(
                        this.normalizePosition(position), 'positions', 'TeamBoard'));

                return this.reRender()
                    .then(() => Espo.Ui.success(message));
            })
            .catch(xhr => {
                if (xhr) {
                    xhr.errorIsHandled = true;
                }

                Espo.Ui.error(
                    this.translate('moveError', 'messages', 'TeamBoard'));

                this.reRender();
            });
    }
}

export default TeamBoardView;

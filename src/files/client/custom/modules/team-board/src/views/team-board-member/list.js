import ListView from 'views/list';

/**
 * P13 «Manage users»: the native EspoCRM list of board people, opened from the
 * board menu. A row opens the same board person card as the board; «+ Create
 * Board User» opens the existing board-only create dialog. Records change only
 * through the checked board actions, never through the generic Record API.
 */
class TeamBoardMemberListView extends ListView {

    createButton = false

    setup() {
        super.setup();

        if (this.canManageBoard()) {
            this.addMenuItem('buttons', {
                name: 'createBoardUser',
                text: this.translate('Create Board User', 'labels', 'TeamBoardMember'),
                iconHtml: '<span class="fas fa-plus fa-sm"></span>',
                style: 'default',
                onClick: () => this.openPerson(null),
            }, true);
        }
    }

    getHeader() {
        const back = document.createElement('a');
        back.href = '#TeamBoard';
        back.textContent = '← ' + this.translate('Board', 'labels', 'TeamBoardMember');

        const title = document.createElement('span');
        title.textContent = this.translate('Manage users', 'labels', 'TeamBoard');
        title.dataset.action = 'fullRefresh';
        title.style.cursor = 'pointer';

        return this.buildHeaderHtml([back, title]);
    }

    updatePageTitle() {
        this.setPageTitle(this.translate('Manage users', 'labels', 'TeamBoard'));
    }

    /**
     * Today in the CRM system time zone (config timeZone), the same "today"
     * the server uses for system tasks and rules. The time zone of the
     * current user is deliberately not used.
     *
     * @private
     * @return {string}
     */
    systemToday() {
        const config = this.getConfig && this.getConfig();
        const zone = config && config.get && config.get('timeZone');

        if (zone) {
            try {
                const parts = new Intl.DateTimeFormat('en-US', {
                    timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit',
                }).formatToParts(new Date());
                const pick = type => parts.find(part => part.type === type).value;

                return `${pick('year')}-${pick('month')}-${pick('day')}`;
            } catch (e) {
                // Unknown zone name: fall through to the framework value.
            }
        }

        return this.getDateTime().getToday();
    }

    /** The same rule as the canManage flag of the board timeline. */
    canManageBoard() {
        return this.getUser().isAdmin() ||
            (this.getAcl().check('TeamBoard') && this.getAcl().check('TeamBoardAssignment', 'edit'));
    }

    /**
     * @param {?string} id A board person id, or null for a new board-only person.
     */
    async openPerson(id) {
        const today = this.systemToday();
        const model = id ? this.collection.get(id) : null;
        let data = {canManage: this.canManageBoard(), teams: [], assignments: [], date: today};

        if (id) {
            Espo.Ui.notifyWait();

            try {
                data = await Espo.Ajax.getRequest('TeamBoard/timeline', {
                    date: today,
                    from: this.shiftMonths(today, -6),
                    to: this.shiftMonths(today, 6),
                });
            } catch (e) {
                Espo.Ui.notify(false);

                return;
            }

            Espo.Ui.notify(false);
        }

        const member = id ?
            (this.findBoardMember(data, id) || this.memberFromModel(model, id)) :
            {isEspoUser: false};
        const archived = !!(model && model.get('isArchived'));

        const view = await this.createView('person', 'team-board:views/team-board/modals/person', {
            member: member,
            canManage: !!data.canManage && !archived,
            assignments: data.assignments || [],
            teams: data.teams || [],
            viewDate: data.date || today,
        });

        let changed = false;

        this.listenTo(view, 'done', () => changed = true);
        this.listenToOnce(view, 'close', () => {
            if (changed) this.collection.fetch();
        });

        await view.render();
    }

    /** @private */
    findBoardMember(data, id) {
        for (const team of data.teams || []) {
            const member = (team.members || []).find(item => item.boardMemberId === id);

            if (member) return Object.assign({}, member);
        }

        const reserved = (data.reserve || []).find(item => item.boardMemberId === id);

        return reserved ? Object.assign({}, reserved) : null;
    }

    /** @private */
    memberFromModel(model, id) {
        const userId = model ? model.get('userId') || null : null;
        const firstName = model ? model.get('personFirstName') || '' : '';
        const lastName = model ? model.get('personLastName') || '' : '';

        return {
            id: userId || id,
            boardMemberId: id,
            userId: userId,
            isEspoUser: !!userId,
            name: (userId ? '' : (model && model.get('name'))) ||
                [firstName, lastName].filter(Boolean).join(' '),
            firstName: firstName,
            lastName: lastName,
            boardNote: model ? model.get('note') || '' : '',
            boardPhotoId: model ? model.get('photoId') || null : null,
        };
    }

    /** @private */
    shiftMonths(date, months) {
        const [year, month, day] = date.split('-').map(Number);

        return new Date(Date.UTC(year, month - 1 + months, day)).toISOString().slice(0, 10);
    }
}

export default TeamBoardMemberListView;

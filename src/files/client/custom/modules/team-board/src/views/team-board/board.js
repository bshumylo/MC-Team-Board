import View from 'view';

/**
 * Team Board — kanban-style board of teams.
 *
 * Columns are teams; cards are team members grouped by their position
 * within the team (stored in the Team-User relationship). The position
 * list is dynamic — taken from the `positionList` field of each team
 * (with a fallback to the default list). The layout is driven purely by
 * position ORDER, never by a position's name (U16): with more than one
 * position, the first one is shown as small avatars in the column header
 * corner (U15 calls this out for the typical list's Leader/Vice Leader,
 * but any first position qualifies); positions 2-3 form the compact command row;
 * position 4 onward are equal-weight groups; the last position is always
 * the ordinary rank-and-file group, whatever it is named.
 *
 * Drag & drop between columns changes the team; drag & drop between
 * groups of one column changes the position; drag & drop within one
 * group changes the personal visual order (saved in Preferences);
 * drag & drop onto the empty area outside the columns removes the user
 * from the team (the user record itself is never deleted). Columns themselves can be reordered by
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
        'click [data-action="addFreeUser"]': function (e) {
            const el = e.currentTarget;

            this.move(
                el.dataset.userId,
                el.dataset.teamId,
                null,
                el.dataset.position
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
        /** @this TeamBoardView */
        'click [data-action="selectMonth"]': function (e) {
            const month = e.currentTarget.dataset.month;
            const day = this.asOfDate.slice(8);
            const end = this.monthEnd(month);
            const candidate = month + '-' + day;

            this.goToDate(candidate > end ? end : candidate);
        },
        /** @this TeamBoardView */
        'change [data-action="setAsOfDate"]': function (e) {
            const value = e.currentTarget.value;

            if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                return;
            }

            this.goToDate(value);
        },
        /** @this TeamBoardView */
        'click [data-action="openDatePicker"]': function (e) {
            this.openDatePicker(e.currentTarget);
        },
        /** @this TeamBoardView */
        'keydown [data-action="openDatePicker"]': function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') {
                return;
            }

            e.preventDefault();
            this.openDatePicker(e.currentTarget);
        },
        /** @this TeamBoardView */
        'click [data-action="goToToday"]': function () {
            this.goToDate(this.today());
        },
        'click [data-action="retryTimeline"]': function () {
            this.goToDate(this.asOfDate);
        },
        /** @this TeamBoardView */
        'click [data-action="setOriginMode"]': function (e) {
            const mode = e.currentTarget.dataset.mode;

            if (!['all', 'system', 'board'].includes(mode) || mode === this.originMode) {
                return;
            }

            this.originMode = mode;
            this.getPreferences().save({teamBoardOriginMode: mode}, {patch: true});

            this.reRender();
        },
        /** @this TeamBoardView */
        'click [data-action="autoArrange"]': function (e) {
            e.preventDefault();
            this.autoArrange();
        },
        /** @this TeamBoardView */
        'click [data-action="manageTeams"]': function (e) {
            e.preventDefault();
            this.openTeamManager();
        },
        'click [data-action="openTeamSettings"]': function (e) {
            e.preventDefault();
            this.openTeamManager(e.currentTarget.dataset.teamId);
        },
        'click [data-action="archiveTeam"]': function (e) {
            e.preventDefault();
            this.archiveTeam(e.currentTarget.dataset.teamId);
        },
        /** @this TeamBoardView */
        'click [data-action="openPerson"]': function (e) {
            e.preventDefault();
            e.stopPropagation();
            const member = this.findMember(e.currentTarget.dataset.boardMemberId);

            if (member) {
                this.openPerson(member);
            }
        },
        /** @this TeamBoardView */
        'click [data-action="viewPeriods"]': function (e) {
            e.preventDefault();
            e.stopPropagation();
            const member = this.findMember(e.currentTarget.dataset.boardMemberId || e.currentTarget.dataset.userId);
            if (member) this.openPerson(member);
        },
        /** @this TeamBoardView */
        'keydown [data-action="viewPeriods"]': function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') {
                return;
            }

            e.preventDefault();
            e.currentTarget.click();
        },
    }

    setup() {
        this.boardData = {
            date: this.today(),
            teams: [],
            positionList: [],
            canManage: false,
            canEditLive: false,
            reserve: [],
            assignments: [],
        };
        this.timelineError = null;

        this.asOfDate = this.today();
        this.visibleMonth = this.asOfDate.slice(0, 7);
        this._monthScrollLeft = 0;
        const savedOriginMode = this.getPreferences().get('teamBoardOriginMode');

        // Old List preferences are ignored; all origins remain visible by default.
        this.originMode = ['all', 'system', 'board'].includes(savedOriginMode)
            ? savedOriginMode
            : 'all';
        this.viewMode = 'photos';

        this.wait(this.loadTimeline(this.asOfDate));
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

    /**
     * @private
     * @return {string}
     */
    today() {
        return this.systemToday();
    }

    /**
     * Fetch the composition at a date and the bounded period history around it.
     * The legacy request is a compatibility fallback while a server is being
     * upgraded; its shape keeps the existing board functional.
     *
     * @private
     * @param {string} date
     * @return {Promise}
     */
    loadTimeline(date) {
        const generation = this._timelineGeneration = (this._timelineGeneration || 0) + 1;
        const isCurrent = () => !this._timelineRemoved && this._timelineGeneration === generation;
        const from = this.shiftMonths(date, -6);
        const to = this.shiftMonths(date, 6);

        return Espo.Ajax
            .getRequest('TeamBoard/timeline', {date: date, from: from, to: to})
            .then(data => {
                if (!isCurrent()) return false;
                this.timelineError = null;
                this.timelineErrorDetails = null;
                this.boardData = data;
                this.asOfDate = data.date;
                this.visibleMonth = data.date.slice(0, 7);
                return true;
            })
            .catch(error => {
                if (!isCurrent()) return false;
                this.timelineError = this.translate('timelineLoadError', 'messages', 'TeamBoard');
                this.timelineErrorDetails = error;
                this.asOfDate = date;
                this.visibleMonth = date.slice(0, 7);
                this.boardData = {
                    date, teams: [], positionList: [], reserve: [], assignments: [],
                    canManage: false, canEditLive: false,
                };
                throw error;
            });
    }

    /**
     * @private
     * @param {string} date `YYYY-MM-DD`
     * @param {number} months
     * @return {string}
     */
    shiftMonths(date, months) {
        const [year, month, day] = date.split('-').map(Number);
        const shifted = new Date(Date.UTC(year, month - 1 + months, day));

        return shifted.toISOString().slice(0, 10);
    }

    /**
     * @private
     * @param {string} date
     */
    goToDate(date) {
        const strip = this.element && this.element.querySelector('.tb-month-strip');

        if (strip) {
            this._monthScrollLeft = strip.scrollLeft;
        }

        Espo.Ui.notifyWait();

        const request = this.loadTimeline(date);
        const generation = this._timelineGeneration;
        const isCurrent = () => !this._timelineRemoved && this._timelineGeneration === generation;

        request
            .then(current => {
                if (!current || !isCurrent()) return;
                Espo.Ui.notify(false);

                this.reRender();
            })
            .catch(() => {
                if (!isCurrent()) return;
                Espo.Ui.notify(false);
                this.reRender();
            });
    }

    data() {
        const canManage = this.boardData.canManage;
        const visibleData = this.visibleTimelineData();
        this._pendingIds = this.pendingPersonIds(visibleData);
        // C03: the system-record link ("#Team/view/{id}") is gated on the
        // current user's Team read ACL, matching the check('scope', action)
        // pattern the controller already uses (controllers/team-board.js).
        const canReadTeam = this.getAcl().check('Team', 'read');

        const teams = this.applyOrder(visibleData.teams).map(team => {
            const positionList = this.teamPositionList(team);
            const togglable = this.togglablePositions(positionList);
            // U15: any stray "hidden" entry for a non-togglable position
            // (the header slot, the last/rank-and-file slot, or a 4th+
            // middle group) is ignored — those can never be hidden, even
            // if stale preference state from before this fix says so.
            const hidden = this.getHiddenPositions(team.id)
                .filter(position => togglable.includes(position));

            // U16: the header slot is the FIRST position in the team's own
            // order, whatever it is named — never a literal "Supervisor"
            // match. A single-position team has no header at all (U16
            // example: "1 position — rank-and-file only").
            const headerPosition = this.headerPositionOf(positionList);
            const hasSupervisorPosition = headerPosition !== null;

            const supervisors = !hasSupervisorPosition ? [] : team.members
                .filter(member =>
                    this.normalizePosition(member.position, positionList) === headerPosition)
                .sort((a, b) => (a.name || '').localeCompare(b.name || ''))
                .map(member => ({
                    id: member.id,
                    boardMemberId: member.boardMemberId || member.id,
                    isEspoUser: !!member.isEspoUser,
                    teamId: team.id,
                    canDrag: canManage,
                    name: member.name,
                    boardNote: member.boardNote || '',
                    tooltip: this.translate(headerPosition, 'positions', 'TeamBoard') +
                        ': ' + member.name,
                    avatarHtml: this.avatarHtml(member, 20, 'tb-sup-img'),
                    isPending: member.status === 'draft' || !!this._pendingIds?.has(member.id),
                    showBoardOnly: !member.isEspoUser,
                    boardOnlyLabel: this.translate('Board only — no EspoCRM account', 'labels', 'TeamBoard'),
                }));

            const settingsPositions = togglable.map(position => ({
                value: position,
                teamId: team.id,
                checked: !hidden.includes(position),
                label: this.positionLabelOf(position),
            }));

            return {
                id: team.id,
                boardSquadId: team.boardSquadId || team.id,
                // Board.tpl's `{{#if isEspoTeam}}` reads this to render the
                // CRM system-record link; it was computed for canArchive
                // below but never copied onto the object the template
                // receives, so the link branch could never fire (C03).
                isEspoTeam: !!team.isEspoTeam && canReadTeam,
                name: team.name,
                colourKey: team.colourKey || 'slate',
                columnSpan: this.getColumnSpan(team),
                columnHeight: this.getColumnHeight(team),
                columnWidth: this.getColumnWidth(team),
                count: this.visibleCount(team, positionList, hidden),
                supervisors: supervisors,
                hasSupervisors: supervisors.length > 0,
                hasSupervisorPosition: hasSupervisorPosition,
                headerPosition: headerPosition,
                supervisorsHidden: hasSupervisorPosition && hidden.includes(headerPosition),
                groups: this.composeGroups(team, positionList, hidden, canManage),
                photoRoleSlots: this.composePhotoRoleSlots(team, positionList, hidden, canManage),
                canArchive: !team.isEspoTeam && canManage,
                settingsPositions: settingsPositions,
                // List mode keeps its existing menu source. The Photos board
                // renders the same people once, in the shared Reserve section.
                reserve: visibleData.reserve.map(user => ({
                    id: user.id,
                    name: user.name,
                    teamId: team.id,
                    position: this.bottomPosition(positionList),
                })),
                hasReserve: visibleData.reserve.length > 0,
            };
        });

        const reserveMembers = visibleData.reserve
            .map(user => this.composeReserveMember(user, canManage));

        return {
            title: this.translate('Team Board', 'labels', 'TeamBoard'),
            totalUnique: this.uniqueMemberCount(visibleData),
            uniqueLabel: this.translate('Unique members', 'labels', 'TeamBoard'),
            pendingApprovalCount: this.pendingApprovalCount(visibleData),
            hasPendingApproval: this.pendingApprovalCount(visibleData) > 0,
            pendingApprovalLabel: this.pendingApprovalLabelFor(this.pendingApprovalCount(visibleData)),
            boardMenuLabel: this.translate('Board menu', 'labels', 'TeamBoard'),
            autoArrangeLabel: this.translate('Auto arrange', 'labels', 'TeamBoard'),
            manageTeamsLabel: this.translate('Manage teams', 'labels', 'TeamBoard'),
            manageUsersLabel: this.translate('Manage users', 'labels', 'TeamBoard'),
            archiveTeamLabel: this.translate('Archive team', 'labels', 'TeamBoard'),
            resizeTeamLabel: this.translate('Resize team', 'labels', 'TeamBoard'),
            noTeams: teams.length === 0,
            noTeamsLabel: this.translate('No teams', 'labels', 'TeamBoard'),
            removeDropLabel: this.translate('Drop here to remove', 'labels', 'TeamBoard'),
            settingsLabel: this.translate('Visible positions', 'labels', 'TeamBoard'),
            addMemberLabel: this.translate('Add member', 'labels', 'TeamBoard'),
            asOfDate: this.asOfDate,
            asOfDateReadable: this.getDateTime().toDisplayDate(this.asOfDate),
            monthTabs: this.monthTabs(),
            monthMin: this.visibleMonth + '-01',
            monthMax: this.monthEnd(this.visibleMonth),
            todayLabel: this.translate('Today', 'labels', 'TeamBoard'),
            isTodayActive: this.asOfDate === this.today(),
            asOfLabel: this.translate('Composition as of', 'labels', 'TeamBoard'),
            viewMode: 'photos',
            isPhotos: true,
            originMode: this.originMode,
            isAllOrigins: this.originMode === 'all',
            isSystemOnly: this.originMode === 'system',
            isBoardOnly: this.originMode === 'board',
            originModeLabel: this.translate('Origin filter', 'labels', 'TeamBoard'),
            systemOnlyLabel: this.translate('System only', 'labels', 'TeamBoard'),
            boardOnlyModeLabel: this.translate('Board only', 'labels', 'TeamBoard'),
            allOriginsLabel: this.translate('All', 'labels', 'TeamBoard'),
            draftLabel: this.translate('Draft', 'labels', 'TeamBoard'),
            confirmedLabel: this.translate('Confirmed', 'labels', 'TeamBoard'),
            cardWidth: this.getConfig().get('teamBoardCardWidth') || 260,
            avatarSize: this.getConfig().get('teamBoardAvatarSize') || 52,
            timelineError: this.timelineError,
            timelineErrorLabel: this.translate('Retry', 'labels', 'TeamBoard'),
            canManage: canManage,
            teams: teams,
            reserveMembers: reserveMembers,
            reserveLabel: this.translate('Reserve', 'labels', 'TeamBoard'),
            reserveCount: reserveMembers.length,
            reserveDescription: this.translate(
                reserveMembers.length === 1 ?
                    'Member without an active team' :
                    'Members without an active team',
                'labels',
                'TeamBoard'
            ),
        };
    }

    /** @private */
    openTeamManager(teamId = null) {
        this.createView('teamManager', 'team-board:views/team-board/modals/team-manager', {
            teams: this.boardData.teams || [],
            columnSpans: this.getPreferences().get('teamBoardColumnSpans') || {},
            viewDate: this.asOfDate,
        }).then(view => {
            this.listenTo(view, 'done', data => {
                if (data) {
                    this.boardData = data;
                }
                this.goToDate(this.asOfDate);
            });
            this.listenTo(view, 'widths', spans => {
                this.getPreferences().save({teamBoardColumnSpans: spans}, {patch: true});
                this.reRender();
            });
            view.render();

            if (teamId) {
                const team = (this.boardData.teams || []).find(item =>
                    (item.boardSquadId || item.id) === teamId
                );

                if (team) {
                    view.openTeam(team);
                }
            }
        });
    }

    archiveTeam(id) {
        this.confirm(this.translate('Historical assignments remain', 'labels', 'TeamBoard')).then(() => {
            Espo.Ui.notifyWait();
            return Espo.Ajax.postRequest(`TeamBoard/squad/${id}/archive`, {viewDate: this.asOfDate});
        }).then(() => {
            Espo.Ui.notify(false);
            this.goToDate(this.asOfDate);
        }).catch(() => Espo.Ui.notify(false));
    }

    /** @private */
    /** O10: use the protected record API, including people outside today's teams. */
    async openPersonById(id) {
        if (typeof id !== 'string' || !/^[a-zA-Z0-9]+$/.test(id)) return;

        let record;
        try {
            record = await Espo.Ajax.getRequest('TeamBoardMember/' + id);
        } catch (e) {
            // Ajax displays the native denial/not-found message. Never invent a card.
            return;
        }

        const userId = record.userId || null;
        let user = null;
        if (userId) {
            try {
                user = await Espo.Ajax.getRequest('User/' + encodeURIComponent(userId));
            } catch (e) {
                return;
            }
        }
        this.openPerson({
            id: userId || record.id,
            boardMemberId: record.id,
            userId,
            isEspoUser: !!userId,
            name: user && user.name ||
                [record.personFirstName, record.personLastName].filter(Boolean).join(' ') ||
                record.name || user && user.userName || '',
            firstName: record.personFirstName || '',
            lastName: record.personLastName || '',
            boardNote: record.note || '',
            boardPhotoId: record.photoId || null,
            systemAvatarId: user && user.avatarId || null,
            avatarColor: user && user.avatarColor || null,
            isArchived: !!record.isArchived,
        }, {
            // O10: once the routed card closes, return the URL to the board so
            // the identical notification link opens the card again.
            onClose: () => {
                if (window.location.hash !== '#TeamBoard/view/' + id) return;
                this.getRouter().navigate('#TeamBoard', {trigger: false, replace: true});
            },
        });
    }

    openPerson(member, options = {}) {
        // Re-rendering the board tears its own template down and rebuilds
        // it; 'person' is a createView()-registered nested view with no
        // placeholder in board.tpl, so it is not part of that template, yet
        // a live DOM trace (MutationObserver on document.body across a save)
        // showed the *entire* open dialog element (.dialog.dialog-record)
        // removed from .modal-container and replaced with a fresh, empty
        // one the instant the board redraws -- confirmed with a plain,
        // fully synchronous redraw too, so this is not a timing race with
        // the dialog's own inline-edit-close, it is the board redrawing
        // itself. Confirmed by an independent adversarial review after an
        // earlier fix here (deferring only the async goToDate call) still
        // reproduced the blank dialog on the very first synchronous
        // redraw. The one safe rule: never redraw the board or call
        // goToDate() while this dialog is open, for any save, including
        // photo. Every refresh is deferred to the dialog's own 'close'
        // event (or run immediately if it is already closed when a late
        // response arrives).
        let dialogClosed = false;
        let pendingRefresh = false;

        this.createView('person', 'team-board:views/team-board/modals/person', {
            member: member,
            canManage: !!this.boardData.canManage && !member.isArchived,
            assignments: this.boardData.assignments || [],
            teams: this.boardData.teams || [],
            viewDate: this.asOfDate,
        }).then(view => {
            // Every applied field, photo change and creation reports done;
            // the card, its name and its tooltip catch up once the dialog
            // closes. A response that arrives after that point runs at once.
            this.listenTo(view, 'done', () => {
                if (dialogClosed) {
                    this.goToDate(this.asOfDate);
                } else {
                    pendingRefresh = true;
                }
            });
            this.listenToOnce(view, 'close', () => {
                dialogClosed = true;

                if (options.onClose) options.onClose();
                if (pendingRefresh) this.goToDate(this.asOfDate);
            });
            view.render();
        });
    }

    /** @private */
    findMember(boardMemberId) {
        for (const team of this.boardData.teams || []) {
            const member = (team.members || [])
                .find(item => item.boardMemberId === boardMemberId);

            if (member) {
                return member;
            }
        }

        return (this.boardData.reserve || [])
            .find(item => item.boardMemberId === boardMemberId) || null;
    }

    /**
     * Apply the origin filter after the server has calculated actual active
     * membership. This deliberately filters the already-composed Reserve
     * instead of rebuilding it from the visible teams, so a CRM user assigned
     * to a hidden board-only team is not incorrectly moved to Reserve.
     *
     * @private
     * @return {{teams: Object[], reserve: Object[]}}
     */
    visibleTimelineData() {
        const systemOnly = this.originMode === 'system';
        const boardOnly = this.originMode === 'board';
        const teamMatches = team => {
            if (systemOnly) return !!team.isEspoTeam;
            if (boardOnly) return !team.isEspoTeam;
            return true;
        };
        const memberMatches = member => {
            if (systemOnly) return !!member.isEspoUser;
            if (boardOnly) return !member.isEspoUser;
            return true;
        };
        const teams = (this.boardData.teams || [])
            .filter(teamMatches)
            .map(team => ({
                ...team,
                members: (team.members || []).filter(memberMatches),
            }));
        const reserve = (this.boardData.reserve || []).filter(memberMatches);

        return {teams, reserve};
    }

    /**
     * @private
     * @return {number}
     */
    uniqueMemberCount(data = this.visibleTimelineData()) {
        const ids = new Set();

        (data.teams || []).forEach(team => {
            (team.members || []).forEach(member => ids.add(member.id));
        });

        return ids.size;
    }

    /**
     * Pluralised "{count} awaiting approval" text: the `...One` form is
     * used for the CLDR "one" category of the user's language (Ukrainian:
     * 1, 21, 31 but not 11); other counts use the base form.
     *
     * @param {number} count
     * @return {string}
     */
    pendingApprovalLabelFor(count) {
        const n = Math.abs(Number(count)) || 0;
        const locale = String(this.getLocaleCode ? this.getLocaleCode() : 'en').replace('_', '-');
        let category = 'other';

        try {
            category = new Intl.PluralRules(locale).select(n);
        } catch (e) {
            category = n === 1 ? 'one' : 'other';
        }

        let text = category === 'one' ?
            this.translate('pendingApprovalCountOne', 'labels', 'TeamBoard') :
            '';

        if (!text || text === 'pendingApprovalCountOne') {
            text = this.translate('pendingApprovalCount', 'labels', 'TeamBoard');
        }

        return text.replace('{count}', String(count));
    }

    /**
     * @private
     * @return {string}
     */
    getLocaleCode() {
        const prefs = this.getPreferences && this.getPreferences();
        const user = this.getUser && this.getUser();
        const config = this.getConfig && this.getConfig();

        return (prefs && prefs.get && prefs.get('language')) ||
            (user && user.get && user.get('language')) ||
            (config && config.get && config.get('language')) ||
            'en';
    }

    /**
     * U02: count of unique people with a pale avatar (see pendingPersonIds):
     * a Draft in effect or a future Draft plan, visible in a team or Reserve.
     *
     * @private
     * @return {number}
     */
    pendingApprovalCount(data = this.visibleTimelineData()) {
        return this.pendingPersonIds(data).size;
    }

    /**
     * The single rule for "has an unapproved (Draft) period": the person has
     * a Draft in effect in a visible team, or a future Draft plan (not
     * earlier than the viewed date) and is visible in a team or in Reserve.
     * The same set drives the pale avatar and the "awaiting approval" counter.
     *
     * @private
     * @param {{teams: Object[], reserve: Object[]}} data Origin-filtered data.
     * @return {Set<string>}
     */
    pendingPersonIds(data = this.visibleTimelineData()) {
        const ids = new Set();
        const visibleIds = new Set();

        (data.teams || []).forEach(team => {
            (team.members || []).forEach(member => {
                visibleIds.add(member.id);
                if (member.status === 'draft') {
                    ids.add(member.id);
                }
            });
        });

        (data.reserve || []).forEach(user => visibleIds.add(user.id));

        (this.boardData?.pendingDrafts || []).forEach(plan => {
            if (plan.dateFrom >= this.asOfDate && visibleIds.has(plan.id)) {
                ids.add(plan.id);
            }
        });

        return ids;
    }

    /**
     * @private
     * @return {Object[]}
     */
    monthTabs() {
        const tabs = [];
        const language = this.getLanguage();
        const monthNamesShort = typeof language.get === 'function' ?
            language.get('Global', 'lists', 'monthNamesShort') || [] :
            [];

        for (let offset = -6; offset <= 5; offset++) {
            const value = this.shiftMonths(this.visibleMonth + '-01', offset).slice(0, 7);
            const monthIndex = Number(value.slice(5, 7)) - 1;

            tabs.push({
                value: value,
                label: monthNamesShort[monthIndex] ||
                    this.getDateTime().toDisplayDate(value + '-01').replace(/^\d+\s/, ''),
                year: String(value.slice(0, 4)),
                active: value === this.visibleMonth,
            });
        }

        return tabs;
    }

    /**
     * @private
     * @param {string} month
     * @return {string}
     */
    monthEnd(month) {
        const [year, monthNumber] = month.split('-').map(Number);
        const last = new Date(Date.UTC(year, monthNumber, 0));

        return last.toISOString().slice(0, 10);
    }

    /**
     * Opens the native picker from the whole styled date control.
     * Browsers without `showPicker` still receive focus and retain their
     * normal input behavior.
     *
     * @private
     * @param {HTMLElement} control
     */
    openDatePicker(control) {
        const input = control.querySelector('input[type="date"]');

        if (!input) {
            return;
        }

        input.focus();

        if (typeof input.showPicker === 'function') {
            try {
                input.showPicker();
            } catch (e) {
                // A browser can reject a duplicate picker request from the
                // input's own click. The normal native click still works.
            }
        }
    }

    /**
     * Automatic responsive width derived from the active roster size.
     *
     * @private
     * @param {number} count
     * @return {number}
     */
    autoColumnSpan(count) {
        let span = 1;

        if (count > 24) {
            span = 4;
        }
        else if (count > 15) {
            span = 3;
        }
        else if (count > 7) {
            span = 2;
        }

        return Math.min(span, this.maxGridSpan());
    }

    /**
     * @private
     * @param {Object} team
     * @return {number}
     */
    getColumnSpan(team) {
        const saved = this.getPreferences().get('teamBoardColumnSpans');
        const value = saved && Number(saved[team.id]);

        return Number.isFinite(value)
            ? Math.max(1, Math.min(this.maxGridSpan(), Math.round(value)))
            : this.autoColumnSpan((team.members || []).length);
    }

    /** @private */
    maxGridSpan() {
        const board = this.element && (
            this.element.querySelector('.tb-team-columns') ||
            this.element.querySelector('.team-board'));

        if (!board || board.clientWidth < 1) {
            const viewport = typeof window !== 'undefined' ? window.innerWidth : 1200;
            return Math.max(1, Math.floor((viewport - 40 + 12) / (288 + 12)));
        }

        const styles = window.getComputedStyle(board);
        const gap = parseFloat(styles.columnGap || styles.gap || '12') || 12;
        const minWidth = board.clientWidth <= 800 ? 180 : 288;

        return Math.max(1, Math.floor((board.clientWidth + gap) / (minWidth + gap)));
    }

    /** @private */
    getColumnHeight(team) {
        const saved = this.getPreferences().get('teamBoardColumnHeights');
        const value = saved && Number(saved[team.id]);

        return Number.isFinite(value) && value >= 180 ? `${Math.round(value)}px` : 'auto';
    }

    /**
     * A card is always exactly as wide as its grid span, the way a GridStack
     * dashlet is always a whole number of dashboard columns. Free pixel widths
     * saved by earlier development builds are ignored, because a pixel width
     * kept across window sizes stops matching the track and the card then
     * covers its neighbour.
     *
     * @private
     */
    getColumnWidth() {
        return '';
    }

    /**
     * Persist the grid span and height, matching a native dashlet resize.
     *
     * @private
     * @param {string} teamId
     * @param {number} span
     * @param {number} height
     */
    saveColumnSize(teamId, span, height) {
        const savedSpans = this.getPreferences().get('teamBoardColumnSpans');
        const spans = Object.assign({}, savedSpans && typeof savedSpans === 'object' ? savedSpans : {});
        const numericSpan = Number(span);
        const numericHeight = Number(height);

        if (!Number.isFinite(numericSpan) || !Number.isFinite(numericHeight)) {
            return;
        }

        spans[teamId] = Math.max(1, Math.min(this.maxGridSpan(), Math.round(numericSpan)));

        const savedHeights = this.getPreferences().get('teamBoardColumnHeights');
        const heights = Object.assign({}, savedHeights && typeof savedHeights === 'object' ? savedHeights : {});
        heights[teamId] = Math.max(180, Math.min(1600, Math.round(numericHeight)));

        const savedWidths = this.getPreferences().get('teamBoardColumnWidths');
        const widths = Object.assign({}, savedWidths && typeof savedWidths === 'object' ? savedWidths : {});

        // The span carries the width now; drop any legacy pixel value so an
        // old preference cannot outlive this resize.
        delete widths[teamId];

        this.getPreferences().save({
            teamBoardColumnSpans: spans,
            teamBoardColumnHeights: heights,
            teamBoardColumnWidths: widths,
        }, {patch: true});
    }

    /**
     * Return team order and sizes to their data-driven defaults.
     *
     * @private
     */
    autoArrange() {
        const order = [...(this.boardData.teams || [])]
            .sort((a, b) =>
                ((b.members || []).length - (a.members || []).length) ||
                (a.name || '').localeCompare(b.name || '')
            )
            .map(team => team.id);
        const spans = {};

        (this.boardData.teams || []).forEach(team => {
            spans[team.id] = this.autoColumnSpan((team.members || []).length);
        });

        this.getPreferences().save({
            teamBoardColumnSpans: spans,
            teamBoardColumnHeights: {},
            teamBoardColumnWidths: {},
            teamBoardOrder: order,
        }, {patch: true});

        this.reRender();
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
     * The header-slot position (U16): the FIRST position of the team's own
     * order, by position, never by name. A team with one position or none
     * has no header slot — that single position is rank-and-file only.
     *
     * @private
     */
    headerPositionOf(positionList) {
        return positionList.length > 1 ? positionList[0] : null;
    }

    /**
     * Hierarchy positions of a team, without the header slot (U16: order
     * decides, not a literal name like "Supervisor").
     *
     * @private
     */
    bodyPositions(positionList) {
        const header = this.headerPositionOf(positionList);

        if (header === null) {
            return positionList;
        }

        const index = positionList.indexOf(header);

        return positionList.filter((position, i) => i !== index);
    }

    /**
     * The top (highlighted, exclusive) position — same as the header slot.
     *
     * @private
     */
    topPosition(positionList) {
        return this.headerPositionOf(positionList);
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

    isPositionVisible(teamId, position) {
        return !this.getHiddenPositions(teamId).includes(position);
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

        const team = this.boardData.teams.find(item => item.id === teamId);

        // U16: the header slot is order-based (the first position of this
        // team's own list), never a literal "Supervisor" match — a team
        // whose first position is named e.g. "Chief" must still toggle its
        // `.tb-sups` header block, not a nonexistent group/slot.
        const isHeaderPosition = !!team &&
            this.headerPositionOf(this.teamPositionList(team)) === position;

        const target = isHeaderPosition ?
            col.querySelector('.tb-sups') :
            col.querySelector(`.tb-role-slot[data-position="${CSS.escape(position)}"]`) ||
            col.querySelector(`.tb-group[data-position="${CSS.escape(position)}"]`);

        if (target) {
            target.classList.toggle('tb-hidden', i === -1);
        }

        this.syncSoloCommandSlot(col);

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
                isPhotoRole: this.isPhotoRole(position, positionList),
                isHidden: hidden.includes(position),
                label: this.positionLabelOf(position),
                members: members,
                isEmpty: members.length === 0,
                accessibleLabel: this.translate('Drop here', 'labels', 'TeamBoard') +
                    ' — ' + this.positionLabelOf(position),
            };
        });
    }

    /**
     * Command positions get a dedicated, always-visible planning target in
     * Photos. Everything else remains a ranked member group.
     *
     * @private
     */
    isPhotoRole(position, positionList) {
        return this.photoRolePositions(positionList).includes(position);
    }

    /**
     * U16: positions 2-3 (the first two positions after the header slot)
     * form the compact command row, and the last position (rank-and-file)
     * gets its own always-visible slot when it directly follows them; with a
     * 4th+ position, positions 4..last are equal groups in list order. Order
     * decides this, not any position's name.
     *
     * @private
     */
    photoRolePositions(positionList) {
        const body = this.bodyPositions(positionList);
        const command = body.slice(0, 2);
        const member = this.bottomPosition(positionList);

        // With a 4th+ position the last one is an ordinary rank group placed
        // after it in list order, not a separate slot above it (U16).
        if (body.length > 3) {
            return command;
        }

        return [...new Set([...command, member])];
    }

    /**
     * U15: the "Visible positions" toggle controls positions 2-3 only —
     * never the header slot (position 1) and never the last/rank-and-file
     * position, which must always stay visible. When the team has 3 or
     * fewer positions, positions 2-3 collapse with the last position (e.g.
     * a 3-position team's only body positions are the command slot and the
     * rank-and-file slot), so only the ones that are NOT the last position
     * are togglable.
     *
     * @private
     */
    togglablePositions(positionList) {
        const bottom = this.bottomPosition(positionList);

        return this.bodyPositions(positionList)
            .slice(0, 2)
            .filter(position => position !== bottom);
    }

    /**
     * Build the compact command row from the exact same members and position
     * vocabulary used by List. A vacant slot is a drag target, not a synthetic
     * person, so the assignment dialog stays the only mutation path.
     *
     * @private
     */
    composePhotoRoleSlots(team, positionList, hidden, canManage) {
        const slots = this.photoRolePositions(positionList)
            .map(position => {
                const members = this.sortMembers(
                    team.members.filter(member =>
                        this.normalizePosition(member.position, positionList) === position),
                    team.id,
                    position
                ).map(member => this.composeMember(member, team, positionList, canManage));

                return {
                    teamId: team.id,
                    position: position,
                    label: this.positionLabelOf(position),
                    members: members,
                    isMemberSlot: position === this.bottomPosition(positionList),
                    isVacant: members.length === 0,
                    // U15: `hidden` was already filtered to togglable
                    // positions only (never the header or last/bottom
                    // slot) — use it directly instead of re-reading raw,
                    // unfiltered preference state.
                    visible: !hidden.includes(position),
                    canManage: canManage,
                    reserveUsers: this.visibleTimelineData().reserve.map(user => ({
                        id: user.id,
                        name: user.name,
                        teamId: team.id,
                        position: position,
                    })),
                    hasReserve: this.visibleTimelineData().reserve.length > 0,
                    accessibleLabel: this.translate('Drop here', 'labels', 'TeamBoard') +
                        ' — ' + this.positionLabelOf(position),
                };
            });

        // U16: when only one of positions 2-3 is visible (the other is
        // absent or toggled off), that one is centered in the command row.
        const solo = this.soloCommandPosition(slots);

        return slots.map(slot => ({...slot, isSolo: slot.position === solo}));
    }

    /**
     * U16: the single visible command slot (positions 2-3), or null when
     * both or neither are visible. The rank-and-file slot never counts.
     *
     * @private
     */
    soloCommandPosition(slots) {
        const visible = slots.filter(slot => !slot.isMemberSlot && slot.visible);

        return visible.length === 1 ? visible[0].position : null;
    }

    /**
     * U16: re-evaluate the centered solo command slot after a live U15
     * toggle, which flips `tb-hidden` without re-rendering the card.
     *
     * @private
     */
    syncSoloCommandSlot(col) {
        const slots = [...col.querySelectorAll('.tb-role-slot')].map(el => ({
            el: el,
            position: el.dataset.position,
            isMemberSlot: el.classList.contains('tb-role-slot-members'),
            visible: !el.classList.contains('tb-hidden'),
        }));

        const solo = this.soloCommandPosition(slots);

        slots.forEach(slot => slot.el.classList.toggle('tb-role-slot-solo', slot.position === solo));
    }

    /**
     * Reserve has no source team at the selected date. Its cards still carry
     * the normal avatar, period action and drag contract, and become an add
     * when dropped into a role or member group.
     *
     * @private
     */
    composeReserveMember(user, canManage) {
        const label = this.translate('Reserve', 'labels', 'TeamBoard');

        // U21: the "⋯" menu is available for Reserve avatars too, offering
        // the same compact-viewport "move to team" list as team cards (a
        // Reserve member has no current team, so every team is a target).
        const visibleTeams = this.visibleTimelineData().teams;
        const menuTeams = visibleTeams.map(item => ({
            toTeamId: item.id,
            fromTeamId: '',
            userId: user.id,
            position: this.bottomPosition(this.teamPositionList(item)),
            label: item.name,
        }));

        return {
            id: user.id,
            boardMemberId: user.boardMemberId || user.id,
            teamId: '',
            position: '',
            name: user.name,
            positionLabel: label,
            avatarHtml: this.avatarHtml(
                user,
                this.getConfig().get('teamBoardAvatarSize') || 52,
                'tb-avatar-img'
            ),
            canManage: canManage,
            showBoardOnly: !user.isEspoUser,
            boardOnlyLabel: this.translate('Board only — no EspoCRM account', 'labels', 'TeamBoard'),
            hasStatus: (user.warnings || []).length > 0,
            statusLabel: this.statusLabel(user),
            isDraft: user.status === 'draft',
            isPending: user.status === 'draft' || !!this._pendingIds?.has(user.id),
            boardNote: user.boardNote || '',
            tooltip: this.memberTooltip(user, null),
            accessibleLabel: `${user.name} — ${label}` +
                (this.statusLabel(user) ? ` — ${this.statusLabel(user)}` : ''),
            menuTeams: menuTeams,
            hasMenuTeams: menuTeams.length > 0,
        };
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

        const visibleTeams = this.visibleTimelineData().teams;

        // U21: there is no "add to another team" action on the board — a
        // member's extra system memberships are managed in CRM directly.
        // Only a plain move (to a different team) is offered here.
        const menuTeams = visibleTeams
            .filter(item => item.id !== team.id)
            .map(item => ({
                toTeamId: item.id,
                fromTeamId: team.id,
                userId: member.id,
                position: this.bottomPosition(this.teamPositionList(item)),
                label: item.name,
            }));

        return {
            id: member.id,
            boardMemberId: member.boardMemberId || member.id,
            boardSquadId: team.boardSquadId || team.id,
            teamId: team.id,
            position: position,
            name: member.name,
            positionLabel: positionLabel,
            avatarHtml: this.avatarHtml(
                member,
                this.getConfig().get('teamBoardAvatarSize') || 52,
                'tb-avatar-img'
            ),
            isEspoUser: !!member.isEspoUser,
            showBoardOnly: !member.isEspoUser,
            boardOnlyLabel: this.translate('Board only — no EspoCRM account', 'labels', 'TeamBoard'),
            isTop: position === this.topPosition(positionList),
            canManage: canManage,
            hasStatus: (member.warnings || []).length > 0,
            statusLabel: this.statusLabel(member, team),
            zone: member.zone,
            assignmentId: member.assignmentId,
            isDraft: member.status === 'draft',
            isPending: member.status === 'draft' || !!this._pendingIds?.has(member.id),
            draftOriginLabel: this.draftOriginLabel(member),
            boardNote: member.boardNote || '',
            tooltip: this.memberTooltip(member, team),
            accessibleLabel: [
                this.memberTooltip(member, team),
                positionLabel,
                this.translate(
                    member.status === 'draft' ? 'Draft' : 'Confirmed',
                    'labels',
                    'TeamBoard'
                ),
            ].join(' — '),
            menuPositions: menuPositions,
            menuTeams: menuTeams,
            hasMenuTeams: menuTeams.length > 0,
            removeLabel: this.translate('Remove from team', 'labels', 'TeamBoard'),
        };
    }

    /** @private */
    avatarHtml(member, size, className) {
        // A board photo is a local fallback only when CRM has no system photo.
        // A CRM avatar always wins and is never modified by Team Board.
        if (member.boardPhotoId && !member.systemAvatarId) {
            const image = document.createElement('img');
            image.className = className;
            image.width = size;
            image.height = size;
            image.alt = member.name || '';
            image.src = this.getBasePath() + '?entryPoint=image&size=small&id=' +
                encodeURIComponent(member.boardPhotoId);

            return image.outerHTML;
        }

        if (member.userId) {
            return this.getHelper().getAvatarHtml(member.userId, 'small', size, className);
        }

        const fallback = document.createElement('span');
        fallback.className = className + ' avatar tb-avatar-fallback';
        fallback.setAttribute('aria-hidden', 'true');
        const initials = [member.firstName, member.lastName]
            .filter(value => typeof value === 'string' && value.trim())
            .map(value => value.trim().charAt(0))
            .join('') || (member.name || '?').trim().charAt(0);
        fallback.textContent = initials.toUpperCase() || '?';

        return fallback.outerHTML;
    }

    /**
     * U06: a Draft in effect on the viewed date is shown only at its
     * destination; the marker names the place the person comes from.
     *
     * @private
     */
    draftOriginLabel(member) {
        const origin = member.status === 'draft' ? member.draftOrigin : null;

        if (!origin || (!origin.isReserve && !origin.name)) {
            return '';
        }

        return this.translate('draftOriginFrom', 'labels', 'TeamBoard')
            .replace('{name}', origin.isReserve ? this.translate('Reserve', 'labels', 'TeamBoard') : origin.name);
    }

    /**
     * Keep operational warnings legible to organisers without exposing a
     * database-level explanation in the card itself.
     *
     * @private
     */
    memberTooltip(member, team) {
        const parts = [member.name];

        if (member.boardNote) {
            parts.push(member.boardNote);
        }

        if (!team) {
            return parts.join('\n');
        }

        if (this.draftOriginLabel(member)) {
            parts.push(this.draftOriginLabel(member));
        }

        if ((member.warnings || []).includes('overlap')) {
            const others = (this.boardData.teams || [])
                .filter(item => item.id !== team.id)
                .filter(item => (item.members || []).some(entry => entry.id === member.id))
                .map(item => item.name);

            if (others.length) {
                parts.push(
                    this.translate('overlapWarning', 'messages', 'TeamBoard')
                        .replace('{date}', this.getDateTime().toDisplayDate(member.dateFrom))
                        .replace('{teamA}', team.name)
                        .replace('{teamB}', others.join(', '))
                );
            }
        }

        return parts.join('\n');
    }

    /** @private */
    statusLabel(member, team = null) {
        if ((member.warnings || []).includes('draftDue')) {
            return this.translate('draftDueWarning', 'messages', 'TeamBoard')
                .replace('{date}', this.getDateTime().toDisplayDate(member.draftDueDate || member.dateFrom));
        }

        if ((member.warnings || []).includes('overlap')) {
            const others = team ? (this.boardData.teams || [])
                .filter(item => item.id !== team.id)
                .filter(item => (item.members || []).some(entry => entry.id === member.id))
                .map(item => item.name) : [];

            return this.translate('overlapIndicator', 'messages', 'TeamBoard')
                .replace('{teams}', others.join(', ') || this.translate('another team', 'labels', 'TeamBoard'));
        }

        return '';
    }

    afterRender() {
        this.resetDragState();
        this.initColumnReorder();
        this.initColumnResize();
        this.positionSupervisors();
        this.initSupervisorReflow();
        this.initFloatingScrollbar();
        this.initMenuPositionFix();
        this.restoreMonthScroll();

        if (!this.boardData.canManage) {
            return;
        }

        this.initDragAndDrop();
        this.initTouchDragAndDrop();
    }


    /** Persist a direct south-east pointer resize like a dashboard dashlet. */
    initColumnResize() {
        this.element.querySelectorAll('.tb-resize-handle').forEach(handle => {
            handle.addEventListener('pointerdown', event => {
                event.preventDefault();
                event.stopPropagation();
                const column = handle.closest('.tb-col');
                const board = column && column.parentElement;
                if (!column || !board) return;

                const startX = event.clientX;
                const startY = event.clientY;
                const startSpan = Number(column.dataset.columnSpan || 1);
                const startHeight = Math.max(180, column.offsetHeight);
                const styles = window.getComputedStyle(board);
                const gap = parseFloat(styles.columnGap || styles.gap || '12') || 12;
                const maxSpan = this.maxGridSpan();
                const unit = Math.max(180, (board.clientWidth + gap) / maxSpan);

                const move = e => {
                    // GridStack uses discrete grid columns for dashlet width;
                    // the card snaps to whole spans while the pointer moves, so
                    // the neighbours reflow live and nothing ever overlaps.
                    const span = Math.max(1, Math.min(maxSpan, Math.round(
                        startSpan + (e.clientX - startX) / unit
                    )));
                    const height = Math.max(180, Math.min(1600,
                        Math.round(startHeight + e.clientY - startY)
                    ));
                    column.dataset.columnSpan = String(span);
                    column.dataset.columnHeight = String(height);
                    column.dataset.columnWidth = '';
                    column.style.setProperty('--tb-column-span', String(span));
                    column.style.setProperty('--tb-column-height', `${height}px`);
                    column.style.removeProperty('--tb-column-width');
                };
                const finish = save => {
                    document.removeEventListener('pointermove', move);
                    document.removeEventListener('pointerup', onUp);
                    document.removeEventListener('pointercancel', onCancel);

                    if (!save) {
                        column.dataset.columnSpan = String(startSpan);
                        column.dataset.columnHeight = String(startHeight);
                        column.dataset.columnWidth = '';
                        column.style.setProperty('--tb-column-span', String(startSpan));
                        column.style.setProperty('--tb-column-height', `${startHeight}px`);
                        column.style.removeProperty('--tb-column-width');
                        this.positionSupervisors();
                        return;
                    }

                    const span = Number(column.dataset.columnSpan);
                    const height = Number(column.dataset.columnHeight);

                    if (Number.isFinite(span) && Number.isFinite(height)) {
                        this.saveColumnSize(column.dataset.teamId, span, height);
                    }
                    this.positionSupervisors();
                };

                const onUp = () => finish(true);
                const onCancel = () => finish(false);

                document.addEventListener('pointermove', move);
                document.addEventListener('pointerup', onUp, {once: true});
                document.addEventListener('pointercancel', onCancel, {once: true});
            });
        });
    }

    positionSupervisors() {
        this.element.querySelectorAll('.tb-col-head').forEach(head => {
            const title = head.querySelector('.tb-title');
            const teamName = head.querySelector('.tb-team-name');
            const count = head.querySelector('.tb-count');
            const supervisors = head.querySelector('.tb-sups');
            if (!title || !supervisors) return;

            const titleWidth = (teamName ? teamName.scrollWidth : title.scrollWidth) +
                (count ? count.offsetWidth : 0) + 6;
            const menus = [...head.querySelectorAll('.tb-col-menu')];
            const menuWidth = menus.reduce((total, menu) => total + menu.offsetWidth, 0);
            // board.tpl centres `.tb-sups` at 50% of the head's own padding
            // box (`left: 50%; transform: translateX(-50%)` on a
            // `position: relative` .tb-col-head) -- that coordinate frame
            // starts at the padding box's left edge, not at the flex
            // content box. So the left-aligned title really starts
            // `paddingLeft` in, and the menu buttons really end
            // `paddingRight` before the right edge, with a `gap` before
            // each flex sibling (title, then one per .tb-col-menu). A
            // budget that assumes padding/gaps are zero under-counts how
            // far the title/menu reach and can still let them touch the
            // centred badge near the boundary. Read the real padding and
            // gap instead of assuming they are zero.
            const headStyle = getComputedStyle(head);
            const paddingLeft = parseFloat(headStyle.paddingLeft) || 0;
            const paddingRight = parseFloat(headStyle.paddingRight) || 0;
            const gap = parseFloat(headStyle.columnGap || headStyle.gap) || 0;

            const gutter = 8;
            const halfSupWidth = supervisors.offsetWidth / 2;
            const badgeLeft = head.clientWidth / 2 - halfSupWidth;
            const badgeRight = head.clientWidth / 2 + halfSupWidth;
            const titleEnd = paddingLeft + titleWidth;
            const menuStart = head.clientWidth - paddingRight - menuWidth - menus.length * gap;
            const compact = (titleEnd + gutter <= badgeLeft) && (badgeRight + gutter <= menuStart);
            head.classList.toggle('tb-sup-centred', compact);
            head.classList.toggle('tb-sup-inline', !compact);
        });
    }

    /** @private */
    initSupervisorReflow() {
        if (this._supervisorResizeHandler) {
            window.removeEventListener('resize', this._supervisorResizeHandler);
        }

        this._supervisorResizeHandler = () => this.positionSupervisors();
        window.addEventListener('resize', this._supervisorResizeHandler);
    }

    /**
     * Restore the month strip exactly where the user left it. On the first
     * render only, reveal the active month without smooth animation. Later
     * selections never force-centre or shift the timeline.
     *
     * @private
     */
    restoreMonthScroll() {
        const strip = this.element.querySelector('.tb-month-strip');

        if (!strip) {
            return;
        }

        if (!this._monthScrollInitialized) {
            const active = strip.querySelector('.tb-month.is-active');

            if (active) {
                strip.scrollLeft = Math.max(
                    0,
                    active.offsetLeft - (strip.clientWidth - active.offsetWidth) / 2
                );
            }

            this._monthScrollInitialized = true;
            this._monthScrollLeft = strip.scrollLeft;
        } else {
            strip.scrollLeft = this._monthScrollLeft || 0;
        }

        strip.addEventListener('scroll', () => {
            this._monthScrollLeft = strip.scrollLeft;
        }, {passive: true});
    }

    onRemove() {
        this._timelineRemoved = true;
        this._timelineGeneration = (this._timelineGeneration || 0) + 1;
        Espo.Ui.notify(false);
        this.destroyFloatingScrollbar();

        if (this._supervisorResizeHandler) {
            window.removeEventListener('resize', this._supervisorResizeHandler);
            this._supervisorResizeHandler = null;
        }
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

        const container = this.element.querySelector(
            '.tb-team-columns, .team-board');

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

                menu.style.position = 'fixed';
                menu.style.maxHeight = '';
                menu.style.overflowY = '';

                this.placeFixedMenu(
                    menu,
                    toggle.getBoundingClientRect(),
                    document.documentElement.clientWidth,
                    window.innerHeight
                );
            }, 0);
        });
    }

    /**
     * U22 — the menu keeps its content width, is right-aligned to its toggle
     * and stays inside the screen; a menu taller than the space scrolls.
     *
     * @private
     */
    placeFixedMenu(menu, rect, viewportWidth, viewportHeight) {
        const gap = 2;
        const margin = 8;
        const width = Math.min(menu.offsetWidth, viewportWidth - 2 * margin);
        const height = menu.scrollHeight || menu.offsetHeight;

        const left = Math.max(margin, Math.min(rect.right - width, viewportWidth - margin - width));

        menu.style.maxWidth = `${viewportWidth - 2 * margin}px`;
        menu.style.right = 'auto';
        menu.style.left = `${left}px`;

        const below = viewportHeight - margin - (rect.bottom + gap);
        const above = rect.top - gap - margin;

        if (height <= below) {
            menu.style.top = `${rect.bottom + gap}px`;

            return;
        }

        if (height <= above) {
            menu.style.top = `${rect.top - gap - height}px`;

            return;
        }

        // Taller than either side: use the whole screen height and scroll.
        const available = viewportHeight - 2 * margin;

        menu.style.top = `${margin}px`;
        menu.style.maxHeight = `${available}px`;
        menu.style.overflowY = 'auto';
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
            e.dataTransfer.effectAllowed = 'move';

            if (e.dataTransfer.setDragImage) {
                const preview = this.createDragPreview(card);

                this._dragPreview = preview;
                e.dataTransfer.setDragImage(preview, 12, 12);
            }

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
                this.updateRemoveZoneVisibility();
            }, 0);
        });

        container.addEventListener('dragend', () => {
            // Column reorder has its own drag state and dragend handler.
            // Do not clear it from the member handler.
            if (this._drag) {
                this.resetDragState();
            }
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

            this.updateRemoveZoneVisibility();

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
                e.dataTransfer.dropEffect = 'move';

                this.highlightElement(target);

                return;
            }

            // Empty area outside the columns — remove intent.
            e.dataTransfer.dropEffect = 'move';

            this.highlightRemoveZone();
        });

        root.addEventListener('drop', e => {
            const drag = this._drag;

            if (!drag) {
                return;
            }

            const cardInsert = this._cardInsert;

            this.resetDragState();

            e.preventDefault();

            if (cardInsert) {
                this.reorderCard(drag, cardInsert);

                return;
            }

            const target = this.dropTargetFromElement(e.target);

            if (target) {
                this.dropTo(drag, target);

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
    reorderTargetFromElement(element, drag = this._drag) {
        if (!drag || !element || !(element instanceof Element)) {
            return null;
        }

        const card = element.closest('.tb-card');

        if (
            !card ||
            card.dataset.userId === drag.userId ||
            card.dataset.teamId !== drag.fromTeamId ||
            card.dataset.position !== drag.position
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
        // The visible cards live in the container of the target card itself
        // (`.tb-role-members` for Members/photo slots, `.tb-group-body` for
        // groups) — `.tb-group` blocks may be hidden duplicates.
        const container = insert.card.parentElement;

        if (!container) {
            return;
        }

        const cardOf = (parent, userId) => [...parent.children].find(child =>
            child.dataset && child.dataset.userId === userId);

        const dragged = cardOf(container, drag.userId);

        if (!dragged) {
            return;
        }

        container.insertBefore(
            dragged,
            insert.before ? insert.card : insert.card.nextSibling
        );

        const userIds = [...container.children]
            .filter(child => child.dataset && child.dataset.userId)
            .map(child => child.dataset.userId);

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

        return element.closest(
            '.tb-group, .tb-sups, .tb-role-slot, .tb-reserve, .tb-remove-zone');
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

            root.classList.remove('tb-drag-active', 'tb-reserve-hidden');
            this.clearDropHighlight();
            this.clearCardIndicator();
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
                this.updateRemoveZoneVisibility();
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

            this.updateRemoveZoneVisibility();

            this.clearDropHighlight();
            this.clearCardIndicator();

            // Reordering within the same group — as with mouse drag.
            const overCard = this.reorderTargetFromElement(
                document.elementFromPoint(touch.clientX, touch.clientY), touchDrag);

            if (overCard) {
                const rect = overCard.getBoundingClientRect();
                const before = touch.clientY < rect.top + rect.height / 2;

                overCard.classList.add(
                    before ? 'tb-card-insert-before' : 'tb-card-insert-after');

                return;
            }

            const target = this.targetFromPoint(touch.clientX, touch.clientY);

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
            const drag = touchDrag;
            const overCard = this.reorderTargetFromElement(
                document.elementFromPoint(touch.clientX, touch.clientY), drag);
            const target = this.targetFromPoint(touch.clientX, touch.clientY);

            cleanup();

            if (overCard) {
                const rect = overCard.getBoundingClientRect();

                this.reorderCard(drag, {
                    card: overCard,
                    before: touch.clientY < rect.top + rect.height / 2,
                });

                return;
            }

            if (target) {
                this.dropTo(drag, target);
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
        const container = this.element.querySelector('.tb-team-columns') ||
            this.element.querySelector('.team-board');

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

            if (e.dataTransfer.setDragImage) {
                const rect = col.getBoundingClientRect();
                const preview = this.createColumnDragPreview(col);

                e.dataTransfer.setDragImage(
                    preview,
                    e.clientX - rect.left,
                    e.clientY - rect.top
                );

                this._colDragPreview = preview;
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
            const draggedRect = dragged.getBoundingClientRect();
            const style = getComputedStyle(container);
            const isGrid = style.display === 'grid';
            const sameGridRow = isGrid &&
                Math.abs(draggedRect.top - rect.top) < Math.min(draggedRect.height, rect.height) / 2;
            const horizontal = isGrid ? sameGridRow : style.flexDirection !== 'column';
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

            this.removeDragPreviews();
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
        this.removeDragPreviews();
        this._drag = null;
        this._colDrag = null;
        this._colInsert = null;
        this._cardInsert = null;

        if (!this.element) {
            return;
        }

        this.element.classList.remove('tb-drag-active', 'tb-reserve-hidden');

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
        const container = this.element.querySelector('.tb-team-columns') ||
            this.element.querySelector('.team-board');

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
        const ghost = this.createDragPreview(card);

        ghost.style.width = `${Math.max(card.offsetWidth, 160)}px`;
        ghost.style.left = `${x + 8}px`;
        ghost.style.top = `${y + 8}px`;

        return ghost;
    }

    /**
     * Build a detached drag image containing only one member's identity.
     *
     * @private
     */
    createDragPreview(card) {
        const preview = document.createElement('div');
        const avatar = card.querySelector('.tb-avatar');
        const nameSource = card.querySelector('.tb-card-name a') ||
            card.querySelector('.tb-card-name');
        let name = nameSource;

        // U08: photo-avatar cards carry the full name on .tb-info only.
        const info = name ? null : card.querySelector('.tb-info[data-user-name]');

        if (info && info.dataset.userName) {
            name = document.createElement('span');
            name.textContent = info.dataset.userName;
        }

        // If no .tb-card-name found, extract the first line of the title attribute (member name only)
        if (!name) {
            const titleText = card.getAttribute('title');
            if (titleText) {
                const nameText = titleText.split('\n')[0];
                if (nameText) {
                    name = document.createElement('span');
                    name.textContent = nameText;
                }
            }
        }

        preview.className = 'tb-ghost tb-drag-preview';
        preview.setAttribute('aria-hidden', 'true');

        if (avatar) {
            preview.appendChild(avatar.cloneNode(true));
        }

        const nameElement = document.createElement('span');
        nameElement.className = 'tb-drag-name';
        nameElement.textContent = name ? name.textContent.trim() : '';
        preview.appendChild(nameElement);
        document.body.appendChild(preview);

        return preview;
    }

    /**
     * Clone one column only for native drag feedback; never use the board
     * container as a browser drag image.
     *
     * @private
     */
    createColumnDragPreview(col) {
        const preview = col.cloneNode(true);
        const handle = preview.querySelector('.tb-resize-handle');

        preview.classList.add('tb-ghost', 'tb-drag-preview-column');
        preview.setAttribute('aria-hidden', 'true');
        preview.style.width = `${col.offsetWidth}px`;
        preview.style.height = `${col.offsetHeight}px`;
        preview.style.left = '-10000px';
        preview.style.top = '0';

        if (handle) {
            handle.remove();
        }

        document.body.appendChild(preview);

        return preview;
    }

    removeDragPreviews() {
        for (const key of ['_dragPreview', '_colDragPreview']) {
            const preview = this[key];

            if (preview) {
                preview.remove();
                this[key] = null;
            }
        }
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
     * The fixed "drop here to remove" hint is only useful while the Reserve
     * panel — which serves the same removal purpose as an ordinary drop
     * target — is scrolled out of view (U11: Reserve sits after a long
     * board). Toggled on drag start and kept current while the drag moves,
     * since scrolling the board during a drag can bring Reserve on or off
     * screen without a new drag starting.
     *
     * @private
     */
    updateRemoveZoneVisibility() {
        if (!this.element) {
            return;
        }

        const reserve = this.element.querySelector('.tb-reserve');
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight;

        let reserveVisible = false;

        if (reserve) {
            const rect = reserve.getBoundingClientRect();
            reserveVisible = rect.bottom > 0 && rect.top < viewportHeight;
        }

        this.element.classList.toggle('tb-reserve-hidden', !reserveVisible);
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
    dropTo(drag, target) {
        if (
            target.classList.contains('tb-remove-zone') ||
            target.classList.contains('tb-reserve')
        ) {
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

        this.move(
            drag.userId,
            toTeamId,
            drag.fromTeamId,
            position
        );
    }

    /**
     * A drop opens a dated plan; it never writes `team_user` directly.
     *
     * @private
     */
    move(userId, teamId, fromTeamId, position) {
        const team = (this.boardData.teams || []).find(item => item.id === teamId);

        this.openAssignmentDialog({
            memberId: userId,
            teamId: teamId,
            boardMemberId: this.shadowMemberId(userId),
            boardSquadId: team ? team.boardSquadId || team.id : teamId,
            position: position,
            positionList: team ? team.positionList : this.boardData.positionList,
            dateFrom: this.asOfDate,
            status: 'draft',
        });
    }

    /** @private */
    shadowMemberId(memberId) {
        const entry = this.findMember(memberId);

        if (entry) {
            return entry.boardMemberId || entry.id;
        }

        for (const team of this.boardData.teams || []) {
            const member = (team.members || []).find(item => item.id === memberId);

            if (member) {
                return member.boardMemberId || member.id;
            }
        }

        const reserve = (this.boardData.reserve || []).find(item => item.id === memberId);

        return reserve ? reserve.boardMemberId || reserve.id : memberId;
    }

    /**
     * A drop plans a period at the date currently shown. The API remains the
     * authority for D13: it rejects a past or live change a caller may not
     * make, even if a modified client submits it directly.
     *
     * @private
     * @param {Object} options
     */
    openAssignmentDialog(options) {
        this.resetDragState();

        this.createView(
            'assignmentDialog',
            'team-board:views/team-board/modals/assignment',
            Object.assign({}, options, {
                viewDate: this.asOfDate,
                canDelete: options.status === 'draft',
            })
        ).then(view => {
            this.listenToOnce(view, 'done', data => {
                this.boardData = data;
                this.asOfDate = data.date;
                this.visibleMonth = data.date.slice(0, 7);

                // The modal emits `done` immediately before it closes. Rendering
                // the parent while that nested view is being removed replaces
                // the board markup but can interrupt `afterRender`, leaving the
                // new container without dragstart/dragend handlers. Wait for the
                // child lifecycle to finish, then rebuild the board.
                this.listenToOnce(view, 'close', () => {
                    setTimeout(() => this.reRender(), 0);
                });
            });

            view.render();
        });
    }

    /**
     * Close the displayed period at the as-of date. The server validates D13.
     *
     * @private
     */
    removeMember(userId, teamId) {
        const entry = this.findMemberEntry(userId, teamId);

        if (!entry) {
            return;
        }

        Espo.Ui.notifyWait();

        // A CRM-linked member currently shown only through the live CRM
        // team membership (no shadow assignment row exists yet for the
        // as-of date) has no assignment id to close with a PUT. Unrelate
        // the CRM team_user membership directly instead — still only
        // changes the assignment, never deletes the person (U21).
        const request = entry.assignmentId ?
            Espo.Ajax.putRequest('TeamBoard/assignment/' + entry.assignmentId, {
                dateTo: this.asOfDate,
                viewDate: this.asOfDate,
            }) :
            Espo.Ajax.postRequest('TeamBoard/removeMember', {
                userId: userId,
                teamId: teamId,
                viewDate: this.asOfDate,
            });

        request
            .then(data => {
                this.boardData = data;
                this.asOfDate = data.date;
                this.visibleMonth = data.date.slice(0, 7);

                this.resetDragState();
                this.reRender();
            })
            .catch(() => Espo.Ui.notify(false));
    }

    /**
     * @private
     * @return {?Object}
     */
    findMemberEntry(userId, teamId) {
        const team = (this.boardData.teams || []).find(item => item.id === teamId);

        if (!team) {
            return null;
        }

        return team.members.find(member => member.id === userId) || null;
    }
}

export default TeamBoardView;

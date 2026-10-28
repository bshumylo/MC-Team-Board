import ModalView from 'views/modal';
import Model from 'model';
import EditForModalRecordView from 'views/record/edit-for-modal';

const COLOUR_LIST = [
    'slate', 'blue', 'sky', 'teal', 'green', 'lime', 'amber',
    'orange', 'rose', 'pink', 'violet', 'purple', 'indigo',
];

const DEFAULT_POSITION_LIST = ['Supervisor', 'Leader', 'Vice Leader', 'Member'];

const MAX_NAME_LENGTH = 150;
const MAX_POSITION_LENGTH = 100;
const MAX_POSITION_COUNT = 20;

// The board endpoint answers a squad save with a refreshed board payload, not
// with squad attributes. Keep the native model save lifecycle without letting
// that payload land in the form model.
class TeamFormModel extends Model {
    prepareAttributes() {
        return {};
    }
}

/**
 * «Manage teams» (U23). Two states of one dialog:
 * - the list of teams, with «Close» and «Create Board Team»;
 * - the form of one board-only team, with «Save», «Cancel» and, for an
 *   existing team, «Archive team» in the native «⋯» menu.
 *
 * A system team has no form: its name links to the CRM Team record and its
 * colour dots in the list save at once.
 */
class TeamManagerModalView extends ModalView {

    template = 'team-board:team-board/modals/team-manager'

    className = 'dialog dialog-record'

    // Bootstrap's own Esc close bypasses the view, so Esc goes through the
    // native shortcut manager instead (as views/modals/edit does) and meets
    // the same unsaved-changes guard as Cancel, X and a click outside.
    escapeDisabled = true

    shortcutKeys = {
        Escape: function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.closeWithConfirmation();
        },
    }

    events = {
        'click [data-action="editTeam"]': function (e) {
            const team = this.team(e.currentTarget.dataset.id);

            if (team) {
                this.openTeam(team);
            }
        },
        // №14: the link opens the CRM Team record. A plain click follows it in
        // the same tab and closes the dialog; a Ctrl/Cmd/Shift click (and a
        // middle click, which is no click event) keeps the native new tab.
        'click [data-action="openSystemTeam"]': function (e) {
            if (e.button > 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
                return;
            }

            this.close();
        },
        'click [data-action="showArchive"]': function () {
            Espo.Ajax.getRequest('TeamBoard/archived-squads?viewDate=' + encodeURIComponent(this.options.viewDate)).then(items => {
                this.options.archivedTeams = items;
                this.reRender();
            });
        },
        'click [data-action="restoreTeam"]': function (e) {
            this.request(
                'post',
                `TeamBoard/squad/${e.currentTarget.dataset.id}/restore`,
                {viewDate: this.options.viewDate},
                () => this.close()
            );
        },
        'click [data-action="setTeamColour"]': function (e) {
            this.setTeamColour(e.currentTarget.dataset.id, e.currentTarget.dataset.colourKey);
        },
    }

    setup() {
        this.teamId = '';
        this.formVisible = false;
        // True while the open form came from a team name on the board: leaving
        // it closes the dialog instead of showing the list.
        this.returnToBoard = false;
        this.isSaving = false;
        this.isClosed = false;
        this.pendingBoardData = null;
        this.formModel = null;
        this.teamRecordView = null;
        this.canManage = this.options.canManage !== false;
        this.initialFormState = null;
        this.updateState();

        // Any close path of views/modal ends in this event, so the board learns
        // about applied changes exactly once.
        this.on('close', () => this.flushBoardUpdate());
    }

    // ui/dialog closes the dialog straight from the header X without asking
    // the view. A capture listener on the modal container runs first and
    // sends X through the unsaved-changes guard. The container is rebuilt on
    // every render, so the listener is added again each time.
    afterRender() {
        if (this.containerElement) {
            this.containerElement.addEventListener('click', e => this.onHeaderCloseClick(e), true);
        }
    }

    onHeaderCloseClick(e) {
        const target = e.target;

        if (!target || typeof target.closest !== 'function' ||
            !target.closest('.modal-header [data-dismiss="modal"]')) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        this.closeWithConfirmation();
    }

    data() {
        const team = this.teamId ? this.team(this.teamId) : null;
        const canReadTeam = this.getAcl().check('Team', 'read');

        return {
            teams: (this.options.teams || []).map(item => Object.assign({}, item, {
                colourOptions: COLOUR_LIST.map(colourKey => ({
                    value: colourKey,
                    selected: (item.colourKey || 'slate') === colourKey,
                })),
                // C03: the same Team read check as the board card title.
                teamLink: item.isEspoTeam && canReadTeam && item.teamId ?
                    `#Team/view/${item.teamId}` : null,
                typeLabel: item.isEspoTeam ?
                    this.translate('System', 'labels', 'TeamBoard') :
                    this.translate('Board only', 'labels', 'TeamBoard'),
            })),
            formVisible: this.formVisible,
            formHint: team ?
                this.translate('This team exists only on the board.', 'labels', 'TeamBoard') :
                this.translate('This team will exist only on the board.', 'labels', 'TeamBoard'),
            archivedTeams: this.options.archivedTeams || [],
            teamNameLabel: this.translate('Team name', 'labels', 'TeamBoard'),
            colourLabel: this.translate('Colour', 'labels', 'TeamBoard'),
            teamTypeLabel: this.translate('Team type', 'labels', 'TeamBoard'),
            archiveListLabel: this.translate('Archive', 'labels', 'TeamBoard'),
            restoreLabel: this.translate('Restore team', 'labels', 'TeamBoard'),
            historyLabel: this.translate('Historical assignments remain', 'labels', 'TeamBoard'),
        };
    }

    team(id) {
        return (this.options.teams || []).find(team => team.boardSquadId === id) || null;
    }

    // The dialog title and the footer of the current state. Every state change
    // re-renders the dialog, and views/modal builds the header and the footer
    // from headerText, buttonList and dropdownItemList on render.
    updateState() {
        if (!this.formVisible) {
            this.headerText = this.translate('Manage teams', 'labels', 'TeamBoard');
            this.buttonList = [{name: 'close', label: 'Close', onClick: () => this.close()}];
            this.dropdownItemList = [];

            if (this.canManage) {
                // №15: the look of «+ Create Board User» on «Manage users».
                this.buttonList.push({
                    name: 'newTeam',
                    text: this.translate('Create Board Team', 'labels', 'TeamBoard'),
                    iconHtml: '<span class="fas fa-plus fa-sm"></span>',
                    style: 'default',
                    position: 'right',
                    onClick: () => this.openNewTeam(),
                });
            }

            return;
        }

        const team = this.teamId ? this.team(this.teamId) : null;

        this.headerText = team ? team.name : this.translate('New team', 'labels', 'TeamBoard');
        this.buttonList = [];
        this.dropdownItemList = [];

        if (this.canManage) {
            this.buttonList.push({name: 'save', label: 'Save', style: 'primary', onClick: () => this.actionSave()});
        }

        this.buttonList.push({
            name: 'cancel',
            label: 'Cancel',
            onClick: () => this.confirmDiscard(() => this.leaveForm()),
        });

        if (this.canManage && team) {
            this.dropdownItemList.push({
                name: 'archiveTeam',
                text: this.translate('Archive team', 'labels', 'TeamBoard'),
                onClick: () => this.archiveTeam(this.teamId),
            });
        }
    }

    openNewTeam() {
        this.teamId = '';
        this.formVisible = true;
        this.returnToBoard = false;

        return this.openForm({});
    }

    /**
     * @param {Object} team
     * @param {{returnToBoard?: boolean}} [options] returnToBoard: the form is
     *   opened from the team name on the board, so leaving it closes the dialog.
     */
    openTeam(team, {returnToBoard = false} = {}) {
        // C06 №14: a system team keeps its CRM name and positions; it has no form.
        if (!team || team.isEspoTeam) {
            return Promise.resolve();
        }

        this.teamId = team.boardSquadId;
        this.formVisible = true;
        this.returnToBoard = returnToBoard;

        return this.openForm(team);
    }

    // The record view has to be ready before the modal renders its container,
    // otherwise the form area stays empty until something else re-renders it.
    openForm(team) {
        const assigned = this.setupTeamFields(team);

        this.rememberFormState();
        this.updateState();

        return Promise.resolve(assigned).then(() => this.reRender());
    }

    // Save, Cancel and Archive leave the form the same way: back to the list,
    // or back to the board when the form was opened from it.
    leaveForm() {
        if (this.returnToBoard) {
            this.close();

            return;
        }

        this.showList();
    }

    showList() {
        if (this.formModel) {
            this.stopListening(this.formModel);
        }

        this.teamId = '';
        this.formVisible = false;
        this.returnToBoard = false;
        this.formModel = null;
        this.teamRecordView = null;
        this.initialFormState = null;
        this.clearView('teamRecord');
        this.updateState();
        this.reRender();
    }

    setupTeamFields(team) {
        const readOnly = !this.canManage;

        if (this.formModel) {
            this.stopListening(this.formModel);
        }

        this.formModel = new TeamFormModel({
            id: team.boardSquadId || null,
            name: team.name || '',
            positionList: [...(team.positionList || DEFAULT_POSITION_LIST)],
            colourKey: team.colourKey || 'slate',
        }, {entityType: 'TeamBoardSquad', urlRoot: 'TeamBoard/squad'});

        // The dialog edits the whole team form with the native edit-mode field
        // views and one Save. EspoCRM's inline per-field control is not rendered
        // for this dialog, so it cannot be the only way to change a field.
        const RecordView = EditForModalRecordView;

        this.teamRecordView = new RecordView({
            model: this.formModel,
            buttonsDisabled: true,
            sideDisabled: true,
            bottomDisabled: true,
            readOnly: readOnly,
            // Cells name the field view instead of holding a constructed one, so
            // the record view owns the field lifecycle and its native inline
            // edit, validation and read-only handling apply.
            // C09 №8: name and colour in one row (half width each), positions
            // below at full width.
            detailLayout: [{rows: [
                [
                    {
                        name: 'name',
                        view: 'views/fields/varchar',
                        labelText: this.translate('Team name', 'labels', 'TeamBoard'),
                        readOnly: readOnly,
                        params: {required: true, maxLength: MAX_NAME_LENGTH},
                    },
                    {
                        name: 'colourKey',
                        // C05 №7: the same colour dots as the list, no names.
                        view: 'team-board:views/team-board/fields/colour-key',
                        labelText: this.translate('Colour', 'labels', 'TeamBoard'),
                        readOnly: readOnly,
                        params: {
                            required: true,
                            options: [...COLOUR_LIST],
                        },
                    },
                ],
                [{
                    name: 'positionList',
                    view: 'views/fields/array',
                    labelText: this.translate('Positions', 'labels', 'TeamBoard'),
                    readOnly: readOnly,
                    params: {
                        required: true,
                        allowCustomOptions: true,
                        noEmptyString: true,
                        maxItemLength: MAX_POSITION_LENGTH,
                        maxCount: MAX_POSITION_COUNT,
                    },
                }],
            ]}],
        });

        this.clearView('teamRecord');

        const assigned = this.assignView('teamRecord', this.teamRecordView);

        this.listenTo(this.formModel, 'sync', (model, response) => this.afterFieldSync(response));

        return assigned;
    }

    // A change applied in the list (a colour dot) or through a native field
    // answers with the refreshed board. Keep the modal open, bring its own list
    // and its open form to the state the server confirmed, and hand the
    // payload to the board when the dialog closes.
    afterFieldSync(response) {
        if (!response || typeof response !== 'object') {
            return;
        }

        if (Array.isArray(response.teams)) {
            this.options.teams = response.teams;
            this.syncFormModelFromTeam(this.team(this.teamId));
        }

        if (this.isClosed) {
            // The modal was closed while this save was still running. The board
            // still has to learn about the confirmed change, exactly once.
            this.trigger('done', response);

            return;
        }

        this.pendingBoardData = response;

        if (!this.teamRecordView || !this.teamRecordView.inlineEditModeIsOn) {
            this.reRender();
        }
    }

    // The row colour picker and every applied field answer with the confirmed
    // team, so the open native form must show the same values. A field the user
    // is editing right now keeps its unapplied input.
    syncFormModelFromTeam(team) {
        if (!team || !this.formModel) {
            return;
        }

        const values = {};

        ['name', 'positionList', 'colourKey'].forEach(name => {
            if (!(name in team) || this.isFieldBeingEdited(name)) {
                return;
            }

            values[name] = name === 'positionList' ? [...(team.positionList || [])] : team[name];
        });

        if (Object.keys(values).length) {
            this.formModel.setMultiple(values);
        }
    }

    isFieldBeingEdited(name) {
        const record = this.teamRecordView;

        if (!record || typeof record.getFieldView !== 'function') {
            return false;
        }

        const field = record.getFieldView(name);

        return !!field && typeof field.isEditMode === 'function' && field.isEditMode();
    }

    // Called when the dialog closes. A response that arrives later is sent by
    // afterFieldSync or request() instead, so a close during a pending save
    // loses nothing.
    flushBoardUpdate() {
        this.isClosed = true;

        if (!this.pendingBoardData) {
            return;
        }

        const data = this.pendingBoardData;
        this.pendingBoardData = null;
        this.trigger('done', data);
    }

    formState() {
        const model = this.formModel;

        return {
            teamId: this.teamId,
            name: model ? (model.get('name') || '') : '',
            colourKey: model ? (model.get('colourKey') || '') : '',
            positionList: model ? [...(model.get('positionList') || [])] : [],
        };
    }

    rememberFormState() {
        this.initialFormState = JSON.stringify(this.formState());
    }

    syncFormState() {
        if (!this.teamRecordView || typeof this.teamRecordView.fetch !== 'function') {
            return;
        }

        const values = this.teamRecordView.fetch();

        if (values) {
            this.formModel.setMultiple(values, {silent: true});
        }
    }

    // Both the new and the existing team form hold unsaved data until Save.
    hasUnsavedForm() {
        if (!this.formVisible || this.initialFormState === null) {
            return false;
        }

        // Text typed into a native field lives in the DOM until the record view
        // is fetched, so the current input is read before the comparison.
        this.syncFormState();

        return JSON.stringify(this.formState()) !== this.initialFormState;
    }

    confirmDiscard(callback) {
        if (!this.hasUnsavedForm()) {
            callback();
            return;
        }

        this.confirm(this.translate('Discard unsaved changes?', 'messages', 'TeamBoard'))
            .then(() => callback());
    }

    closeWithConfirmation() {
        this.confirmDiscard(() => this.close());
    }

    // Backdrop is 'static' (views/modal default), so a click outside never
    // auto-closes; this hook still fires on every such click (see
    // ui/dialog.js callOnBackdropClick). U23: it closes at once when nothing
    // is unsaved and asks first when the form holds unsaved input.
    onBackdropClick() {
        this.closeWithConfirmation();
    }

    actionSave() {
        if (!this.formVisible || !this.canManage || this.isSaving) {
            return;
        }

        const values = this.teamRecordView.processFetch();

        if (!values) {
            return;
        }

        this.request(
            this.teamId ? 'put' : 'post',
            this.teamId ? `TeamBoard/squad/${this.teamId}` : 'TeamBoard/squad',
            {
                name: (values.name || '').trim(),
                positionList: [...(values.positionList || [])],
                colourKey: values.colourKey || 'slate',
            }
        );
    }

    archiveTeam(id) {
        this.confirm(this.translate('Historical assignments remain', 'labels', 'TeamBoard'))
            .then(() => this.request(
                'post',
                `TeamBoard/squad/${id}/archive`,
                {viewDate: this.options.viewDate},
                () => {
                    // A loaded archive list no longer matches; «Archive» loads it again.
                    this.options.archivedTeams = [];
                    this.leaveForm();
                }
            ));
    }

    setTeamColour(id, colourKey) {
        if (!this.team(id) || !colourKey || !this.canManage || this.isSaving) {
            return;
        }

        this.isSaving = true;
        Espo.Ui.notifyWait();
        Espo.Ajax.putRequest(`TeamBoard/squad/${id}`, {colourKey})
            .then(data => {
                this.isSaving = false;
                Espo.Ui.notify(false);
                this.afterFieldSync(data);
            })
            .catch(() => {
                this.isSaving = false;
                Espo.Ui.notify(false);
            });
    }

    /**
     * Save, archive or restore a team. The answer is the refreshed board: the
     * dialog keeps it for the board until it closes, then `onDone` decides
     * where the user goes (by default the Save rule, leaveForm()).
     */
    request(method, url, payload, onDone = () => this.leaveForm()) {
        if (this.isSaving) {
            return;
        }

        this.isSaving = true;
        Espo.Ui.notifyWait();
        Espo.Ajax[`${method}Request`](url, payload)
            .then(data => {
                this.isSaving = false;
                Espo.Ui.notify(false);

                if (this.isClosed) {
                    // Closed while the request ran: the board still learns once.
                    this.trigger('done', data);

                    return;
                }

                if (data && Array.isArray(data.teams)) {
                    this.options.teams = data.teams;
                }

                this.pendingBoardData = data;
                onDone();
            })
            .catch(() => {
                this.isSaving = false;
                Espo.Ui.notify(false);
            });
    }
}

export default TeamManagerModalView;

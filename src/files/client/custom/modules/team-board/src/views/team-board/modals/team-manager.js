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

class TeamManagerModalView extends ModalView {

    template = 'team-board:team-board/modals/team-manager'

    className = 'dialog dialog-record'

    events = {
        'click [data-action="newTeam"]': function () {
            this.confirmDiscard(() => this.openNewTeam());
        },
        'click [data-action="editTeam"]': function (e) {
            const team = this.team(e.currentTarget.dataset.id);

            if (!team) {
                return;
            }

            this.confirmDiscard(() => this.openTeam(team));
        },
        // "archiveTeam" no longer has body markup — it is a footer button
        // (see updateFormActionButtons(), U23).
        'click [data-action="showArchive"]': function () {
            Espo.Ajax.getRequest('TeamBoard/archived-squads?viewDate=' + encodeURIComponent(this.options.viewDate)).then(items => {
                this.options.archivedTeams = items;
                this.reRender();
            });
        },
        'click [data-action="restoreTeam"]': function (e) {
            this.request('post', `TeamBoard/squad/${e.currentTarget.dataset.id}/restore`, {viewDate: this.options.viewDate});
        },
        'click [data-action="setTeamColour"]': function (e) {
            this.setTeamColour(e.currentTarget.dataset.id, e.currentTarget.dataset.colourKey);
        },
    }

    setup() {
        this.teamId = '';
        this.formVisible = false;
        this.isSaving = false;
        this.isClosed = false;
        this.pendingBoardData = null;
        this.formModel = null;
        this.teamRecordView = null;
        this.canManage = this.options.canManage !== false;
        this.headerText = this.translate('Manage teams', 'labels', 'TeamBoard');
        // New Team sits on the right of the Save/Close row from the start
        // (U23), not as a body button under it.
        this.buttonList = [
            {name: 'cancel', label: 'Close', onClick: () => this.closeWithConfirmation()},
        ];

        if (this.canManage) {
            this.buttonList.push({
                name: 'newTeam',
                label: this.translate('New team', 'labels', 'TeamBoard'),
                position: 'right',
                onClick: () => this.confirmDiscard(() => this.openNewTeam()),
            });
        }
        this.initialFormState = null;

        // Any close path of views/modal ends in this event, so the board learns
        // about applied field changes exactly once.
        this.on('close', () => this.flushBoardUpdate());
    }

    data() {
        const team = this.teamId ? this.team(this.teamId) : null;

        return {
            teams: (this.options.teams || []).map(item => Object.assign({}, item, {
                colourOptions: COLOUR_LIST.map(colourKey => ({
                    value: colourKey,
                    selected: (item.colourKey || 'slate') === colourKey,
                })),
            })),
            teamId: this.teamId,
            teamName: team ? team.name : '',
            formVisible: this.formVisible,
            editingLinked: !!(team && team.isEspoTeam),
            archivedTeams: this.options.archivedTeams || [],
            canManage: this.canManage,
            newTeamLabel: this.translate('New team', 'labels', 'TeamBoard'),
            manageTeamsLabel: this.translate('Manage teams', 'labels', 'TeamBoard'),
            teamNameLabel: this.translate('Team name', 'labels', 'TeamBoard'),
            colourLabel: this.translate('Colour', 'labels', 'TeamBoard'),
            archiveLabel: this.translate('Archive team', 'labels', 'TeamBoard'),
            archiveListLabel: this.translate('Archive', 'labels', 'TeamBoard'),
            restoreLabel: this.translate('Restore team', 'labels', 'TeamBoard'),
            historyLabel: this.translate('Historical assignments remain', 'labels', 'TeamBoard'),
            boardOnlyDescription: this.translate('Board-only team; no EspoCRM Team is created.', 'labels', 'TeamBoard'),
        };
    }

    team(id) {
        return (this.options.teams || []).find(team => team.boardSquadId === id) || null;
    }

    openNewTeam() {
        this.teamId = '';
        this.formVisible = true;

        return this.openForm({});
    }

    openTeam(team) {
        this.teamId = team.boardSquadId;
        this.formVisible = true;

        return this.openForm(team);
    }

    // The record view has to be ready before the modal renders its container,
    // otherwise the form area stays empty until something else re-renders it.
    openForm(team) {
        const assigned = this.setupTeamFields(team);

        this.rememberFormState();
        this.updateSaveButton();

        return Promise.resolve(assigned).then(() => this.reRender());
    }

    setupTeamFields(team) {
        const existing = !!team.boardSquadId;
        const linked = !!team.isEspoTeam;
        const readOnly = !this.canManage;
        const nameReadOnly = readOnly || linked;

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
            detailLayout: [{rows: [
                [{
                    name: 'name',
                    view: 'views/fields/varchar',
                    labelText: this.translate('Team name', 'labels', 'TeamBoard'),
                    readOnly: nameReadOnly,
                    params: {required: true, maxLength: MAX_NAME_LENGTH},
                }],
                [{
                    name: 'positionList',
                    view: 'views/fields/array',
                    labelText: this.translate('Positions', 'labels', 'TeamBoard'),
                    readOnly: nameReadOnly,
                    params: {
                        required: true,
                        allowCustomOptions: true,
                        noEmptyString: true,
                        maxItemLength: MAX_POSITION_LENGTH,
                        maxCount: MAX_POSITION_COUNT,
                    },
                }],
                [{
                    name: 'colourKey',
                    view: 'views/fields/enum',
                    labelText: this.translate('Colour', 'labels', 'TeamBoard'),
                    readOnly: readOnly,
                    params: {
                        required: true,
                        options: [...COLOUR_LIST],
                        translatedOptions: this.colourLabels(),
                    },
                }],
            ]}],
        });

        this.clearView('teamRecord');

        const assigned = this.assignView('teamRecord', this.teamRecordView);

        this.listenTo(this.formModel, 'sync', (model, response) => this.afterFieldSync(response));

        return assigned;
    }

    colourLabels() {
        return Object.fromEntries(
            COLOUR_LIST.map(value => [value, value.charAt(0).toUpperCase() + value.slice(1)])
        );
    }

    updateSaveButton() {
        const hasSave = this.buttonList.some(item => item.name === 'save');
        const needsSave = this.formVisible && this.canManage;

        if (needsSave && !hasSave) {
            this.addButton({name: 'save', label: 'Save', style: 'primary', onClick: () => this.actionSave()}, 'cancel');
        } else if (!needsSave && hasSave) {
            this.removeButton('save', true);
        }

        this.updateFormActionButtons();
    }

    // New Team and Archive Team sit in the same footer row as Save/Close,
    // pinned to its right side (U23): New Team whenever teams can be managed,
    // Archive Team while a board-only team is open.
    updateFormActionButtons() {
        const needsForm = this.formVisible && this.canManage;
        const team = this.teamId ? this.team(this.teamId) : null;
        const needsArchiveTeam = needsForm && !!team && !team.isEspoTeam;

        this.ensureButton('newTeam', this.canManage, {
            label: this.translate('New team', 'labels', 'TeamBoard'),
            position: 'right',
            onClick: () => this.confirmDiscard(() => this.openNewTeam()),
        });

        this.ensureButton('archiveTeam', needsArchiveTeam, {
            label: this.translate('Archive team', 'labels', 'TeamBoard'),
            style: 'danger',
            position: 'right',
            onClick: () => this.archiveTeam(this.teamId),
        });
    }

    ensureButton(name, needed, definition) {
        const has = this.buttonList.some(item => item.name === name);

        if (needed && !has) {
            this.addButton(Object.assign({name}, definition), false, true);

            return;
        }

        if (!needed && has) {
            this.removeButton(name, true);
        }
    }

    // A field applied through the native record view answers with the refreshed
    // board. Keep the modal open, bring its own list and its open form to the
    // state the server confirmed, and hand the payload to the board.
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
    // afterFieldSync instead, so a close during a pending save loses nothing.
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
    // ui/dialog.js callOnBackdropClick) and decides whether to close, per
    // U23: only when there is no unsaved form data.
    onBackdropClick() {
        if (this.hasUnsavedForm()) return;

        this.close();
    }

    actionSave() {
        if (!this.formVisible || !this.canManage || this.isSaving) {
            return;
        }

        const values = this.teamRecordView.processFetch();

        if (!values) {
            return;
        }

        const team = this.teamId ? this.team(this.teamId) : null;
        const payload = {colourKey: values.colourKey || 'slate'};

        // A team linked to an EspoCRM Team keeps its system name and positions.
        if (!team || !team.isEspoTeam) {
            payload.name = (values.name || '').trim();
            payload.positionList = [...(values.positionList || [])];
        }

        this.request(
            this.teamId ? 'put' : 'post',
            this.teamId ? `TeamBoard/squad/${this.teamId}` : 'TeamBoard/squad',
            payload
        );
    }

    archiveTeam(id) {
        this.confirm(this.translate('Historical assignments remain', 'labels', 'TeamBoard'))
            .then(() => this.request('post', `TeamBoard/squad/${id}/archive`, {viewDate: this.options.viewDate}));
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

    request(method, url, payload) {
        if (this.isSaving) {
            return;
        }

        this.isSaving = true;
        Espo.Ui.notifyWait();
        Espo.Ajax[`${method}Request`](url, payload)
            .then(data => {
                this.isSaving = false;
                Espo.Ui.notify(false);
                this.pendingBoardData = null;
                this.trigger('done', data);

                if (!this.isClosed) {
                    this.close();
                }
            })
            .catch(() => {
                this.isSaving = false;
                Espo.Ui.notify(false);
            });
    }
}

export default TeamManagerModalView;

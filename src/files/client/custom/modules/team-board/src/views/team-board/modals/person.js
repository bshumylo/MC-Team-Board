import ModalView from 'views/modal';
import View from 'view';
import Model from 'model';
import PersonDetailRecordView from 'team-board:views/team-board/record/person-detail';
import EnumFieldView from 'views/fields/enum';
import ViewRecordHelper from 'view-record-helper';

// The board endpoint returns a refreshed timeline, not entity attributes.
// Keep the native model save lifecycle without treating that timeline as a person.
class PersonFormModel extends Model {
    prepareAttributes() {
        return {};
    }

    // The member endpoint validates the name pair as one value and rebuilds the
    // full name from it, and it accepts a note only as a string. A native patch
    // of a single field is completed here so that editing one field never
    // rejects the request or drops a neighbouring value.
    save(attributes, options) {
        if (!attributes) return super.save(attributes, options);

        const data = Object.assign({}, attributes);

        if ('firstName' in data || 'lastName' in data) {
            data.firstName = 'firstName' in data ? data.firstName : this.get('firstName');
            data.lastName = 'lastName' in data ? data.lastName : this.get('lastName');
        }

        if ('note' in data && (data.note === null || data.note === undefined)) {
            data.note = '';
        }

        return super.save(data, options);
    }
}

/**
 * The History table of the person dialog, as its own native view.
 *
 * A retried or late timeline response used to redraw through the whole
 * dialog's `reRender()`. That also redrew the person record's own container,
 * which native EspoCRM field views only wire their inline-edit pencil into
 * once, on the very first `after:render` (`client/src/views/fields/base.ts`,
 * `initInlineEdit`, bound with `listenToOnce`). A later redraw of that
 * ancestor gave the photo, name and note cells fresh DOM without a pencil
 * ever put back into it. Keeping the table as a view of its own means a
 * history refresh only ever redraws this container, so the person record's
 * DOM — and the inline-edit controls EspoCRM already attached to it — stays
 * exactly as it was.
 */
class PersonHistoryView extends View {

    templateContent = '{{#if historyError}}<div class="alert alert-danger">' +
        '<span>{{timelineLoadError}}</span> ' +
        '<button type="button" class="btn btn-default btn-xs" data-action="retryHistory">' +
        '{{retryHistoryLabel}}</button></div>{{/if}}' +
        '{{#if historyLoading}}<p class="text-muted">{{loadingHistoryLabel}}</p>{{else}}' +
        '{{#if hasPeriods}}<table class="table table-condensed"><thead><tr>' +
        '<th>{{historyTeamLabel}}</th><th>{{historyPositionLabel}}</th><th>{{historyFromLabel}}</th>' +
        '<th>{{historyUntilLabel}}</th><th>{{historyStatusLabel}}</th><th></th></tr></thead><tbody>' +
        '{{#each periods}}<tr><td data-label="{{../historyTeamLabel}}">{{teamName}}</td>' +
        '<td data-label="{{../historyPositionLabel}}">{{position}}</td>' +
        '<td data-label="{{../historyFromLabel}}">{{dateFrom}}</td>' +
        '<td data-label="{{../historyUntilLabel}}">{{dateTo}}</td>' +
        '{{#if canEditStatus}}<td class="cell" data-name="status" tabindex="-1" ' +
        'data-label="{{../historyStatusLabel}}">' +
        '<div class="field" data-name="status" data-status-field="{{id}}"></div></td>{{else}}' +
        '<td data-label="{{../historyStatusLabel}}">{{status}}</td>{{/if}}<td>{{#if canEdit}}<button type="button" ' +
        'class="btn btn-link btn-xs" data-action="editAssignment" data-id="{{id}}">' +
        '{{../editAssignmentLabel}}</button>{{/if}}</td></tr>{{/each}}</tbody></table>{{else}}' +
        '<p class="text-muted">{{emptyPeriodsLabel}}</p>{{/if}}{{/if}}'

    data() {
        return this.options.historyData();
    }
}

class PersonModalView extends ModalView {

    template = 'team-board:team-board/modals/person'

    className = 'dialog dialog-record tb-person-dialog'

    events = {
        'click [data-action="retryHistory"]': function () {
            const id = (this.options.member || {}).boardMemberId;
            if (id) this.loadHistory(id);
        },
        'click [data-action="editAssignment"]': function (e) {
            const item = (this.history || []).find(entry => entry.id === e.currentTarget.dataset.id);
            if (item && this.canEditAssignment(item)) this.openAssignment(item);
        },
    }

    setup() {
        const member = this.options.member || {};
        this.history = null;
        this.historyLoading = !!member.boardMemberId;
        this.historyError = false;
        // Bumped on every loadHistory call so an older, overlapping response
        // that resolves after a newer one is simply ignored.
        this.historyRequestToken = 0;
        // Set while a response has landed but the history view had not
        // rendered yet, so the redraw it still owes gets applied once it has.
        this.historyRenderPending = false;
        this.isSaving = false;
        this.saveError = null;
        this.statusFieldKeys = [];
        this.canEditBoard = !!this.options.canManage;
        // A person that does not exist yet has nothing to read, so the form
        // opens in edit mode the way a native create form does.
        this.isNewPerson = !member.boardMemberId;
        this.editModeIsOn = this.canEditBoard && this.isNewPerson;
        this.headerText = member.name || this.translate('New person', 'labels', 'TeamBoard');

        this.linked = !!member.isEspoUser;
        this.canArchive = this.canEditBoard && !this.linked;
        this.memberTypeLabel = this.translate(
            this.linked ? 'EspoCRM user' : 'Board only — no EspoCRM account',
            'labels', 'TeamBoard'
        );

        // The button lifecycle of the native detail form: Edit alone while
        // reading, Save and Close only once editing has been entered. Archive
        // Person and the origin marker live in the same footer row (U23):
        // Archive Person directly next to Edit (read mode only, mirroring
        // Edit's own visibility), the origin marker pinned to the right edge.
        this.buttonList = [
            {
                name: 'edit',
                label: 'Edit',
                hidden: !this.canEditBoard || this.editModeIsOn,
                onClick: () => this.actionEdit(),
            },
            {
                name: 'save',
                label: 'Save',
                style: 'primary',
                hidden: !this.editModeIsOn,
                onClick: () => this.actionSave(),
            },
            {
                name: 'cancel',
                label: 'Close',
                hidden: this.canEditBoard && !this.editModeIsOn,
                onClick: () => this.actionCancelEdit(),
            },
            {
                name: 'archivePerson',
                label: this.translate('Archive person', 'labels', 'TeamBoard'),
                style: 'danger',
                hidden: !this.canArchive || this.isNewPerson || this.editModeIsOn,
                onClick: () => this.archivePerson(),
            },
            {
                name: 'originMarker',
                html: this.memberTypeLabel,
                className: 'tb-origin-marker',
                position: 'right',
                disabled: true,
                hidden: false,
            },
        ];

        this.setupPersonFields(member);

        // Its own view, so a history refresh redraws this container alone; see
        // PersonHistoryView above. Bound to its own dedicated container so it
        // can never fall back to binding the whole modal body and taking the
        // person record's DOM down with it.
        this.historyView = new PersonHistoryView({
            historyData: () => this.historyData(),
        });
        this.assignView('history', this.historyView, '.history-container');

        if (member.boardMemberId) {
            this.loadHistory(member.boardMemberId);
        }
    }

    setupPersonFields(member) {
        // An EspoCRM avatar takes precedence over a board photo, so the field
        // holds no board attachment in that case and shows the system image.
        const boardPhotoId = member.systemAvatarId ? null : (member.boardPhotoId || null);

        this.formModel = new PersonFormModel({
            id: member.boardMemberId,
            firstName: member.firstName || '',
            lastName: member.lastName || '',
            note: member.boardNote || '',
            photoId: boardPhotoId,
            photoName: boardPhotoId ? (member.name || this.translate('Photo', 'labels', 'TeamBoard')) : null,
        }, {entityType: 'TeamBoardMember', urlRoot: 'TeamBoard/member'});

        const nameReadOnly = !this.canEditBoard || !!member.isEspoUser;
        // A system avatar belongs to the CRM profile, so the board offers no
        // replacement and no removal for it.
        const photoReadOnly = !this.canEditBoard || !!member.systemAvatarId;
        // The dialog holds the native detail record view, so the form reads as
        // a record until Edit is pressed and then becomes the native edit form
        // with one Save, exactly like the system User form.
        this.personRecordView = new PersonDetailRecordView({
            model: this.formModel,
            startInEditMode: this.editModeIsOn,
            buttonsDisabled: true,
            sideDisabled: true,
            bottomDisabled: true,
            webSocketDisabled: true,
            readOnly: !this.canEditBoard,
            // Cells name the field view instead of holding a constructed one, so
            // the record view owns the field lifecycle and its native inline
            // edit, validation and read-only handling apply.
            detailLayout: [{rows: [
                // Photo and the name pair share one row, exactly like the
                // native cell-count-based width split the record layout-type
                // already applies to a two-cell row: three cells divide the
                // row into equal thirds instead of two separate rows.
                [
                    {
                        name: 'photo',
                        view: 'team-board:views/fields/member-photo',
                        labelText: this.translate('Photo', 'labels', 'TeamBoard'),
                        readOnly: photoReadOnly,
                        params: {
                            previewSize: 'small',
                            maxFileSize: 5,
                            accept: ['image/*'],
                            // A person that does not exist yet has nothing to
                            // patch; that form is in edit mode anyway.
                            inlineEditDisabled: !member.boardMemberId,
                        },
                        options: {
                            userId: member.userId || null,
                            systemAvatarId: member.systemAvatarId || null,
                            // The photo has its own endpoint, so the field's own
                            // inline save is answered here.
                            applyPhoto: () => this.applyPhotoInline(),
                        },
                    },
                    {
                        name: 'firstName',
                        view: 'views/fields/varchar',
                        labelText: this.translate('First Name', 'labels', 'TeamBoard'),
                        readOnly: nameReadOnly,
                        params: {required: !member.isEspoUser, maxLength: 100},
                    },
                    {
                        name: 'lastName',
                        view: 'views/fields/varchar',
                        labelText: this.translate('Last Name', 'labels', 'TeamBoard'),
                        readOnly: nameReadOnly,
                        params: {maxLength: 100},
                    },
                ],
                [{
                    name: 'note',
                    view: 'views/fields/text',
                    labelText: this.translate('Note', 'labels', 'TeamBoard'),
                    readOnly: !this.canEditBoard,
                }],
            ]}],
        });
        this.assignView('personRecord', this.personRecordView);
        this.listenTo(this.formModel, 'sync', () => {
            this.applySavedValues();
            // The board owns its own rendering. The existing done contract lets
            // it refresh the card name and the tooltip without a page reload.
            this.trigger('done', {});
        });
    }

    /**
     * Take the values the board accepted as the new starting point, so the
     * card, the header and a later Cancel all read from them and not from the
     * values the dialog was opened with.
     */
    applySavedValues() {
        const member = this.options.member || {};

        Object.assign(member, {
            firstName: this.formModel.get('firstName'),
            lastName: this.formModel.get('lastName'),
            boardNote: this.formModel.get('note') || '',
        });

        if (!member.isEspoUser) {
            member.name = [member.firstName, member.lastName].filter(Boolean).join(' ');
        }

        this.updateHeader(member);
    }

    actionEdit() {
        if (!this.canEditBoard || this.editModeIsOn) return;

        // P14: a CRM user's own profile (first/last name, and everything
        // else about their CRM account) is not owned by the board — the
        // board form already keeps those fields read-only for a linked
        // person (see nameReadOnly in setupPersonFields()). Edit here opens
        // the native User edit view instead, exactly the way any other
        // EspoCRM entity is edited, so it goes through the real User ACL:
        // permitted, it edits; not permitted, the native controller shows
        // its own system-level access-denied view. Nothing board-specific
        // is bypassed by this — this dialog never carried CRM-profile edit
        // capability in the first place.
        if (this.linked) {
            this.openLinkedUserEdit();

            return;
        }

        this.editModeIsOn = true;
        this.saveError = null;
        this.personRecordView.setEditMode();
        this.updateModeButtons();
    }

    /** P14: hand off to the native EspoCRM User edit view. */
    openLinkedUserEdit() {
        const userId = (this.options.member || {}).userId;

        if (!userId) return;

        // The native User edit ACL: `own` covers only the current user's own
        // profile. Without rights the form would open inert, so show the
        // system denial instead (P14).
        const level = this.getUser().isAdmin() ? 'all' : this.getAcl().getLevel('User', 'edit');
        const allowed = level === 'all' || level === 'yes' || level === 'team' ||
            (level === 'own' && userId === this.getUser().id);

        if (!allowed) {
            Espo.Ui.error(this.translate('error403', 'messages'));

            return;
        }

        this.getRouter().navigate(`#User/edit/${userId}`, {trigger: true});
        this.close();
    }

    // Native Cancel: the form returns to read mode with the values it had.
    // A person that is being created has no read mode to return to.
    actionCancelEdit() {
        if (!this.editModeIsOn || this.isNewPerson) {
            this.close();

            return;
        }

        this.saveError = null;
        this.personRecordView.cancelEdit();
        this.editModeIsOn = false;
        this.updateModeButtons();
    }

    updateModeButtons() {
        const editing = this.editModeIsOn;

        this.canEditBoard && !editing ? this.showActionItem('edit') : this.hideActionItem('edit');
        editing ? this.showActionItem('save') : this.hideActionItem('save');
        this.canEditBoard && !editing ? this.hideActionItem('cancel') : this.showActionItem('cancel');

        // Archive Person stays next to Edit: visible in the same read-mode
        // state Edit itself is visible in (U23).
        this.canArchive && !this.isNewPerson && !editing
            ? this.showActionItem('archivePerson')
            : this.hideActionItem('archivePerson');
    }

    photoField() {
        const record = this.personRecordView;

        return record && typeof record.getFieldView === 'function'
            ? record.getFieldView('photo')
            : null;
    }

    /**
     * Apply the photo of an existing person on its own, without the rest of
     * the form. The board card follows it the same way it follows the photo
     * of a dialog-wide Save.
     *
     * @return {Promise}
     */
    applyPhotoInline() {
        const member = this.options.member || {};

        if (!member.boardMemberId) {
            return Promise.reject(new Error('The board member does not exist yet.'));
        }

        return this.applyPhoto(member.boardMemberId).then(data => {
            if (data) {
                member.boardPhotoId = data.boardPhotoId || null;
                this.trigger('done', data);
            }

            return data;
        });
    }

    updateHeader(member) {
        if (!member.name) return;

        this.headerText = member.name;

        if (this.dialog) this.dialog.setHeaderText(this.headerText);
    }

    loadHistory(id) {
        // Overlapping calls (a retry, an assignment saved twice in a row, a
        // reload after the assignment dialog closes) fire independent
        // requests with no guarantee they answer in the order they were
        // sent. Only the response to the most recently issued call is
        // allowed to apply; a stale one that lands after it is dropped.
        const requestToken = (this.historyRequestToken = (this.historyRequestToken || 0) + 1);

        Espo.Ajax.getRequest(`TeamBoard/member/${id}/timeline`)
            .then(history => {
                if (requestToken !== this.historyRequestToken) return;

                this.history = Array.isArray(history) ? history : [];
                this.historyError = false;
                this.historyLoading = false;
                this.refreshHistory();
            })
            .catch(() => {
                if (requestToken !== this.historyRequestToken) return;

                this.historyError = true;
                this.historyLoading = false;
                this.refreshHistory();
            });
    }

    // The status selects live in rows the history view has to draw first, so
    // they are attached once that markup exists. A history refresh redraws
    // only that view, so the person record's own DOM — and whatever it is
    // mid-edit — is left exactly as it was.
    refreshHistory() {
        // The per-row status field views go first: left as children of the
        // history view they would be drawn into the redrawn table too, and
        // each field view adds its own pencil to its cell — a second one
        // next to the new view's (Review №25 round 3). No field is attached
        // before the table's own paint for the same reason.
        this.clearStatusFields();

        // Only the latest refresh attaches fields: an earlier one whose redraw
        // resolves later would otherwise add its own set to the same cells.
        const refreshToken = (this.historyRefreshToken = (this.historyRefreshToken || 0) + 1);
        const setupIfLatest = () => {
            if (refreshToken === this.historyRefreshToken) this.setupStatusFields();
        };

        if (!this.historyView.isRendered()) {
            // The container has not painted yet, including mid-render, so a
            // reRender now would have nothing to redraw or could race the
            // in-flight one. Its very first paint may still bake in the
            // "loading" markup that was current before this data arrived, so
            // one more pass is owed once that paint actually lands, instead
            // of trusting it to already show the current state.
            if (!this.historyRenderPending) {
                this.historyRenderPending = true;

                this.listenToOnce(this.historyView, 'after:render', () => {
                    this.historyRenderPending = false;

                    Promise.resolve(this.historyView.reRender()).then(() => {
                        this.historyRefreshToken = (this.historyRefreshToken || 0) + 1;
                        this.setupStatusFields();
                    });
                });
            }

            return;
        }

        Promise.resolve(this.historyView.reRender()).then(setupIfLatest);
    }

    /**
     * The status of an editable period is the native enum field in its own
     * cell, edited exactly like `gender` on a `User`: the cell shows a pencil,
     * the click turns the value into a select with the check and cancel links,
     * and Ctrl+Enter or Esc do what they do everywhere else.
     *
     * The field asks its record helper to save, which is the seam EspoCRM's
     * own record view uses. Here the answer patches that one period instead of
     * a whole record, so nothing but the status moves.
     */
    setupStatusFields() {
        this.clearStatusFields();

        if (!this.canEditBoard) return;

        this.periods().filter(row => row.canEditStatus).forEach(row => {
            const key = `status-${row.id}`;
            const recordHelper = new ViewRecordHelper();
            const fieldView = new EnumFieldView({
                name: 'status',
                model: new Model({status: row.statusValue}, {entityType: 'TeamBoardAssignment'}),
                recordHelper: recordHelper,
                labelText: this.translate('status', 'fields', 'TeamBoardAssignment'),
                params: {
                    required: true,
                    options: ['draft', 'confirmed'],
                    translation: 'TeamBoardAssignment.options.status',
                },
            });

            this.listenTo(recordHelper, 'inline-edit-save', (name, options) =>
                this.saveStatus(row.id, fieldView, options || {}));

            // assignView only binds the field view to its cell; unlike a
            // nested view registered before the parent's own first render, a
            // view assigned after the parent is already on the page is never
            // rendered for you (Espo's setView() just calls setElement()).
            // Without this render() the cell stays the empty
            // `<div data-status-field>` markup forever — no dropdown, no
            // value, nothing to click (Review №25 / T12).
            this.historyView.assignView(key, fieldView, `[data-status-field="${row.id}"]`)
                .then(view => view.render());

            this.statusFieldKeys.push(key);
        });
    }

    clearStatusFields() {
        (this.statusFieldKeys || []).forEach(key => this.historyView.clearView(key));
        this.statusFieldKeys = [];
    }

    /**
     * The same steps `views/record/detail` takes for one inline-edited field,
     * against the period endpoint.
     *
     * @param {string} assignmentId
     * @param {Object} fieldView
     * @param {{bypassClose?: boolean}} options
     */
    saveStatus(assignmentId, fieldView, options) {
        const model = fieldView.model;
        const previous = (fieldView.initialAttributes || {}).status;

        model.setMultiple(fieldView.fetch(), {silent: true});

        const value = model.get('status');

        if (value === previous) {
            if (!options.bypassClose) fieldView.inlineEditClose(true);

            return;
        }

        if (fieldView.validate()) {
            Espo.Ui.error(this.translate('Not valid'));

            return;
        }

        if (!options.bypassClose) fieldView.inlineEditClose(true);

        Espo.Ui.notify(this.translate('saving', 'messages'));

        Espo.Ajax.putRequest(`TeamBoard/assignment/${assignmentId}`, {
            status: value,
            viewDate: this.options.viewDate || this.systemToday(),
        })
            .then(data => {
                Espo.Ui.success(this.translate('Saved'));
                fieldView.trigger('after:inline-save');
                this.trigger('done', data);
                this.loadHistory((this.options.member || {}).boardMemberId);
            })
            .catch(error => {
                // The server refused the transition, so the cell goes back to
                // the value the period still has.
                model.setMultiple({status: previous}, {silent: true});
                fieldView.reRender();
                Espo.Ui.error(
                    error && error.message
                        ? error.message
                        : this.translate('Could not save the assignment.', 'messages', 'TeamBoard')
                );
            });
    }

    // Archive Person and the origin marker (memberTypeLabel) are footer
    // buttons now (see setup(), U23) — the template itself only needs the
    // record, the history table and the save error.
    data() {
        const member = this.options.member || {};

        return {
            member: Object.assign({firstName: '', lastName: '', boardNote: ''}, member),
            existingMember: !!member.boardMemberId,
            editable: this.canEditBoard,
            periodsLabel: this.translate('History', 'labels', 'TeamBoard'),
            saveError: this.saveError || '',
        };
    }

    /**
     * The data of the history table alone, read by PersonHistoryView on every
     * render of its own — including the first one, still nested in this
     * dialog's own render, and every later refresh, on its own.
     */
    historyData() {
        return {
            historyTeamLabel: this.translate('Team', 'labels', 'TeamBoard'),
            historyPositionLabel: this.translate('Position', 'labels', 'TeamBoard'),
            historyFromLabel: this.translate('From', 'labels', 'TeamBoard'),
            historyUntilLabel: this.translate('Until', 'labels', 'TeamBoard'),
            historyStatusLabel: this.translate('Status', 'labels', 'TeamBoard'),
            emptyPeriodsLabel: this.translate('No periods', 'labels', 'TeamBoard'),
            loadingHistoryLabel: this.translate('Loading history', 'labels', 'TeamBoard'),
            editAssignmentLabel: this.translate('Edit period', 'labels', 'TeamBoard'),
            historyError: this.historyError,
            historyLoading: this.historyLoading,
            timelineLoadError: this.translate('timelineLoadError', 'messages', 'TeamBoard'),
            retryHistoryLabel: this.translate('Retry', 'labels', 'TeamBoard'),
            periods: this.periods(),
            hasPeriods: this.periods().length > 0,
        };
    }

    // The native record flag is updated after field events, but a freshly
    // typed value can still be in the DOM when the backdrop is clicked.
    // Compare the current form with the stored model as well as that flag.
    hasUnsavedForm() {
        if (!this.editModeIsOn) return false;
        if (this.personRecordView.hasChanged()) return true;

        const values = this.personRecordView.fetch();
        const textFields = ['firstName', 'lastName', 'note'];
        if (textFields.some(name =>
            Object.prototype.hasOwnProperty.call(values, name) &&
            (values[name] ?? '') !== (this.formModel.get(name) ?? '')
        )) return true;

        const photo = this.personRecordView.getFieldView('photo');
        return !!(photo && (photo.getPendingFile?.() || photo.isRemovalStaged?.()));
    }

    // Backdrop is 'static' (views/modal default); ui/dialog.js calls this
    // hook on each outside click so U23 can close only a clean form.
    onBackdropClick() {
        if (this.hasUnsavedForm()) return;

        this.close();
    }

    actionSave() {
        if (!this.canEditBoard || this.isSaving) return;

        const member = this.options.member || {};
        const values = this.personRecordView.processFetch();
        if (!values) return;
        const payload = {note: values.note || ''};
        if (!member.isEspoUser) {
            payload.firstName = (values.firstName || '').trim();
            payload.lastName = (values.lastName || '').trim();

            if (!payload.firstName) {
                this.saveError = this.translate('First Name is required.', 'messages', 'TeamBoard');
                Espo.Ui.notify(this.saveError, 'error');
                return;
            }
        }
        const id = member.boardMemberId;
        this.isSaving = true;
        Espo.Ui.notifyWait();
        Espo.Ajax[id ? 'putRequest' : 'postRequest'](
            id ? `TeamBoard/member/${id}` : 'TeamBoard/member', payload
        ).then(data => {
            const createdId = data.boardMemberId || data.id;
            if (!createdId) {
                throw new Error('The server did not return boardMemberId.');
            }

            this.options.member = Object.assign({}, member, {
                boardMemberId: createdId,
                id: createdId,
            });

            // The photo belongs to the same form, so it is applied with the
            // rest of it and the board hears about the result once.
            return this.applyPhoto(createdId).then(photoData => photoData || data);
        }).then(data => {
            const wasNew = this.isNewPerson;

            this.isSaving = false;
            Espo.Ui.notify(false);
            this.saveError = null;
            this.applySavedValues();
            // The dialog saved through the board, not through the model, so
            // the record view is told which values it may now revert to.
            this.personRecordView.attributes = this.formModel.getClonedAttributes();
            this.trigger('done', data);

            // Creating a person is one action and ends with the dialog; an
            // existing card goes back to read mode, as the native form does.
            if (wasNew) {
                this.close();

                return;
            }

            this.editModeIsOn = false;
            this.updateModeButtons();

            Promise.resolve(this.personRecordView.setDetailMode())
                .then(() => this.refreshHistory());
        }).catch(error => {
            this.isSaving = false;
            Espo.Ui.notify(false);
            this.saveError = error && error.message
                ? error.message
                : this.translate('Could not save the board member.', 'messages', 'TeamBoard');
            Espo.Ui.notify(this.saveError, 'error');
        });
    }

    // A file chosen in the native image field is uploaded once the member
    // exists; clearing that field removes the stored board photo.
    applyPhoto(memberId) {
        const field = this.photoField();

        if (!field) return Promise.resolve(null);

        const file = typeof field.getPendingFile === 'function' ? field.getPendingFile() : null;

        if (file) {
            return this.readFile(file)
                .then(fileData => Espo.Ajax.postRequest(`TeamBoard/member/${memberId}/photo`, {
                    name: file.name,
                    type: file.type,
                    file: fileData,
                }))
                .then(data => {
                    if (typeof field.applyStoredId === 'function') {
                        field.applyStoredId(data ? data.boardPhotoId : null);
                    }

                    return data;
                });
        }

        if (typeof field.isRemovalStaged === 'function' && field.isRemovalStaged()) {
            return Espo.Ajax.deleteRequest(`TeamBoard/member/${memberId}/photo`)
                .then(data => {
                    if (typeof field.applyStoredId === 'function') {
                        field.applyStoredId(null);
                    }

                    return data;
                });
        }

        return Promise.resolve(null);
    }

    periods() {
        const id = (this.options.member || {}).boardMemberId;
        const names = Object.fromEntries((this.options.teams || []).map(team => [team.boardSquadId || team.id, team.name]));

        const assignments = this.history || this.options.assignments || [];

        return assignments.filter(item => (item.boardMemberId || item.memberId) === id)
            .sort((a, b) => b.dateFrom.localeCompare(a.dateFrom)).map(item => ({
                id: item.id,
                teamName: item.state === 'reserve' ? this.translate('Reserve', 'labels', 'TeamBoard') :
                    item.state === 'inactive' ? this.translate('Inactive in CRM', 'labels', 'TeamBoard') :
                        item.teamName || names[item.boardSquadId || item.teamId] || '',
                position: ['reserve', 'inactive'].includes(item.state) ? '—' :
                    this.translate(item.position, 'positions', 'TeamBoard'),
                dateFrom: this.getDateTime().toDisplayDate(item.dateFrom),
                dateTo: item.dateTo ? this.getDateTime().toDisplayDate(item.dateTo) : '—',
                status: this.translate(item.status === 'confirmed' ? 'Confirmed' : 'Draft', 'labels', 'TeamBoard'),
                statusValue: item.status === 'confirmed' ? 'confirmed' : 'draft',
                canEdit: this.canEditAssignment(item),
                canEditStatus: this.canEditAssignmentStatus(item),
            }));
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
     * T12/T04: the status dropdown is offered only where a status change is
     * allowed. An actual (already started) confirmed period cannot go back
     * to Draft — the server refuses it — and Confirmed is its only other
     * value, so such a row shows its status as plain text.
     */
    canEditAssignmentStatus(item) {
        if (!this.canEditAssignment(item)) return false;

        return !(item.status === 'confirmed' && item.dateFrom <= this.systemToday());
    }

    canEditAssignment(item) {
        if (!this.canEditBoard || !item || !item.dateFrom ||
            ['reserve', 'inactive'].includes(item.state) || !(item.boardSquadId || item.teamId)) return false;
        const today = this.systemToday();
        return !item.dateTo || item.dateTo > today;
    }

    openAssignment(item) {
        const team = (this.options.teams || []).find(entry =>
            (entry.boardSquadId || entry.id) === (item.boardSquadId || item.teamId)
        );
        this.createView('assignment', 'team-board:views/team-board/modals/assignment', {
            assignmentId: item.id,
            boardMemberId: item.boardMemberId || this.options.member.boardMemberId,
            boardSquadId: item.boardSquadId || item.teamId,
            position: item.position,
            dateFrom: item.dateFrom,
            dateTo: item.dateTo,
            status: item.status,
            note: item.note,
            viewDate: this.options.viewDate || this.systemToday(),
            positionList: team?.positionList || ['Supervisor', 'Leader', 'Vice Leader', 'Member'],
            canManage: this.canEditBoard,
            canDelete: false,
        }).then(view => {
            this.listenToOnce(view, 'done', () => this.loadHistory(this.options.member.boardMemberId));
            view.render();
        });
    }

    readFile(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    archivePerson() {
        if (!this.canEditBoard) return;
        const id = (this.options.member || {}).boardMemberId;
        if (!id) return;
        this.confirm(this.translate('Historical assignments remain', 'labels', 'TeamBoard'))
            .then(() => this.request('post', `TeamBoard/member/${id}/archive`, {}));
    }

    request(method, url, payload) {
        Espo.Ui.notifyWait();
        Espo.Ajax[`${method}Request`](url, payload)
            .then(data => {
                Espo.Ui.notify(false);
                this.trigger('done', data);
                this.close();
            })
            .catch(() => Espo.Ui.notify(false));
    }
}

export default PersonModalView;

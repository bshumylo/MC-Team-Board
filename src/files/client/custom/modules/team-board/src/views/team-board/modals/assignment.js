import ModalView from 'views/modal';
import Model from 'model';
import EditForModalRecordView from 'views/record/edit-for-modal';
import DateFieldView from 'views/fields/date';
import EnumFieldView from 'views/fields/enum';

/**
 * A board drop creates or edits a dated assignment rather than directly
 * changing the live Team/User relationship.
 */
class AssignmentModalView extends ModalView {

    template = 'team-board:team-board/modals/assignment'

    className = 'dialog dialog-record'

    setup() {
        this.headerText = this.translate('Assignment', 'labels', 'TeamBoard');

        this.buttonList = [
            {
                name: 'save',
                label: 'Save',
                style: 'primary',
                onClick: () => this.actionSave(),
            },
            {
                name: 'cancel',
                label: 'Cancel',
                onClick: () => this.close(),
            },
        ];

        if (this.options.assignmentId && this.options.canDelete) {
            this.buttonList.splice(1, 0, {
                name: 'remove',
                label: this.translate('Remove', 'labels', 'TeamBoard'),
                style: 'danger',
                onClick: () => this.actionRemove(),
            });
        }

        this.formModel = new Model({
            position: this.options.position || null,
            dateFrom: this.options.dateFrom,
            dateTo: this.options.dateTo || null,
            status: this.options.status || 'draft',
        }, {entityType: 'TeamBoardAssignment'});

        const fromLabel = this.translate('dateFrom', 'fields', 'TeamBoardAssignment');
        const toLabel = this.translate('dateTo', 'fields', 'TeamBoardAssignment');
        // The position list belongs to the team, so the native enum field is
        // given its options instead of the entity-wide ones.
        const positionList = [...(this.options.positionList || [])];

        // A period may still carry a position the team no longer offers. The
        // native enum only renders a value it knows, so keep it selectable.
        if (this.options.position && !positionList.includes(this.options.position)) {
            positionList.unshift(this.options.position);
        }

        const positionLabels = Object.fromEntries(
            positionList.map(position => [position, this.translate(position, 'positions', 'TeamBoard')])
        );

        this.recordView = new EditForModalRecordView({
            model: this.formModel,
            // The same rows the canonical TeamBoardAssignment detail layout
            // defines, so the dialog reads like the entity's own form.
            detailLayout: [{
                rows: [
                    [
                        {
                            view: new EnumFieldView({
                                name: 'position',
                                labelText: this.translate('position', 'fields', 'TeamBoardAssignment'),
                                params: {
                                    required: true,
                                    options: positionList,
                                    translatedOptions: positionLabels,
                                },
                            }),
                        },
                        {
                            view: new EnumFieldView({
                                name: 'status',
                                labelText: this.translate('status', 'fields', 'TeamBoardAssignment'),
                                params: {
                                    required: true,
                                    options: ['draft', 'confirmed'],
                                    translation: 'TeamBoardAssignment.options.status',
                                },
                            }),
                        },
                    ],
                    [
                        {
                            view: new DateFieldView({
                                name: 'dateFrom',
                                labelText: fromLabel,
                                params: {required: true},
                            }),
                        },
                        {
                            view: new DateFieldView({
                                name: 'dateTo',
                                labelText: toLabel,
                                otherFieldLabelText: fromLabel,
                                params: {after: 'dateFrom'},
                            }),
                        },
                    ],
                ],
            }],
        });

        this.assignView('record', this.recordView);
    }

    actionSave() {
        const values = this.recordView.processFetch();

        if (!values) {
            return;
        }

        const payload = {
            boardMemberId: this.options.boardMemberId || this.options.memberId,
            boardSquadId: this.options.boardSquadId || this.options.teamId,
            position: values.position,
            dateFrom: values.dateFrom,
            dateTo: values.dateTo || null,
            status: values.status,
            viewDate: this.options.viewDate,
        };

        const request = this.options.assignmentId
            ? Espo.Ajax.putRequest('TeamBoard/assignment/' + this.options.assignmentId, payload)
            : Espo.Ajax.postRequest('TeamBoard/assignment', payload);

        Espo.Ui.notifyWait();

        request
            .then(data => {
                Espo.Ui.notify(false);
                this.trigger('done', data);
                this.close();
            })
            .catch(error => {
                Espo.Ui.notify(false);
                this.notifyValidation(error && error.message ? error.message : 'Could not save the assignment.');
            });
    }

    notifyValidation(message) {
        Espo.Ui.notify(this.translate(message, 'messages', 'TeamBoard'), 'error');
    }

    // Backdrop is 'static' (views/modal default), so a click outside never
    // auto-closes; this hook still fires on every such click (see
    // ui/dialog.js callOnBackdropClick) and decides whether to close, per
    // U23: only when the form holds no unsaved edits (native record-view
    // change tracking, the same one a real navigate-away confirm uses).
    onBackdropClick() {
        if (this.recordView.hasChanged()) return;

        this.close();
    }

    actionRemove() {
        this.confirm(this.translate('confirmRemovePeriod', 'messages', 'TeamBoard'))
            .then(() => {
                const url = 'TeamBoard/assignment/' + this.options.assignmentId +
                    '?viewDate=' + encodeURIComponent(this.options.viewDate);

                Espo.Ui.notifyWait();

                Espo.Ajax.deleteRequest(url)
                    .then(data => {
                        Espo.Ui.notify(false);
                        this.trigger('done', data);
                        this.close();
                    })
                    .catch(() => Espo.Ui.notify(false));
            });
    }
}

export default AssignmentModalView;

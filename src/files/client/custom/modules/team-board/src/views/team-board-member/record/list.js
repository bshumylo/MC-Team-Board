import ListRecordView from 'views/record/list';

/**
 * P13: read-only rows. No checkboxes, mass actions, export, merge, follow or
 * row actions; a row click opens the board person card instead of a generic
 * detail page.
 */
class TeamBoardMemberListRecordView extends ListRecordView {

    massActionsDisabled = true
    checkboxes = false
    rowActionsDisabled = true
    exportDisabled = true
    mergeDisabled = true
    massUpdateDisabled = true
    removeDisabled = true
    massFollowDisabled = true

    mandatorySelectAttributeList = ['userId', 'userName', 'name', 'note', 'photoId', 'isArchived']

    setup() {
        super.setup();

        this.addHandler('click', 'tr[data-id]', (e, row) => {
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;

            const link = e.target.closest('a');

            if (link && !link.classList.contains('link')) return;

            e.preventDefault();
            e.stopPropagation();

            this.openPerson(row.dataset.id);
        });
    }

    /** @private */
    openPerson(id) {
        const parent = this.getParentView();

        if (parent && typeof parent.openPerson === 'function') {
            parent.openPerson(id);
        }
    }
}

export default TeamBoardMemberListRecordView;

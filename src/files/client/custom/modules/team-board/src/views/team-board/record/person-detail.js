import DetailRecordView from 'views/record/detail';

/**
 * The person form of the board dialog.
 *
 * It is the native detail record view — the same one the system `User` form is
 * built from — so the read mode, the Edit/Save/Cancel lifecycle, the field
 * views, their validation and their cancel behaviour are EspoCRM's own.
 *
 * Only the surroundings of a modal are turned off: the record owns no buttons
 * (the dialog footer holds them), no side and bottom panels, and no Record API
 * access control. A board member is deliberately not writable through the
 * Record API (`Classes/Acl/TeamBoardMember/AccessChecker`), so the native
 * access check would lock the form read-only; the board endpoints enforce the
 * real permission. Native per-field inline edit stays on — EspoCRM sends it as
 * a partial PUT, which is exactly what the board member endpoint accepts.
 */
class PersonDetailRecordView extends DetailRecordView {

    sideView = null

    bottomView = null

    buttonsDisabled = true

    isWide = true

    accessControlDisabled = true

    confirmLeaveDisabled = true

    setup() {
        // A person that does not exist yet has nothing to read, so the form
        // opens in edit mode the way a native create form does. The native
        // view takes its starting mode from the class, not from an option.
        if (this.options.startInEditMode) {
            this.mode = 'edit';
            this.fieldsMode = 'edit';
        }

        super.setup();
    }
}

export default PersonDetailRecordView;

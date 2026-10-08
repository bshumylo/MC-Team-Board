import RecordController from 'controllers/record';

/**
 * P13: only the list page exists. A generic detail, edit or create URL of a
 * board person returns to the list, where the board person card is opened.
 */
class TeamBoardMemberController extends RecordController {

    /**
     * TeamBoardMember is an `acl: false` scope: a non-admin's client ACL
     * table never contains it, so the native check denied the list to board
     * editors who see «Manage users». Reading follows TeamBoard read, like the
     * server AccessChecker; the list is the only page, nothing is mutated here.
     */
    checkAccess(action) {
        if (!this.getAcl().check('TeamBoard', 'read')) {
            return false;
        }

        return !action || action === 'read';
    }

    actionView() {
        this.getRouter().navigate('#TeamBoardMember', {trigger: true, replace: true});
    }

    actionEdit() {
        this.actionView();
    }

    actionCreate() {
        this.actionView();
    }
}

export default TeamBoardMemberController;

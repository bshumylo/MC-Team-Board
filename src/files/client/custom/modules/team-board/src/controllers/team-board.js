import Controller from 'controller';

class TeamBoardController extends Controller {

    defaultAction = 'index'

    checkAccess() {
        return this.getAcl().check('TeamBoard');
    }

    // noinspection JSUnusedGlobalSymbols
    actionIndex() {
        this.main('team-board:views/team-board/board', {}, view => {
            view.render();
        });
    }
}

export default TeamBoardController;

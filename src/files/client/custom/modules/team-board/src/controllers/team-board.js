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

    async actionPerson(options) {
        return this.main('team-board:views/team-board/board', {}, async view => {
            // O10: a failed render/fetch already shows the native message;
            // never leak an unhandled rejection from the route callback.
            try {
                await view.render();
                await view.openPersonById(options.id);
            } catch (e) {}
        });
    }

    actionView(options) {
        return this.actionPerson(options);
    }
}

export default TeamBoardController;

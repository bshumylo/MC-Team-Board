<style>
    .team-board {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        overflow-x: auto;
        padding-bottom: 12px;
    }
    .team-board .tb-col {
        flex: 0 0 auto;
        width: 288px;
        margin-bottom: 0;
    }
    .team-board .tb-col-head {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .team-board .tb-team-name {
        font-weight: 600;
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .team-board .tb-count {
        opacity: 0.7;
        font-size: 85%;
    }
    .team-board .tb-chev {
        display: none;
        opacity: 0.5;
    }
    .team-board .tb-col-body {
        padding: 8px;
    }
    .team-board .tb-group {
        border-radius: 4px;
        padding: 2px 4px 4px;
        margin-bottom: 6px;
    }
    .team-board .tb-group.tb-over {
        outline: 2px dashed;
        outline-offset: -1px;
    }
    .team-board .tb-group-label {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 2px 2px 4px;
    }
    .team-board .tb-card {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 8px;
        margin-bottom: 6px;
    }
    .team-board .tb-card[draggable="true"] {
        cursor: grab;
    }
    .team-board .tb-card.tb-dragging {
        opacity: 0.4;
    }
    .team-board .tb-group-supervisor .tb-card {
        opacity: 0.65;
    }
    .team-board .tb-lead .tb-name {
        font-weight: 600;
    }
    .team-board .tb-avatar {
        flex: 0 0 auto;
        display: flex;
    }
    .team-board .tb-info {
        flex: 1;
        min-width: 0;
    }
    .team-board .tb-name {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .team-board .tb-menu > .btn {
        padding: 2px 6px;
    }
    .team-board .tb-drop {
        border: 1px dashed;
        border-radius: 4px;
        opacity: 0.5;
        text-align: center;
        padding: 8px;
    }
    .tb-ghost {
        position: fixed;
        z-index: 2000;
        pointer-events: none;
        opacity: 0.9;
        margin: 0;
    }
    @media (max-width: 768px) {
        .team-board {
            flex-direction: column;
            overflow-x: visible;
        }
        .team-board .tb-col {
            width: 100%;
        }
        .team-board .tb-chev {
            display: inline-block;
        }
        .team-board .tb-col-head {
            cursor: pointer;
        }
        .team-board .tb-col.tb-collapsed .tb-col-body {
            display: none;
        }
        .team-board .tb-col.tb-collapsed .tb-chev {
            transform: rotate(-90deg);
        }
    }
</style>

<div class="page-header"><h3>{{title}}</h3></div>

{{#if noTeams}}
    <p class="text-muted">{{noTeamsLabel}}</p>
{{/if}}

<div class="team-board">
    {{#each teams}}
    <div class="tb-col panel panel-default" data-team-id="{{id}}">
        <div class="panel-heading tb-col-head" data-action="toggleColumn">
            <span class="tb-team-name">{{name}}</span>
            <span class="tb-count">{{count}}</span>
            <span class="tb-chev fas fa-chevron-down"></span>
        </div>
        <div class="tb-col-body">
            {{#each groups}}
            <div class="tb-group {{cls}}" data-team-id="{{teamId}}" data-position="{{position}}">
                <div class="tb-group-label text-muted">{{label}}</div>
                {{#each members}}
                <div
                    class="tb-card panel panel-default{{#if isLeader}} tb-lead{{/if}}"
                    data-user-id="{{id}}"
                    data-team-id="{{teamId}}"
                    data-position="{{position}}"
                    {{#if canManage}}draggable="true"{{/if}}
                >
                    <div class="tb-avatar">{{{avatarHtml}}}</div>
                    <div class="tb-info">
                        <div class="tb-name">{{name}}</div>
                        <div class="tb-pos text-muted small">{{positionLabel}}</div>
                    </div>
                    {{#if canManage}}
                    <div class="btn-group tb-menu">
                        <button
                            type="button"
                            class="btn btn-link btn-sm dropdown-toggle"
                            data-toggle="dropdown"
                        ><span class="fas fa-ellipsis-v"></span></button>
                        <ul class="dropdown-menu pull-right">
                            {{#each menuPositions}}
                            <li><a
                                role="button"
                                tabindex="0"
                                data-action="setPosition"
                                data-user-id="{{userId}}"
                                data-team-id="{{teamId}}"
                                data-position="{{value}}"
                            >{{label}}</a></li>
                            {{/each}}
                            {{#if hasMenuTeams}}
                            <li class="divider"></li>
                            {{#each menuTeams}}
                            <li><a
                                role="button"
                                tabindex="0"
                                data-action="moveToTeam"
                                data-user-id="{{userId}}"
                                data-to-team-id="{{toTeamId}}"
                                data-from-team-id="{{fromTeamId}}"
                            >{{label}}</a></li>
                            {{/each}}
                            {{/if}}
                        </ul>
                    </div>
                    {{/if}}
                </div>
                {{/each}}
                {{#if isEmpty}}
                <div class="tb-drop text-muted small">{{dropHereLabel}}</div>
                {{/if}}
            </div>
            {{/each}}
        </div>
    </div>
    {{/each}}
</div>

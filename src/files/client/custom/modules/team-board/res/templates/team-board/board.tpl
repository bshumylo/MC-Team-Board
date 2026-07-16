<style>
    .team-board {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        overflow-x: auto;
        padding-bottom: 12px;
        min-height: 200px;
        /* Hide the container's own scrollbar — the fixed .tb-hscroll at the
           bottom of the window is the single visible horizontal scrollbar. */
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .team-board::-webkit-scrollbar {
        display: none;
    }
    .team-board .tb-col {
        flex: 0 0 auto;
        width: 288px;
        margin-bottom: 0;
    }
    .team-board .tb-col.tb-col-dragging {
        opacity: 0.5;
    }
    .team-board .tb-col.tb-col-insert-before {
        box-shadow: -4px 0 0 0 #337ab7;
    }
    .team-board .tb-col.tb-col-insert-after {
        box-shadow: 4px 0 0 0 #337ab7;
    }
    .team-board .tb-col-head {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .team-board .tb-col-head[draggable="true"] {
        cursor: grab;
    }
    .team-board .tb-title {
        flex: 1;
        display: flex;
        align-items: baseline;
        gap: 6px;
        min-width: 0;
    }
    .team-board .tb-team-name {
        font-weight: 600;
        flex: 0 1 auto;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .team-board .tb-team-name a {
        color: inherit;
    }
    .team-board .tb-count {
        flex: 0 0 auto;
        opacity: 0.7;
        font-size: 85%;
    }
    .tb-total {
        display: inline-block;
        font-size: 55%;
        font-weight: 600;
        vertical-align: middle;
        border: 1px solid;
        opacity: 0.6;
        border-radius: 10px;
        padding: 1px 9px;
        margin-left: 8px;
        cursor: default;
    }
    .team-board .tb-sups {
        display: flex;
        align-items: center;
        gap: 3px;
        min-width: 24px;
        min-height: 24px;
        justify-content: flex-end;
        border-radius: 12px;
        padding: 1px;
    }
    .team-board .tb-sup {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        opacity: 0.75;
        overflow: hidden;
    }
    .team-board .tb-sup-empty {
        border: 1px dashed;
        opacity: 0.45;
        cursor: default;
    }
    .team-board .tb-sup[draggable="true"] {
        cursor: grab;
    }
    .team-board .tb-sup.tb-dragging {
        opacity: 0.4;
    }
    .team-board .tb-sup img {
        width: 20px;
        height: 20px;
        border-radius: 50%;
    }
    .team-board .tb-chev {
        display: none;
        opacity: 0.5;
    }
    .team-board .tb-col-menu > .btn {
        padding: 2px 5px;
        opacity: 0.6;
    }
    .team-board .tb-col-menu > .btn:hover {
        opacity: 1;
    }
    .team-board .tb-col-menu .dropdown-menu {
        max-height: 320px;
        overflow-y: auto;
    }
    .team-board .tb-check {
        width: 14px;
        display: inline-block;
    }
    .team-board .tb-col-body {
        padding: 8px;
    }
    .team-board .tb-group {
        border-radius: 4px;
        padding: 2px 4px 4px;
        margin-bottom: 6px;
    }
    .team-board .tb-group.tb-over,
    .team-board .tb-sups.tb-over {
        outline: 2px dashed #337ab7;
        outline-offset: -1px;
    }
    .team-board .tb-hidden {
        display: none !important;
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
    .team-board .tb-card.tb-card-insert-before {
        box-shadow: 0 -3px 0 0 #337ab7;
    }
    .team-board .tb-card.tb-card-insert-after {
        box-shadow: 0 3px 0 0 #337ab7;
    }
    .team-board .tb-lead {
        border-left: 3px solid #337ab7;
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
    .team-board .tb-name a {
        color: inherit;
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
    .tb-remove-zone {
        display: none;
        position: fixed;
        left: 50%;
        bottom: 24px;
        transform: translateX(-50%);
        z-index: 1500;
        align-items: center;
        gap: 8px;
        border: 2px dashed #cf605d;
        border-radius: 6px;
        color: #cf605d;
        background: rgba(255, 255, 255, 0.92);
        padding: 12px 24px;
        font-weight: 600;
        pointer-events: auto;
    }
    .tb-drag-active .tb-remove-zone {
        display: flex;
    }
    .tb-remove-zone.tb-over {
        background: #cf605d;
        color: #fff;
    }
    .tb-ghost {
        position: fixed;
        z-index: 2000;
        pointer-events: none;
        opacity: 0.9;
        margin: 0;
    }
    .tb-hscroll {
        display: none;
        position: fixed;
        bottom: 0;
        height: 14px;
        overflow-x: auto;
        overflow-y: hidden;
        z-index: 1200;
    }
    .tb-hscroll > div {
        height: 1px;
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

<div class="page-header"><h3>{{title}}<span
    class="tb-total"
    title="{{uniqueLabel}}"
>{{totalUnique}}</span></h3></div>

{{#if noTeams}}
    <p class="text-muted">{{noTeamsLabel}}</p>
{{/if}}

<div class="team-board">
    {{#each teams}}
    <div class="tb-col panel panel-default" data-team-id="{{id}}">
        <div class="panel-heading tb-col-head" data-action="toggleColumn" draggable="true">
            <div class="tb-title">
                <span class="tb-team-name"><a
                    href="#Team/view/{{id}}"
                    draggable="false"
                >{{name}}</a></span>
                <span class="tb-count">{{count}}</span>
            </div>
            {{#if hasSupervisorPosition}}
            <div
                class="tb-sups{{#if supervisorsHidden}} tb-hidden{{/if}}"
                data-team-id="{{id}}"
                data-position="Supervisor"
            >
                {{#each supervisors}}
                <a
                    href="#User/view/{{id}}"
                    class="tb-sup"
                    {{#if canDrag}}draggable="true"{{else}}draggable="false"{{/if}}
                    data-user-id="{{id}}"
                    data-team-id="{{teamId}}"
                    data-position="Supervisor"
                    title="{{tooltip}}"
                >{{{avatarHtml}}}</a>
                {{/each}}
                {{#unless hasSupervisors}}
                <span class="tb-sup tb-sup-empty"></span>
                {{/unless}}
            </div>
            {{/if}}
            {{#if ../canManage}}
            <div class="btn-group tb-col-menu">
                <button
                    type="button"
                    class="btn btn-link btn-sm dropdown-toggle"
                    data-toggle="dropdown"
                    title="{{../addMemberLabel}}"
                ><span class="fas fa-user-plus"></span></button>
                <ul class="dropdown-menu pull-right">
                    {{#if hasFreeUsers}}
                    {{#each freeUsers}}
                    <li><a
                        role="button"
                        tabindex="0"
                        data-action="addFreeUser"
                        data-user-id="{{id}}"
                        data-team-id="{{teamId}}"
                        data-position="{{position}}"
                    >{{name}}</a></li>
                    {{/each}}
                    {{else}}
                    <li class="disabled"><a>{{../noFreeUsersLabel}}</a></li>
                    {{/if}}
                </ul>
            </div>
            {{/if}}
            <div class="btn-group tb-col-menu">
                <button
                    type="button"
                    class="btn btn-link btn-sm dropdown-toggle"
                    data-toggle="dropdown"
                    title="{{../settingsLabel}}"
                ><span class="fas fa-ellipsis-v"></span></button>
                <ul class="dropdown-menu pull-right">
                    <li class="dropdown-header">{{../settingsLabel}}</li>
                    {{#each settingsPositions}}
                    <li><a
                        role="button"
                        tabindex="0"
                        data-action="togglePosition"
                        data-team-id="{{teamId}}"
                        data-position="{{value}}"
                    ><span
                        class="far {{#if checked}}fa-check-square{{else}}fa-square{{/if}} tb-check"
                    ></span> {{label}}</a></li>
                    {{/each}}
                </ul>
            </div>
            <span class="tb-chev fas fa-chevron-down"></span>
        </div>
        <div class="tb-col-body">
            {{#each groups}}
            <div
                class="tb-group{{#if isHidden}} tb-hidden{{/if}}"
                data-team-id="{{teamId}}"
                data-position="{{position}}"
            >
                <div class="tb-group-label text-muted">{{label}}</div>
                {{#each members}}
                <div
                    class="tb-card panel panel-default{{#if isTop}} tb-lead{{/if}}"
                    data-user-id="{{id}}"
                    data-team-id="{{teamId}}"
                    data-position="{{position}}"
                    {{#if canManage}}draggable="true"{{/if}}
                >
                    <div class="tb-avatar">{{{avatarHtml}}}</div>
                    <div class="tb-info">
                        <div class="tb-name"><a
                            href="#User/view/{{id}}"
                            draggable="false"
                        >{{name}}</a></div>
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
                                data-position="{{position}}"
                            >→ {{label}}</a></li>
                            {{/each}}
                            {{/if}}
                            {{#if hasMenuAddTeams}}
                            <li class="divider"></li>
                            {{#each menuAddTeams}}
                            <li><a
                                role="button"
                                tabindex="0"
                                data-action="addToTeam"
                                data-user-id="{{userId}}"
                                data-to-team-id="{{toTeamId}}"
                                data-position="{{position}}"
                            >+ {{label}}</a></li>
                            {{/each}}
                            {{/if}}
                            <li class="divider"></li>
                            <li><a
                                role="button"
                                tabindex="0"
                                data-action="removeFromTeam"
                                data-user-id="{{id}}"
                                data-team-id="{{teamId}}"
                            >{{removeLabel}}</a></li>
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

{{#if canManage}}
<div class="tb-remove-zone">
    <span class="fas fa-user-minus"></span>
    <span>{{removeDropLabel}}</span>
</div>
{{/if}}

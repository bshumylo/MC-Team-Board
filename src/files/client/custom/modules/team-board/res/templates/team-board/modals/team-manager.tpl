<style>
    .tb-team-manager .form-group { margin-bottom: 16px; }
    .tb-team-manager .record-container { margin-top: 8px; }
    .tb-team-colour-picker { display: flex; gap: 4px; }
    .tb-colour-dot { width: 18px; height: 18px; padding: 0; border: 2px solid transparent; border-radius: 50%; }
    .tb-colour-dot.is-selected { border-color: var(--text-color, #333); }
    .tb-colour-dot--slate { background: #6b7280; }
    .tb-colour-dot--blue { background: #3b82f6; }
    .tb-colour-dot--sky { background: #0ea5e9; }
    .tb-colour-dot--teal { background: #0d9488; }
    .tb-colour-dot--green { background: #22c55e; }
    .tb-colour-dot--lime { background: #84cc16; }
    .tb-colour-dot--amber { background: #f59e0b; }
    .tb-colour-dot--orange { background: #f97316; }
    .tb-colour-dot--rose { background: #f43f5e; }
    .tb-colour-dot--pink { background: #ec4899; }
    .tb-colour-dot--violet { background: #8b5cf6; }
    .tb-colour-dot--purple { background: #a855f7; }
    .tb-colour-dot--indigo { background: #6366f1; }
</style>
<div class="panel panel-default tb-team-manager">
    <div class="panel-heading"><strong>{{#if formVisible}}{{#if teamId}}{{teamName}}{{else}}{{newTeamLabel}}{{/if}}{{else}}{{manageTeamsLabel}}{{/if}}</strong></div>
    <div class="panel-body">
        {{#if formVisible}}
        {{#unless editingLinked}}<p class="text-muted small">{{boardOnlyDescription}}</p>{{/unless}}
        <div class="record-container no-side-margin">{{{teamRecord}}}</div>
        {{/if}}
    </div>
</div>
<table class="table table-striped">
    <thead><tr><th>{{teamNameLabel}}</th><th>{{colourLabel}}</th></tr></thead>
    <tbody>{{#each teams}}<tr>
        <td><a role="button" data-action="editTeam" data-id="{{boardSquadId}}">{{name}}</a></td>
        <td><div class="tb-team-colour-picker" aria-label="{{../colourLabel}}">{{#each colourOptions}}<button type="button" class="tb-colour-dot tb-colour-dot--{{value}}{{#if selected}} is-selected{{/if}}" data-action="setTeamColour" data-id="{{../boardSquadId}}" data-colour-key="{{value}}" title="{{value}}"></button>{{/each}}</div></td>
    </tr>{{/each}}</tbody>
</table>
<button class="btn btn-default" data-action="showArchive">{{archiveListLabel}}</button>
{{#if archivedTeams}}<table class="table table-condensed"><tbody>{{#each archivedTeams}}<tr><td>{{name}}</td><td><button class="btn btn-link" data-action="restoreTeam" data-id="{{id}}">{{../restoreLabel}}</button></td></tr>{{/each}}</tbody></table>{{/if}}
<p class="text-muted small">{{historyLabel}}</p>

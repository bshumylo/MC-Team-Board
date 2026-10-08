<style>
    /* F2: a long name without spaces wraps inside the header instead of
       pushing past the dialog edge (phone width). */
    .tb-person-dialog .modal-header .modal-title {
        min-width: 0; max-width: 100%; overflow-wrap: anywhere; word-break: break-word;
    }
    .tb-person-dialog .modal-header .modal-title-text { overflow-wrap: anywhere; word-break: break-word; }
    .tb-person-panel { background: var(--panel-bg, #fff); border: 1px solid var(--default-border-color, #e0e2e3); border-radius: 4px; }
    .tb-person-panel .record-container { padding: 15px 15px 0; }
    .tb-person-panel .record-container .panel { border: none; box-shadow: none; margin: 0; background: transparent; }
    .tb-person-panel hr.tb-history-divider { margin: 15px 0 0; border-top: 1px solid var(--default-border-color, #e0e2e3); }
    .tb-person-panel .history-container { padding: 0 15px 15px; }
    .tb-person-panel h4.tb-history-heading { margin: 15px 15px 8px; }
    /* Origin marker: a non-interactive footer item (U23), styled as a plain
       label rather than a disabled button. */
    .modal-footer .btn.tb-origin-marker {
        border: none;
        background: none;
        box-shadow: none;
        opacity: 1;
        color: var(--text-muted-color, #777);
        cursor: default;
        pointer-events: none;
    }
    /* History status cell (Review №25 / T12): unlike a native detail-view
       field, this cell has no `.control-label` before `.field`, so the core
       `.control-label + .field { clear: both; }` rule never applies here.
       Without it the enum field's selectize control paints over the floated
       Update/Cancel icons instead of below them, and a mouse click on either
       icon lands on the control underneath instead of saving or cancelling. */
    .tb-person-panel .history-container .inline-save-link + .inline-edit-link + .field {
        clear: both;
    }
    /* Narrow screen (Review №25 round 3 / T12): the six History columns do
       not fit a phone-width panel, so each row becomes a stacked block with
       the column name beside each value; Status stays reachable without
       scrolling the dialog sideways. */
    @media (max-width: 767px) {
        .tb-person-panel .history-container table,
        .tb-person-panel .history-container tbody,
        .tb-person-panel .history-container tr,
        .tb-person-panel .history-container td { display: block; width: 100%; }
        .tb-person-panel .history-container thead {
            position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0);
        }
        .tb-person-panel .history-container tr { border-top: 1px solid var(--default-border-color, #e0e2e3); padding: 6px 0; }
        .tb-person-panel .history-container table.table > tbody > tr > td {
            /* flow-root contains the label float without clipping the
               status dropdown the way a clipping cell would. */
            display: flow-root; border: none; padding: 2px 0;
        }
        .tb-person-panel .history-container td[data-label]::before {
            content: attr(data-label); float: left; width: 40%; padding-right: 8px;
            color: var(--text-muted-color, #777);
        }
    }
</style>
<div class="tb-person-panel">
    <div class="record-container no-side-margin">{{{personRecord}}}</div>
    <hr class="tb-history-divider">
    <h4 class="tb-history-heading">{{periodsLabel}}</h4>
    <div class="history-container">{{{history}}}</div>
</div>
{{#if saveError}}<p class="text-danger">{{saveError}}</p>{{/if}}

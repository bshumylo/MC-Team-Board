<style>
    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 10px;
    }
    .page-header h3 {
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: 10px;
        margin: 0;
    }
    .team-board {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        overflow-x: visible;
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
    .team-board .tb-team-columns {
        min-width: 0;
        width: 100%;
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
        position: relative;
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
    .tb-total-text {
        color: var(--text-muted-color, #969696);
        font-size: 12px;
        font-weight: 400;
    }
    .team-board .tb-resize-handle {
        position: absolute;
        right: -4px;
        bottom: -4px;
        top: auto;
        width: 14px;
        height: 14px;
        cursor: se-resize;
        z-index: 3;
    }
    .tb-board-menu > .btn {
        width: 32px;
        height: 32px;
        padding: 5px 8px;
    }
    .tb-board-menu .dropdown-menu {
        width: max-content;
        max-width: min(360px, calc(100vw - 20px));
        white-space: normal;
    }
    .tb-board-menu .tb-menu-warning {
        color: var(--brand-warning, #e4a133);
    }
    .tb-timebar {
        overflow: hidden;
        margin-bottom: 12px;
        border: 1px solid var(--panel-default-border, var(--default-border-color));
        border-radius: var(--panel-border-radius, 4px);
        background: var(--panel-bg, transparent);
    }
    .tb-month-strip {
        display: flex;
        overflow-x: auto;
        overscroll-behavior-inline: contain;
        scrollbar-width: thin;
        scroll-behavior: auto;
    }
    .tb-month {
        position: relative;
        flex: 1 0 auto;
        min-width: 86px;
        min-height: 50px;
        padding: 7px 12px 6px;
        border: 0;
        border-right: 1px solid var(--panel-default-border, var(--default-border-color));
        background: transparent;
        color: var(--text-muted-color, #969696);
        text-align: center;
    }
    .tb-month:hover,
    .tb-month:focus {
        background: var(--dropdown-link-hover-bg, rgba(0, 0, 0, .04));
        color: var(--text-color, inherit);
        outline: 0;
    }
    .tb-month.is-active {
        background: var(--brand-primary, #5589ca);
        color: #fff;
    }
    .tb-origin-mode .btn.active {
        background: var(--brand-primary, #5589ca);
        border-color: var(--brand-primary, #5589ca);
        color: #fff;
    }
    .tb-as-of [data-action="goToToday"].is-active {
        background: var(--brand-primary, #5589ca);
        border-color: var(--brand-primary, #5589ca);
        color: #fff;
    }
    .tb-month > span,
    .tb-month > small {
        display: block;
    }
    .tb-month > span {
        font-weight: 600;
        text-transform: capitalize;
    }
    .tb-month > small {
        opacity: .8;
        font-variant-numeric: tabular-nums;
    }
    .tb-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        min-height: 49px;
        padding: 8px 10px;
        border-top: 1px solid var(--panel-default-border, var(--default-border-color));
    }
    .tb-toolbar .btn,
    .tb-toolbar .form-control,
    .tb-date-control,
    .tb-as-of-input,
    .tb-toolbar select.form-control[data-name="status"] {
        height: 36px; /* match the Status select height */
    }
    .tb-as-of {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-left: auto;
    }
    .tb-as-of-label {
        margin: 0;
        font-weight: normal;
        color: var(--text-muted-color, #969696);
    }
    .tb-as-of-input {
        width: 142px;
        height: 30px !important;
        padding: 4px 8px;
        border: 0;
        background: transparent;
        box-shadow: none;
    }
    .tb-date-control {
        display: inline-flex;
        align-items: center;
        border: 1px solid var(--input-border, var(--default-border-color));
        border-radius: var(--border-radius-small, 4px);
        background: var(--input-bg, var(--panel-bg, #fff));
        cursor: pointer;
    }
    .tb-date-control:focus-within {
        border-color: var(--brand-primary, #5589ca);
        box-shadow: 0 0 0 1px var(--brand-primary, #5589ca);
    }
    .tb-density .btn {
        width: 36px;
        padding: 5px 8px;
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
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        opacity: 0.75;
        overflow: visible;
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
    .team-board .tb-sup.tb-sup--pending img,
    .team-board .tb-sup.tb-sup--pending .tb-sup-img,
    .team-board .tb-sup.tb-sup--pending .tb-avatar-fallback {
        opacity: .52;
    }
    .team-board .tb-sup .tb-board-only {
        position: absolute;
        right: -5px;
        bottom: -5px;
        z-index: 2;
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
    .team-board .tb-title {
        flex: 1 1 auto;
        overflow: hidden;
    }
    .team-board .tb-col-head.tb-sup-centred .tb-sups {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
    }
    .team-board .tb-col-head.tb-sup-inline .tb-sups {
        position: static;
        flex: 0 1 auto;
        margin-left: 8px;
        transform: none;
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
        position: relative;
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
    .tb-density {
        flex: 0 0 auto;
    }
    .tb-mode-photos .tb-card-name,
    .tb-mode-photos .tb-card-sub {
        display: none;
    }
    .tb-mode-photos .tb-card > .tb-menu {
        position: absolute;
        z-index: 4;
        top: -8px;
        right: -8px;
        display: block;
        opacity: 0;
        transition: opacity .12s ease;
    }
    .tb-mode-photos .tb-card:hover > .tb-menu,
    .tb-mode-photos .tb-card:focus-within > .tb-menu {
        opacity: 1;
    }
    .tb-mode-photos .tb-group-body {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .tb-mode-photos .tb-card {
        width: var(--tb-avatar-size, 40px);
        height: var(--tb-avatar-size, 40px);
        padding: 0;
        margin: 0;
        border-radius: 50%;
        overflow: visible;
        justify-content: center;
    }
    .tb-mode-photos .tb-avatar,
    .tb-mode-photos .tb-avatar > * {
        width: 100%;
        height: 100%;
    }
    .tb-mode-photos .tb-card img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .team-board.tb-mode-photos {
        display: flex;
        flex-direction: column;
        gap: 12px;
        overflow: visible;
        align-items: stretch;
        min-height: calc(100vh - 250px);
    }
    .tb-mode-photos .tb-team-columns {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(288px, 100%), 1fr));
        gap: 12px;
        align-items: start;
        flex: 0 1 auto;
    }
    .tb-mode-photos .tb-col {
        position: relative;
        grid-column: span var(--tb-column-span, 1);
        height: var(--tb-column-height, auto);
        width: var(--tb-column-width, auto);
        min-width: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
    }
    .tb-mode-photos .tb-col-body {
        padding: 8px;
        text-align: center;
        overflow: auto;
        flex: 1 1 auto;
        min-height: 0;
    }
    .tb-mode-photos .tb-photo-command-group {
        display: none;
    }
    .tb-mode-photos .tb-role-slots {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 8px;
    }
    .tb-mode-photos .tb-role-slot {
        display: flex;
        min-height: calc(var(--tb-avatar-size, 52px) + 24px);
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;
        padding: 3px;
        border: 0;
        background: transparent;
    }
    .tb-mode-photos .tb-sups {
        display: flex;
    }
    .tb-mode-photos .tb-role-slot.tb-over,
    .tb-mode-photos .tb-reserve.tb-over {
        outline: 2px dashed var(--brand-primary, #5589ca);
        outline-offset: -2px;
    }
    .tb-mode-photos .tb-role-label {
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: var(--text-muted-color, #969696);
        font-size: 10px;
        font-weight: 600;
        letter-spacing: .04em;
        text-transform: uppercase;
    }
    .tb-mode-photos .tb-role-slot-members {
        grid-column: 1 / -1;
    }
    /* U16: a lone position 2 or 3 (the other absent or toggled off) is
       centered under the header instead of sitting in the left column. */
    .tb-mode-photos .tb-role-slot-solo {
        grid-column: 1 / -1;
    }
    .tb-mode-photos .tb-role-slot-members .tb-role-members {
        justify-content: center;
    }
    .tb-mode-photos .tb-role-members,
    .tb-mode-photos .tb-reserve-members {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 6px;
    }
    .tb-mode-photos .tb-group-body {
        justify-content: center;
    }
    /* Two classes (0,2,0) needed to beat EspoCRM dark theme's
       `body .panel-default { border-color: var(--panel-default-border); }`
       (0,1,1), which otherwise overrides the border-top color chosen here
       (C05: the card border was rendering as the theme's default grey for
       every colour). */
    /* C05 (review #6, round 2): border-top-color alone left the card's own
       fill unchanged for every colour, so users reported the chosen colour
       "isn't applied to the card". Each rule also sets a low-opacity
       background-color tint of the same hue on the card itself, so the
       chosen colour is visibly on the card, not just a 3px top strip. */
    .tb-col.tb-col--slate { border-top-color: #6b7280; background-color: rgba(107, 114, 128, .08); }
    .tb-col.tb-col--blue { border-top-color: #3b82f6; background-color: rgba(59, 130, 246, .08); }
    .tb-col.tb-col--sky { border-top-color: #0ea5e9; background-color: rgba(14, 165, 233, .08); }
    .tb-col.tb-col--teal { border-top-color: #0d9488; background-color: rgba(13, 148, 136, .08); }
    .tb-col.tb-col--green { border-top-color: #22c55e; background-color: rgba(34, 197, 94, .08); }
    .tb-col.tb-col--lime { border-top-color: #84cc16; background-color: rgba(132, 204, 22, .08); }
    .tb-col.tb-col--amber { border-top-color: #f59e0b; background-color: rgba(245, 158, 11, .08); }
    .tb-col.tb-col--orange { border-top-color: #f97316; background-color: rgba(249, 115, 22, .08); }
    .tb-col.tb-col--rose { border-top-color: #f43f5e; background-color: rgba(244, 63, 94, .08); }
    .tb-col.tb-col--pink { border-top-color: #ec4899; background-color: rgba(236, 72, 153, .08); }
    .tb-col.tb-col--violet { border-top-color: #8b5cf6; background-color: rgba(139, 92, 246, .08); }
    .tb-col.tb-col--purple { border-top-color: #a855f7; background-color: rgba(168, 85, 247, .08); }
    .tb-col.tb-col--indigo { border-top-color: #6366f1; background-color: rgba(99, 102, 241, .08); }
    .tb-col[class*="tb-col--"] { border-top-width: 3px; border-top-style: solid; }
    .tb-mode-photos .tb-role-vacancy {
        display: inline-flex;
        width: var(--tb-avatar-size, 52px);
        height: var(--tb-avatar-size, 52px);
        align-items: center;
        justify-content: center;
        border: 1px dashed var(--default-border-color, #e0e2e3);
        border-radius: 50%;
        background: transparent;
    }
    .tb-mode-photos .tb-role-vacancy > .dropdown-toggle {
        display: inline-flex;
        width: 100%;
        height: 100%;
        align-items: center;
        justify-content: center;
        padding: 0;
        border-radius: 50%;
    }
    .team-board .tb-card .tb-avatar img,
    .team-board .tb-card .tb-avatar > img { border: 0 !important; box-shadow: none !important; }
    .team-board .tb-board-only {
        display: inline-flex;
        width: 18px;
        height: 18px;
        align-items: center;
        justify-content: center;
        flex: 0 0 18px;
        border-radius: 50%;
        background: var(--panel-bg, #fff);
        color: var(--brand-primary, #5589ca);
        font-size: 12px;
        line-height: 1;
    }
    .tb-mode-photos .tb-card {
        position: relative;
        border: 0;
        box-shadow: none;
        overflow: visible;
    }
    .tb-mode-photos .tb-card .tb-avatar {
        overflow: hidden;
        border-radius: 50%;
    }
    .team-board .tb-avatar-fallback {
        display: inline-flex;
        width: 100%;
        height: 100%;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: var(--brand-primary, #5589ca);
        color: #fff;
        font-size: .9em;
        font-weight: 600;
        line-height: 1;
        text-transform: uppercase;
    }
    .team-board .tb-card.tb-card--draft {
        opacity: 1;
        box-shadow: none !important;
    }
    .team-board .tb-card.tb-card--draft .tb-avatar,
    .team-board .tb-card.tb-card--pending .tb-avatar {
        opacity: .52;
    }
    .tb-mode-photos .tb-card.tb-card--draft {
        border-color: transparent;
        box-shadow: none !important;
    }
    .tb-mode-photos .tb-card.tb-card--status .tb-status-marker {
        color: var(--brand-warning, #e4a133);
    }
    .tb-mode-photos .tb-info {
        position: absolute;
        inset: 0;
        z-index: 1;
        min-width: 0;
        cursor: pointer;
    }
    .tb-mode-photos .tb-info:focus-visible {
        outline: 2px solid var(--brand-primary, #5589ca);
        outline-offset: 2px;
        border-radius: 50%;
    }
    .tb-mode-photos .tb-status-marker {
        position: absolute;
        z-index: 2;
        right: -1px;
        top: -1px;
        width: 16px;
        height: 16px;
        padding-top: 1px;
        border-radius: 50%;
        background: var(--panel-bg, #fff);
        color: var(--brand-warning, #e4a133);
        font-size: 10px;
        line-height: 15px;
        text-align: center;
    }
    .tb-mode-photos .tb-board-only { position: absolute; right: -4px; bottom: -4px; z-index: 3; padding: 3px; border-radius: 50%; background: var(--panel-bg, #fff); color: var(--text-muted-color, #777); font-size: 11px; line-height: 1; }
    .tb-mode-photos .tb-reserve {
        grid-column: 1 / -1;
        margin-top: auto;
        padding: 10px;
        border: 1px dashed var(--default-border-color, #e0e2e3);
        border-radius: var(--panel-border-radius, 6px);
        background: var(--panel-bg, transparent);
    }
    .tb-mode-photos .tb-reserve-head {
        display: flex;
        align-items: baseline;
        gap: 6px;
        margin-bottom: 8px;
    }
    .tb-mode-photos .tb-reserve-count {
        color: var(--text-muted-color, #969696);
        font-size: 12px;
    }
    .tb-mode-photos .tb-reserve-empty {
        color: var(--text-muted-color, #969696);
        font-size: 12px;
    }
    .tb-draft-badge {
        margin-left: 4px;
    }
    /* U06 (round 2): the Draft origin «з: A» is visible text under the photo,
       not only a tooltip; the card keeps room below so rows do not overlap.
       Round 3: up to two lines, so a short team name is not cut. */
    .tb-mode-photos .tb-card.tb-card--origin {
        margin-right: 8px;
        margin-bottom: 26px;
        margin-left: 8px;
    }
    .tb-mode-photos .tb-draft-origin-tag {
        position: absolute;
        z-index: 2;
        top: calc(100% + 1px);
        left: 50%;
        transform: translateX(-50%);
        display: -webkit-box;
        width: max-content;
        max-width: calc(var(--tb-avatar-size, 52px) + 20px);
        overflow: hidden;
        color: var(--text-muted-color, #969696);
        font-size: 10px;
        line-height: 12px;
        text-align: center;
        overflow-wrap: anywhere;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        pointer-events: none;
    }
    .team-board .tb-drop {
        border: 1px dashed;
        border-radius: 4px;
        opacity: 0.5;
        text-align: center;
        padding: 8px;
    }
    .tb-mode-photos .tb-group .tb-drop {
        box-sizing: border-box;
        width: var(--tb-avatar-size, 52px);
        height: var(--tb-avatar-size, 52px);
        padding: 0;
        border: 1px dashed var(--default-border-color, #e0e2e3);
        border-radius: 50%;
        opacity: 1;
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
    .tb-drag-active.tb-reserve-hidden .tb-remove-zone {
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
    .tb-drag-preview {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 160px;
        max-width: 280px;
        padding: 8px 12px;
        border: 1px solid var(--default-border-color, #d8d8d8);
        border-radius: var(--border-radius-small, 4px);
        background: var(--panel-bg, #fff);
        box-shadow: 0 6px 18px rgba(0, 0, 0, .2);
    }
    .tb-drag-preview .tb-avatar {
        flex: 0 0 auto;
    }
    .tb-drag-preview .tb-drag-name {
        overflow-wrap: anywhere;
        font-weight: 600;
    }
    .tb-drag-preview-column {
        overflow: hidden;
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
    @media (max-width: 1240px) {
        .tb-mode-photos .tb-team-columns {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        .tb-mode-photos .tb-col[data-column-span="4"] {
            grid-column: span 3;
        }
    }
    @media (max-width: 940px) {
        .tb-mode-photos .tb-team-columns {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .tb-mode-photos .tb-col[data-column-span="3"],
        .tb-mode-photos .tb-col[data-column-span="4"] {
            grid-column: span 2;
        }
    }
    /* U21: the avatar "⋯" menu's team-move / add-to-team rows are a
       compact-viewport substitute for drag-and-drop. On a wide screen the
       move is done by dragging the card, so these rows are hidden — not
       merely deferred to CSS truncation, hence a hard display:none. */
    @media (min-width: 768px) {
        .tb-menu-compact-only {
            display: none !important;
        }
    }
    @media (max-width: 767px) {
        /* EspoCRM treats anything below 768px as a small screen and stacks
           the Home dashlets in one column; the board follows that reference. */
        .tb-mode-photos .tb-team-columns {
            grid-template-columns: 1fr;
            min-width: 0;
            overflow: visible;
        }
        .tb-mode-photos .tb-col {
            width: auto;
        }
        .team-board.tb-mode-photos .tb-col {
            grid-column: span 1;
        }
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
        .team-board.tb-mode-photos .tb-col {
            width: auto;
        }
    }
    @media (max-width: 620px) {
        .tb-mode-photos .tb-team-columns {
            grid-template-columns: 1fr;
            min-width: 0;
            overflow-x: visible;
        }
        .team-board.tb-mode-photos .tb-col {
            grid-column: span 1;
        }
        .tb-timeline-bar {
            gap: 8px;
        }
        .tb-as-of {
            width: 100%;
            margin-left: 0;
        }
    }
</style>

<div class="page-header">
    <h3>
        <span>{{title}}</span>
        <span class="tb-total-text">{{totalUnique}} {{uniqueLabel}}{{#if hasPendingApproval}} <span class="tb-pending-approval-text">({{pendingApprovalLabel}})</span>{{/if}}</span>
    </h3>
    <div class="btn-group tb-board-menu">
        <button
            type="button"
            class="btn btn-default dropdown-toggle"
            data-toggle="dropdown"
            title="{{boardMenuLabel}}"
            aria-label="{{boardMenuLabel}}"
        ><span class="fas fa-ellipsis-v"></span></button>
        <ul class="dropdown-menu pull-right">
            <li><a role="button" tabindex="0" data-action="autoArrange">
                <span class="fas fa-magic fa-fw"></span> {{autoArrangeLabel}}
            </a></li>
            {{#if canManage}}
            <li><a role="button" tabindex="0" data-action="manageTeams">
                <span class="fas fa-users-cog fa-fw"></span> {{manageTeamsLabel}}
            </a></li>
            <li><a href="#TeamBoardMember" data-action="manageUsers">
                <span class="fas fa-user-friends fa-fw"></span> {{manageUsersLabel}}
            </a></li>
            {{/if}}
        </ul>
    </div>
</div>

<div class="tb-timebar">
    <div class="tb-month-strip" role="tablist" aria-label="{{asOfLabel}}">
        {{#each monthTabs}}
        <button
            type="button"
            class="tb-month{{#if active}} is-active{{/if}}"
            data-action="selectMonth"
            data-month="{{value}}"
            role="tab"
            aria-selected="{{#if active}}true{{else}}false{{/if}}"
        ><span>{{label}}</span> <small>{{year}}</small></button>
        {{/each}}
    </div>
    <div class="tb-toolbar">
      <div class="btn-group tb-origin-mode" role="group" aria-label="{{originModeLabel}}">
        <button
            type="button"
            class="btn btn-default btn-xs{{#if isAllOrigins}} active{{/if}}"
            data-action="setOriginMode"
            data-mode="all"
            title="{{allOriginsLabel}}" aria-label="{{allOriginsLabel}}"
        >{{allOriginsLabel}}</button>
        <button
            type="button"
            class="btn btn-default btn-xs{{#if isSystemOnly}} active{{/if}}"
            data-action="setOriginMode"
            data-mode="system"
            title="{{systemOnlyLabel}}" aria-label="{{systemOnlyLabel}}"
        >{{systemOnlyLabel}}</button>
        <button
            type="button"
            class="btn btn-default btn-xs{{#if isBoardOnly}} active{{/if}}"
            data-action="setOriginMode"
            data-mode="board"
            title="{{boardOnlyModeLabel}}" aria-label="{{boardOnlyModeLabel}}"
        >{{boardOnlyModeLabel}}</button>
      </div>
      <div class="tb-as-of">
        <label class="tb-as-of-label" for="tb-as-of-date">{{asOfLabel}}</label>
        <span class="tb-date-control" data-action="openDatePicker" tabindex="0">
            <input
                id="tb-as-of-date"
                type="date"
                class="form-control input-sm tb-as-of-input"
                data-action="setAsOfDate"
                value="{{asOfDate}}"
                min="{{monthMin}}"
                max="{{monthMax}}"
            >
        </span>
        <button type="button" class="btn btn-default{{#if isTodayActive}} is-active{{/if}}" data-action="goToToday">{{todayLabel}}</button>
      </div>
    </div>
</div>

{{#if noTeams}}
    <p class="text-muted">{{noTeamsLabel}}</p>
{{/if}}

{{#if timelineError}}
    <div class="alert alert-danger tb-timeline-error" role="alert">
        <span>{{timelineError}}</span>
        <button type="button" class="btn btn-default btn-xs" data-action="retryTimeline">{{timelineErrorLabel}}</button>
    </div>
{{/if}}

<div
    class="team-board tb-mode-{{viewMode}}"
    style="--tb-card-width: {{cardWidth}}px; --tb-avatar-size: {{avatarSize}}px;"
>
    <div class="tb-team-columns">
    {{#each teams}}
    <div
        class="tb-col tb-col--{{colourKey}} panel panel-default"
        data-team-id="{{id}}"
        data-column-span="{{columnSpan}}"
        data-column-height="{{columnHeight}}"
        data-column-width="{{columnWidth}}"
        style="--tb-column-span: {{columnSpan}}; --tb-column-height: {{columnHeight}}; --tb-column-width: {{columnWidth}};"
    >
        <span class="tb-resize-handle" aria-label="{{../resizeTeamLabel}}"></span>
        <div class="panel-heading tb-col-head" data-action="toggleColumn" draggable="true">
            <div class="tb-title">
                <span class="tb-team-name">
                    {{#if isEspoTeam}}<a
                        href="#Team/view/{{id}}"
                        draggable="false"
                    >{{name}}</a>{{else}}{{#if canArchive}}<a
                        role="button"
                        tabindex="0"
                        data-action="openTeamSettings"
                        data-team-id="{{boardSquadId}}"
                    >{{name}}</a>{{else}}<span>{{name}}</span>{{/if}}{{/if}}
                </span>
                <span class="tb-count">{{count}}</span>
            </div>
            {{#if hasSupervisorPosition}}
            <div
                class="tb-sups{{#if supervisorsHidden}} tb-hidden{{/if}}"
                data-team-id="{{id}}"
                data-position="{{headerPosition}}"
            >
                {{#each supervisors}}
                {{#if isEspoUser}}<a
                    href="#User/view/{{id}}"
                    class="tb-sup{{#if isPending}} tb-sup--pending{{/if}}"
                    {{#if canDrag}}draggable="true"{{else}}draggable="false"{{/if}}
                    data-user-id="{{id}}"
                    data-team-id="{{teamId}}"
                    data-position="{{../headerPosition}}"
                    title="{{tooltip}}"
                >{{{avatarHtml}}}</a>{{else}}<span
                    class="tb-sup{{#if isPending}} tb-sup--pending{{/if}}"
                    data-user-id="{{id}}"
                    data-board-member-id="{{boardMemberId}}"
                    data-team-id="{{teamId}}"
                    data-position="{{../headerPosition}}"
                    title="{{tooltip}}"
                >{{{avatarHtml}}}{{#if showBoardOnly}}<span class="tb-board-only fas fa-unlink" title="{{boardOnlyLabel}}" aria-label="{{boardOnlyLabel}}"></span>{{/if}}</span>{{/if}}
                {{/each}}
                {{#unless hasSupervisors}}
                <span class="tb-sup tb-sup-empty"></span>
                {{/unless}}
            </div>
            {{/if}}
            {{#if ../canManage}}
            <div class="btn-group tb-col-menu tb-menu-compact-only">
                <button
                    type="button"
                    class="btn btn-link btn-sm dropdown-toggle"
                    data-toggle="dropdown"
                    title="{{../addMemberLabel}}"
                ><span class="fas fa-user-plus"></span></button>
                <ul class="dropdown-menu pull-right">
                    {{#if hasReserve}}
                    {{#each reserve}}
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
                    <li class="disabled"><a>{{../reserveDescription}}</a></li>
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
                    {{#if canArchive}}<li class="divider"></li><li><a role="button" tabindex="0" class="text-danger" data-action="archiveTeam" data-team-id="{{boardSquadId}}">{{../archiveTeamLabel}}</a></li>{{/if}}
                </ul>
            </div>
            <span class="tb-chev fas fa-chevron-down"></span>
        </div>
        <div class="tb-col-body">
            {{#if photoRoleSlots}}
            <div class="tb-role-slots" aria-label="{{name}}">
                {{#each photoRoleSlots}}
                <div
                    class="tb-role-slot{{#if isMemberSlot}} tb-role-slot-members{{/if}}{{#if visible}}{{else}} tb-hidden{{/if}}{{#if isVacant}} tb-role-slot-vacant{{/if}}{{#if isSolo}} tb-role-slot-solo{{/if}}"
                    data-team-id="{{teamId}}"
                    data-position="{{position}}"
                    aria-label="{{label}}"
                >
                    <div class="tb-role-label">{{label}}</div>
                    <div class="tb-role-members">
                        {{#each members}}
                        <div
                            class="tb-card panel panel-default tb-photo-avatar{{#if isDraft}} tb-card--draft{{/if}}{{#if isPending}} tb-card--pending{{/if}}{{#if hasStatus}} tb-card--status{{/if}}{{#if draftOriginLabel}} tb-card--origin{{/if}}"
                            data-user-id="{{id}}"
                            data-team-id="{{teamId}}"
                            data-position="{{position}}"
                            {{#if canManage}}draggable="true"{{/if}}
                        >
                            <div class="tb-avatar">{{{avatarHtml}}}</div>
                            {{#if draftOriginLabel}}<span class="tb-draft-origin-tag">{{draftOriginLabel}}</span>{{/if}}
                            {{#if showBoardOnly}}<span class="tb-board-only fas fa-unlink" title="{{boardOnlyLabel}}" aria-label="{{boardOnlyLabel}}"></span>{{/if}}
                            <div
                                class="tb-info"
                                data-action="viewPeriods"
                                data-user-id="{{id}}"
                                data-board-member-id="{{boardMemberId}}"
                                data-user-name="{{name}}"
                                title="{{tooltip}}"
                                role="button"
                                tabindex="0"
                                aria-label="{{accessibleLabel}}"
                            ></div>
                            {{#if canManage}}
                            <div class="btn-group tb-menu">
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm dropdown-toggle"
                                    data-toggle="dropdown"
                                    title="{{name}}"
                                    aria-label="{{name}}"
                                ><span class="fas fa-ellipsis-v"></span></button>
                                <ul class="dropdown-menu pull-right">
                                    <li><a role="button" tabindex="0" data-action="openPerson" data-board-member-id="{{boardMemberId}}">{{name}}</a></li>
                                    <li class="divider"></li>
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
                                    <li class="divider tb-menu-compact-only"></li>
                                    {{#each menuTeams}}
                                    <li class="tb-menu-compact-only"><a
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
                            {{#if hasStatus}}<span class="tb-status-marker fas fa-exclamation-triangle" title="{{statusLabel}}" aria-label="{{statusLabel}}"></span>{{/if}}
                        </div>
                        {{/each}}
                        {{#if isVacant}}
                        {{#if canManage}}
                        <div class="btn-group tb-role-vacancy">
                            <button
                                type="button"
                                class="btn btn-link btn-xs dropdown-toggle"
                                data-toggle="dropdown"
                                aria-label="{{accessibleLabel}}"
                            ><span class="fas fa-user-plus" aria-hidden="true"></span></button>
                            <ul class="dropdown-menu">
                                {{#if hasReserve}}
                                {{#each reserveUsers}}
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
                                 <li class="disabled"><a>{{../../reserveDescription}}</a></li>
                                {{/if}}
                            </ul>
                        </div>
                        {{else}}
                        <div class="tb-role-vacancy" aria-label="{{accessibleLabel}}"></div>
                        {{/if}}
                        {{/if}}
                    </div>
                </div>
                {{/each}}
            </div>
            {{/if}}
            {{#each groups}}
            <div
                class="tb-group{{#if isPhotoRole}} tb-photo-command-group{{/if}}{{#if isHidden}} tb-hidden{{/if}}"
                data-team-id="{{teamId}}"
                data-position="{{position}}"
            >
                <div class="tb-group-label text-muted">{{label}}</div>
                <div class="tb-group-body">
                {{#each members}}
                <div
                    class="tb-card panel panel-default{{#if isTop}} tb-lead{{/if}}{{#if isDraft}} tb-card--draft{{/if}}{{#if isPending}} tb-card--pending{{/if}}{{#if hasStatus}} tb-card--status{{/if}}{{#if draftOriginLabel}} tb-card--origin{{/if}}"
                    data-user-id="{{id}}"
                    data-team-id="{{teamId}}"
                    data-position="{{position}}"
                    {{#if canManage}}draggable="true"{{/if}}
                >
                    <div class="tb-avatar">{{{avatarHtml}}}</div>
                    {{#if draftOriginLabel}}<span class="tb-draft-origin-tag">{{draftOriginLabel}}</span>{{/if}}
                    {{#if showBoardOnly}}<span class="tb-board-only fas fa-unlink" title="{{boardOnlyLabel}}" aria-label="{{boardOnlyLabel}}"></span>{{/if}}
                    <div
                        class="tb-info"
                        data-action="viewPeriods"
                        data-user-id="{{id}}"
                        data-board-member-id="{{boardMemberId}}"
                        data-user-name="{{name}}"
                        title="{{tooltip}}"
                        role="button"
                        tabindex="0"
                        aria-label="{{accessibleLabel}}"
                    >
                        <div class="tb-name tb-card-name">{{#if isEspoUser}}<a
                            href="#User/view/{{id}}"
                            draggable="false"
                        >{{name}}</a>{{else}}{{name}}{{/if}}{{#if isDraft}}<span class="label label-default tb-draft-badge">{{../draftLabel}}</span>{{/if}}</div>
                        <div class="tb-pos tb-card-sub text-muted small">{{positionLabel}}</div>
                        {{#if draftOriginLabel}}<div class="tb-draft-origin text-muted small">{{draftOriginLabel}}</div>{{/if}}
                    </div>
                    {{#if hasStatus}}<span class="tb-status-marker fas fa-exclamation-triangle" title="{{statusLabel}}" aria-label="{{statusLabel}}"></span>{{/if}}
                    {{#if canManage}}
                    <div class="btn-group tb-menu">
                        <button
                            type="button"
                            class="btn btn-link btn-sm dropdown-toggle"
                            data-toggle="dropdown"
                        ><span class="fas fa-ellipsis-v"></span></button>
                        <ul class="dropdown-menu pull-right">
                            <li><a role="button" tabindex="0" data-action="openPerson" data-board-member-id="{{boardMemberId}}">{{name}}</a></li>
                            <li class="divider"></li>
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
                            <li class="divider tb-menu-compact-only"></li>
                            {{#each menuTeams}}
                            <li class="tb-menu-compact-only"><a
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
                <div class="tb-drop text-muted small" aria-label="{{accessibleLabel}}"></div>
                {{/if}}
                </div>
            </div>
            {{/each}}
        </div>
    </div>
    {{/each}}
    </div>
    <section
        class="tb-reserve panel panel-default"
        data-position="__reserve__"
        aria-label="{{reserveLabel}}"
    >
        <div class="tb-reserve-head">
            <strong>{{reserveLabel}}</strong>
            <span class="tb-reserve-count">{{reserveCount}}</span>
            <span class="text-muted small">{{reserveDescription}}</span>
        </div>
        {{#if reserveMembers}}
        <div class="tb-reserve-members">
            {{#each reserveMembers}}
            <div
                class="tb-card panel panel-default tb-photo-avatar{{#if isDraft}} tb-card--draft{{/if}}{{#if isPending}} tb-card--pending{{/if}}{{#if hasStatus}} tb-card--status{{/if}}"
                data-user-id="{{id}}"
                data-team-id="{{teamId}}"
                data-position="{{position}}"
                {{#if canManage}}draggable="true"{{/if}}
            >
                <div class="tb-avatar">{{{avatarHtml}}}</div>
                {{#if showBoardOnly}}<span class="tb-board-only fas fa-unlink" title="{{boardOnlyLabel}}" aria-label="{{boardOnlyLabel}}"></span>{{/if}}
                <div
                    class="tb-info"
                    data-action="viewPeriods"
                    data-user-id="{{id}}"
                    data-board-member-id="{{boardMemberId}}"
                    data-user-name="{{name}}"
                    title="{{tooltip}}"
                    role="button"
                    tabindex="0"
                    aria-label="{{accessibleLabel}}"
                >
                    <div class="tb-name tb-card-name">{{name}}</div>
                    <div class="tb-pos tb-card-sub text-muted small">{{positionLabel}}</div>
                </div>
                {{#if canManage}}
                <div class="btn-group tb-menu">
                    <button
                        type="button"
                        class="btn btn-link btn-sm dropdown-toggle"
                        data-toggle="dropdown"
                    ><span class="fas fa-ellipsis-v"></span></button>
                    <ul class="dropdown-menu pull-right">
                        <li><a role="button" tabindex="0" data-action="openPerson" data-board-member-id="{{boardMemberId}}">{{name}}</a></li>
                        {{#if hasMenuTeams}}
                        <li class="divider tb-menu-compact-only"></li>
                        {{#each menuTeams}}
                        <li class="tb-menu-compact-only"><a
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
                    </ul>
                </div>
                {{/if}}
                {{#if hasStatus}}<span class="tb-status-marker fas fa-exclamation-triangle" title="{{statusLabel}}" aria-label="{{statusLabel}}"></span>{{/if}}
            </div>
            {{/each}}
        </div>
        {{else}}
        <div class="tb-reserve-empty">{{reserveDescription}}</div>
        {{/if}}
    </section>
</div>

{{#if canManage}}
<div class="tb-remove-zone">
    <span class="fas fa-user-minus"></span>
    <span>{{removeDropLabel}}</span>
</div>
{{/if}}

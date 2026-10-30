import EnumFieldView from 'views/fields/enum';

/**
 * C05 (Q5 №7): the colour of a board team, chosen from a row of colour dots —
 * the same dots as the «Manage teams» list — without colour names.
 *
 * It stays the native enum field of the team record view: the chosen key is
 * held in one hidden input that the native fetch() reads, so validation, the
 * model update on change and the unsaved-changes check of the dialog work as
 * for any other field. The dot styles come from the team manager template.
 */
class ColourKeyFieldView extends EnumFieldView {

    // The hidden input is the main element; it is not turned into a selectize.
    nativeSelect = true

    validationElementSelector = '.tb-team-colour-picker'

    editTemplateContent = `
        <input type="hidden" data-name="{{name}}" value="{{value}}">
        <div class="tb-team-colour-picker tb-team-colour-field" aria-label="{{labelText}}">
            {{#each colourOptions}}<button type="button" class="tb-colour-dot tb-colour-dot--{{value}}{{#if selected}} is-selected{{/if}}" data-colour-key="{{value}}" aria-label="{{label}}" aria-pressed="{{#if selected}}true{{else}}false{{/if}}"></button>{{/each}}
        </div>
    `

    detailTemplateContent = `
        <div class="tb-team-colour-picker tb-team-colour-field" aria-label="{{labelText}}">
            {{#each colourOptions}}<button type="button" class="tb-colour-dot tb-colour-dot--{{value}}{{#if selected}} is-selected{{/if}}" aria-label="{{label}}" aria-pressed="{{#if selected}}true{{else}}false{{/if}}" disabled></button>{{/each}}
        </div>
    `

    setup() {
        super.setup();

        this.addHandler('click', '[data-colour-key]', (e, target) => this.selectColour(target.dataset.colourKey));
    }

    data() {
        const data = super.data();
        const value = this.model.get(this.name);

        data.labelText = this.getLabelText();
        data.colourOptions = (this.params.options || []).map(colourKey => ({
            value: colourKey,
            // C05: the accessible name is the colour name in the user's language.
            label: this.getLanguage().translateOption(colourKey, this.name, this.entityType),
            selected: colourKey === value,
        }));

        return data;
    }

    selectColour(colourKey) {
        if (!this.isEditMode() || !(this.params.options || []).includes(colourKey)) {
            return;
        }

        this.$element.val(colourKey);

        this.element.querySelectorAll('[data-colour-key]').forEach(dot => {
            const selected = dot.dataset.colourKey === colourKey;

            dot.classList.toggle('is-selected', selected);
            dot.setAttribute('aria-pressed', String(selected));
        });

        // The native field change: the model takes fetch() and the record view
        // marks itself as changed.
        this.trigger('change');
    }
}

export default ColourKeyFieldView;

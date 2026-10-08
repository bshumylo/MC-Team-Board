import VarcharFieldView from 'views/fields/varchar';

/**
 * P13/P01: the First Name row link of «Manage users». It shows only the real
 * first name, read dynamically from CRM (never the last name), so the visible
 * first-name sort matches the data. A CRM user without a first name (Admin)
 * gets a blank cell instead of «None»; the person stays identifiable through
 * the Last Name row link (also in the narrow `listSmall` layout). Only when
 * both names are empty the link shows the display name.
 */
class PersonFirstNameFieldView extends VarcharFieldView {

    listLinkTemplateContent = `<a
        href="#{{scope}}/view/{{model.id}}"
        class="link"
        data-id="{{model.id}}"
        title="{{value}}"
    >{{value}}</a>`

    getValueForDisplay() {
        const value = super.getValueForDisplay();

        if (value || this.mode !== this.MODE_LIST_LINK) {
            return value;
        }

        if (this.model.get('personLastName')) {
            return '';
        }

        const fallback = this.model.get('userId') ?
            this.model.get('userName') :
            this.model.get('name');

        return fallback || '';
    }
}

export default PersonFirstNameFieldView;

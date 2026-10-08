import MessageNotificationItemView from 'views/notification/items/message';

/**
 * O10 Draft reminder. The native message item view renders it, including the
 * {entity} anchor built with .text(entityName); only the text template is
 * translated here, in the viewer's current UI language, instead of the
 * language stored when the job created the notification.
 */
class DraftReminderNotificationItemView extends MessageNotificationItemView {
    setup() {
        const label = 'draftReminderMessage';
        const text = this.translate(label, 'messages', 'TeamBoard');
        if (typeof text === 'string' && text !== label && text.includes('{person}')) {
            this.model.set({message: text.split('{person}').join('{entity}')}, {silent: true});
        }
        super.setup();
    }
}

export default DraftReminderNotificationItemView;

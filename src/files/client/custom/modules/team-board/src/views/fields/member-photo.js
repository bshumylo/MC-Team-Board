import ImageFieldView from 'views/fields/image';

/**
 * The board photo of a person.
 *
 * The markup, the attach control, the preview, the remove control, the
 * validation messages and the read mode are the native EspoCRM image field,
 * the same field the system User form uses for an avatar.
 *
 * A chosen file goes through the same crop dialog as the system avatar, so it
 * reaches the board as a square `avatar.jpg` of type `image/jpeg` — the one
 * shape the board photo endpoint accepts. Without it an ordinary picture
 * (`Screenshot 2026-09-21 at 10.29.15.png`, a HEIC photo, an oversized
 * original) was rejected by the server and adding a photo simply failed.
 *
 * Only the transport differs from the native field. A board member is not
 * writable through the Record API by design, so the generic Attachment upload
 * is refused for it. The cropped file therefore stays in the field until the
 * dialog is saved and the board endpoint applies it with the rest of the form.
 */
class MemberPhotoFieldView extends ImageFieldView {

    setup() {
        super.setup();

        // A file chosen in the dialog has no attachment id before the board
        // applies it. This placeholder lets the native preview, attachment box
        // and remove control treat it like a stored attachment.
        this.pendingId = `tb-pending-${this.cid}`;
        this.pendingFile = null;
        this.pendingUrl = null;

        this.storedId = this.model.get(this.idName) || null;
        // A person linked to an EspoCRM user falls back to the system avatar,
        // exactly as the User form renders it.
        this.userId = this.options.userId || null;
        this.systemAvatarId = this.options.systemAvatarId || null;

        // views/user/fields/avatar: a replaced picture keeps the same entry
        // point, so the read mode is refreshed past the browser cache.
        this.on('after:inline-save', () => {
            this.suspendCache = true;

            this.reRender();
        });

        // The native remove control clears the id. Whatever the field held for
        // the next save is released with it, without depending on which
        // internal method did the clearing.
        this.listenTo(this.model, `change:${this.idName}`, () => {
            if (this.model.get(this.idName) !== this.pendingId) {
                this.releasePending();
            }
        });

        this.on('remove', () => this.releasePending());
    }

    /**
     * The file waiting for the next save, if any.
     *
     * @return {?File}
     */
    getPendingFile() {
        return this.pendingFile;
    }

    /**
     * Whether the stored photo was removed in the form and not replaced.
     *
     * @return {boolean}
     */
    isRemovalStaged() {
        return !!this.storedId && !this.model.get(this.idName);
    }

    /**
     * Save this field alone, the way the system avatar is saved from the
     * `User` detail form.
     *
     * The native field asks its record helper, whose record view then patches
     * the record. A board photo does not travel with the member record, so the
     * dialog hands the field the one call that applies it and the rest of the
     * native inline-edit lifecycle is kept: the editor closes, `saving` and
     * `Saved` are reported, and a refused request puts the field back.
     *
     * @param {{bypassClose?: boolean}} [options]
     */
    inlineEditSave(options = {}) {
        const apply = this.options.applyPhoto;
        const initialAttributes = Object.assign({}, this.initialAttributes);

        if (!apply || (!this.getPendingFile() && !this.isRemovalStaged())) {
            this.inlineEditClose(true);

            return;
        }

        if (!options.bypassClose) {
            this.inlineEditClose(true);
        }

        Espo.Ui.notify(this.translate('saving', 'messages'));

        apply()
            .then(() => {
                Espo.Ui.success(this.translate('Saved'));

                this.trigger('after:inline-save');
                this.trigger('after:save');
            })
            .catch(error => {
                this.releasePending();
                this.model.setMultiple(initialAttributes, {silent: true});
                this.reRender();

                Espo.Ui.error(
                    error && error.message ? error.message : this.translate('Error occurred')
                );
            });
    }

    /**
     * Accept the state the board confirmed, so a reopened form starts from it.
     *
     * @param {?string} photoId
     */
    applyStoredId(photoId) {
        this.releasePending();
        this.storedId = photoId || null;

        const attributes = {};
        attributes[this.idName] = this.storedId;

        if (!this.storedId) {
            attributes[this.nameName] = null;
        }

        this.model.setMultiple(attributes);
    }

    releasePending() {
        if (this.pendingUrl) {
            URL.revokeObjectURL(this.pendingUrl);
        }

        this.pendingFile = null;
        this.pendingUrl = null;
    }

    // The native size check, message and crop step are kept; only the upload
    // itself is deferred to the board save.
    uploadFile(file) {
        const maxFileSize = this.getMaxFileSize();

        if (maxFileSize && file.size > maxFileSize * 1024 * 1024) {
            const message = this.translate('fieldMaxFileSizeError', 'messages')
                .replace('{field}', this.getLabelText())
                .replace('{max}', maxFileSize.toString());

            this.showValidationMessage(message, '.attachment-button label');

            return;
        }

        return this.handleUploadingFile(file)
            .then(prepared => this.stagePendingFile(prepared))
            // A cancelled crop leaves the field as it was.
            .catch(() => {});
    }

    /**
     * The crop dialog of the system avatar, with its square ratio and its
     * JPEG result. The board photo endpoint accepts exactly that name and
     * type, so this step is what makes an arbitrary picture uploadable.
     *
     * @param {File} file
     * @return {Promise<File>}
     */
    handleUploadingFile(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();

            reader.onload = event => {
                this.createView('crop', 'views/modals/image-crop', {contents: event.target.result})
                    .then(view => {
                        view.render();

                        let cropped = false;

                        this.listenToOnce(view, 'crop', dataUrl => {
                            cropped = true;

                            fetch(dataUrl)
                                .then(result => result.blob())
                                .then(blob => resolve(
                                    new File([blob], 'avatar.jpg', {type: 'image/jpeg'})
                                ))
                                .catch(reject);
                        });

                        this.listenToOnce(view, 'remove', () => {
                            if (!cropped) {
                                setTimeout(() => this.reRender(), 10);

                                reject();
                            }

                            this.clearView('crop');
                        });
                    })
                    .catch(reject);
            };

            reader.onerror = reject;

            reader.readAsDataURL(file);
        });
    }

    /**
     * Hold the cropped file for the next dialog save and show it the way the
     * native field shows a stored attachment.
     *
     * @param {File} file
     */
    stagePendingFile(file) {
        this.releasePending();

        this.pendingFile = file;
        this.pendingUrl = URL.createObjectURL(file);

        const attributes = {};
        attributes[this.idName] = this.pendingId;
        attributes[this.nameName] = file.name;
        attributes[this.typeName] = file.type;

        this.model.setMultiple(attributes, {ui: true});

        this.reRender();
    }

    // The placeholder has no entry point, so the preview reads the local file.
    getImageUrl(id, size) {
        if (id === this.pendingId && this.pendingUrl) {
            return this.pendingUrl;
        }

        return super.getImageUrl(id, size);
    }

    getDetailPreview(name, type, id) {
        if (id !== this.pendingId || !this.pendingUrl) {
            return super.getDetailPreview(name, type, id);
        }

        return $('<img>')
            .attr('src', this.pendingUrl)
            .attr('alt', name)
            .addClass('image-preview')
            .get(0).outerHTML;
    }

    // Nothing was uploaded yet, so removing the pending file must not try to
    // destroy an attachment that does not exist.
    deleteAttachment() {
        const attributes = {};
        attributes[this.idName] = null;
        attributes[this.nameName] = null;

        this.model.setMultiple(attributes);

        this.$el.find('div.attachment').empty();
    }

    /**
     * The read mode of `views/user/fields/avatar`: one round image, not the
     * attachment block the generic image field draws.
     *
     * The generic field wraps its preview in
     * `.attachment-block-container > .attachment-block > .attachment-preview`,
     * which is what made the board photo a square tile. The avatar field
     * bypasses that with a bare image, and the core rule `img.avatar` gives it
     * the 50% radius — the same class every round avatar in EspoCRM uses, so
     * the dialog still carries no stylesheet of its own.
     */
    getValueForDisplay() {
        if (!this.isReadMode()) {
            return '';
        }

        const id = this.model.get(this.idName);
        const source = this.avatarSource(id);

        if (!source) {
            return '';
        }

        const sizes = this.getMetadata().get(['app', 'image', 'sizes']) || {};
        const size = sizes[this.previewSize] || [];

        const $img = $('<img>')
            .attr('src', source.url)
            .attr('alt', this.getLabelText())
            .attr('draggable', 'false')
            .addClass('avatar')
            .css({maxWidth: size[0], maxHeight: size[1]});

        if (!source.previewId) {
            return $img.get(0).outerHTML;
        }

        return $('<a>')
            .attr('data-id', source.previewId)
            .attr('data-action', 'showImagePreview')
            .attr('title', this.getLabelText())
            .attr('href', `${this.getBasePath()}?entryPoint=image&id=${source.previewId}`)
            .append($img)
            .get(0).outerHTML;
    }

    /**
     * Where the read mode takes its image from: the file chosen in this form,
     * the stored board photo, or the CRM profile of a linked user.
     *
     * @param {?string} id
     * @return {?{url: string, previewId: ?string}}
     */
    avatarSource(id) {
        if (id === this.pendingId && this.pendingUrl) {
            return {url: this.pendingUrl, previewId: null};
        }

        const timestamp = this.cacheTimestamp = this.suspendCache
            ? Date.now()
            : (this.cacheTimestamp || Date.now());

        if (id) {
            return {
                url: `${this.getBasePath()}?entryPoint=image&size=${this.previewSize}` +
                    `&id=${id}&t=${timestamp}`,
                previewId: id,
            };
        }

        if (!this.userId) {
            return null;
        }

        // A linked person falls back to the CRM profile picture, from the very
        // entry point the system User form reads.
        return {
            url: `${this.getBasePath()}?entryPoint=avatar&size=${this.previewSize}` +
                `&id=${this.userId}&t=${timestamp}` +
                `&attachmentId=${this.systemAvatarId || 'false'}`,
            previewId: this.systemAvatarId || null,
        };
    }
}

export default MemberPhotoFieldView;

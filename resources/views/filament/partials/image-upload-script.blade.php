{{--
    Alpine component "ogImageUpload" of the ImageUpload field
    (resources/views/filament/forms/image-upload.blade.php).

    Uses the same contract as Filament's own upload widget: $wire.upload into
    "{statePath}.{key}", removeUploadedFile / getUploadedFiles on the field,
    and the form-processing events that keep Save disabled during an upload.
    A replaced image only leaves the form once the new one has arrived, so a
    failed upload keeps it. Stored files change on Save, as before.

    Inline (like theme-styles) so it works without rebuilding the frontend.
--}}
<script>
    (() => {
        const typesByExtension = {
            gif: 'image/gif',
            ico: 'image/x-icon',
            jpeg: 'image/jpeg',
            jpg: 'image/jpeg',
            png: 'image/png',
            svg: 'image/svg+xml',
            webp: 'image/webp',
        }

        const newKey = () => ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(/[018]/g, (c) =>
            (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16))

        const imageUpload = ({ state, statePath, files, accepted, maxKb, locale, isDisabled, title, formatNames, chip, uploadingMessage, messages }) => ({
            state,
            form: null,
            current: null,
            savedFile: null,
            savedState: '[]',
            hasChanges: false,
            upload: null,
            error: '',
            isDragging: false,
            previewFailed: false,
            dimensions: '',

            init() {
                this.form = this.$el.closest('form')
                this.adopt(files)
                this.$watch('state', () => this.followOutsideChange())
            },

            destroy() {
                if (this.upload) {
                    this.cancel()
                }

                if (this.current?.isLocal) {
                    URL.revokeObjectURL(this.current.url)
                }
            },

            get hasImage() {
                return Boolean(this.upload || this.current)
            },

            get previewUrl() {
                return (this.upload ?? this.current)?.url ?? null
            },

            get isRemoved() {
                return ! this.hasImage && Boolean(this.savedFile)
            },

            get isUnsaved() {
                return ! this.upload && Boolean(this.current) && this.hasChanges
            },

            get displayName() {
                const file = this.upload ?? this.current

                if (! file) {
                    return ''
                }

                // Stored files have a random name, so they are described by their format.
                const format = formatNames[file.type]

                return file.clientName ?? (format ? messages.imageOfType.replace(':type', format) : messages.image)
            },

            get detail() {
                if (this.upload) {
                    return messages.uploading.replace(':percent', this.upload.progress)
                }

                if (! this.current) {
                    return ''
                }

                const size = this.formatSize(this.current.size)

                return this.dimensions && ! this.isUnsaved
                    ? [size, this.dimensions].filter(Boolean).join(' · ')
                    : size
            },

            get chipText() {
                if (! chip) {
                    return ''
                }

                const name = chip.path ? this.$wire.$get(chip.path) : null

                return chip.text.replace(':name', name || chip.fallback)
            },

            formatSize(bytes) {
                if (! bytes) {
                    return ''
                }

                const isMegabytes = bytes >= 1024 * 1024
                const value = bytes / (isMegabytes ? 1024 * 1024 : 1024)
                const number = new Intl.NumberFormat(locale, { maximumFractionDigits: value < 10 ? 1 : 0 }).format(value)

                return `${number} ${isMegabytes ? 'MB' : 'KB'}`
            },

            measure(image) {
                const type = (this.upload ?? this.current)?.type ?? ''

                this.dimensions = image.naturalWidth && ! type.includes('svg')
                    ? `${image.naturalWidth} × ${image.naturalHeight} px`
                    : ''
            },

            show(file) {
                const previous = this.current

                if (previous?.url !== file?.url) {
                    this.previewFailed = false
                    this.dimensions = ''

                    if (previous?.isLocal) {
                        URL.revokeObjectURL(previous.url)
                    }
                }

                this.current = file
            },

            adopt(fileMap, clientName = null) {
                const entry = Object.entries(fileMap ?? {}).find(([, file]) => file)
                const file = entry ? { key: entry[0], ...entry[1], clientName } : null

                this.show(file)
                this.savedFile = file
                this.savedState = JSON.stringify(this.state ?? [])
                this.hasChanges = false
            },

            async reload(clientName = null) {
                this.adopt(await this.$callSchemaComponentMethod('getUploadedFiles', {}, { async: true }), clientName)
            },

            followOutsideChange() {
                if (this.upload) {
                    return
                }

                const keys = Object.keys(this.state ?? {})

                if (keys.some((key) => String(this.state[key]).startsWith('livewire-file:'))) {
                    return
                }

                const known = this.current ? [this.current.key] : []

                if (keys.length !== known.length || keys.some((key) => ! known.includes(key))) {
                    this.reload()
                }
            },

            async saved(detail) {
                if (detail?.statePath !== statePath || this.upload) {
                    return
                }

                await this.$nextTick()
                await this.reload(this.current?.clientName ?? null)
            },

            isAccepted(file) {
                if (! accepted.length) {
                    return true
                }

                // Some systems report no type for .ico files.
                const type = file.type || typesByExtension[file.name.split('.').pop().toLowerCase()] || ''
                const isIcon = ['image/x-icon', 'image/vnd.microsoft.icon'].includes(type)

                return accepted.some((allowed) => allowed === type
                    || (allowed.endsWith('/*') && type.startsWith(allowed.slice(0, -1)))
                    || (isIcon && ['image/x-icon', 'image/vnd.microsoft.icon'].includes(allowed)))
            },

            pick(fileList) {
                const file = fileList?.[0]

                this.$refs.input.value = ''

                if (! file || isDisabled || this.upload) {
                    return
                }

                if (! this.isAccepted(file)) {
                    this.error = messages.wrongType

                    return
                }

                if (maxKb && file.size > maxKb * 1024) {
                    this.error = messages.tooLarge

                    return
                }

                this.error = ''
                this.start(file)
            },

            start(file) {
                const key = newKey()

                this.previewFailed = false
                this.dimensions = ''
                this.upload = { key, clientName: file.name, size: file.size, type: file.type, url: URL.createObjectURL(file), progress: 0, isFinishing: false }
                this.formEvent('form-processing-started', { message: uploadingMessage })

                this.$wire.upload(
                    `${statePath}.${key}`,
                    file,
                    () => this.finish(key),
                    () => this.fail(key),
                    (event) => {
                        if (this.upload?.key === key) {
                            this.upload.progress = event.detail.progress
                        }
                    },
                )
            },

            async finish(key) {
                if (this.upload?.key !== key) {
                    return
                }

                this.upload.isFinishing = true

                try {
                    if (this.current) {
                        await this.$removeUploadedFile({ fileKey: this.current.key })
                    }
                } finally {
                    const { key: fileKey, clientName, size, type, url } = this.upload

                    this.upload = null
                    this.show({ key: fileKey, clientName, size, type, url, isLocal: true })
                    this.hasChanges = true
                    this.formEvent('form-processing-finished')
                }
            },

            fail(key) {
                if (this.upload?.key !== key) {
                    return
                }

                URL.revokeObjectURL(this.upload.url)
                this.upload = null
                this.previewFailed = false
                this.dimensions = ''
                this.error = messages.failed
                this.formEvent('form-processing-finished')
            },

            cancel() {
                if (! this.upload || this.upload.isFinishing) {
                    return
                }

                const { key, url } = this.upload

                this.upload = null
                this.previewFailed = false
                this.dimensions = ''
                URL.revokeObjectURL(url)
                this.$wire.cancelUpload(`${statePath}.${key}`)
                this.formEvent('form-processing-finished')
            },

            async remove() {
                const file = this.current

                if (! file || this.upload) {
                    return
                }

                this.error = ''
                this.show(null)
                this.hasChanges = Boolean(this.savedFile)
                await this.$removeUploadedFile({ fileKey: file.key })
            },

            undo() {
                this.state = JSON.parse(this.savedState)
                this.show(this.savedFile)
                this.hasChanges = false
                this.error = ''
            },

            dragOver() {
                if (! isDisabled && ! this.upload) {
                    this.isDragging = true
                }
            },

            dragLeave(event) {
                if (! event.currentTarget.contains(event.relatedTarget)) {
                    this.isDragging = false
                }
            },

            dropped(event) {
                this.isDragging = false
                this.pick(event.dataTransfer?.files)
            },

            pasted(event) {
                if (event.clipboardData?.files?.length) {
                    event.preventDefault()
                    this.pick(event.clipboardData.files)
                }
            },

            formEvent(name, detail = {}) {
                this.form?.dispatchEvent(new CustomEvent(name, { composed: true, cancelable: true, detail }))
            },
        })

        const register = () => window.Alpine.data('ogImageUpload', imageUpload)
        window.Alpine ? register() : document.addEventListener('alpine:init', register)
    })()
</script>

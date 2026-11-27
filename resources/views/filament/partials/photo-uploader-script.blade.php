{{--
    Alpine component "ogPhotoUploader" for the media picker's drop zone
    (resources/views/livewire/media-picker.blade.php).

    Uploads any number of photos in chunks to App\Http\Controllers\PhotoUploadController:
    a few files at a time, each in pieces small enough for PHP's upload limits.
    Failed chunks are retried with growing pauses; after a network error the
    server reports what it already has and the upload resumes from there.
    Finished photos are handed to the picker ($wire.addUploaded) every few
    seconds. If that fails they are kept and sent again. The grid stays
    still meanwhile: at the end it is redrawn once ($wire.showUploaded) and
    the new photos join whatever the person already selected (select()).
    Selecting earlier would move the grid, because Filament's "selected" bar
    appears above it.

    Inline (like theme-styles) so it works without rebuilding the frontend.
    Texts come translated from PhotoUploads::uploaderConfig() (config.messages);
    t() fills in their :placeholders.
--}}
<script data-navigate-once>
    (() => {
        /** Files uploaded at the same time; each turns into a photo on the server, which takes memory. */
        const PARALLEL_FILES = 2
        const MAX_ATTEMPTS = 6
        const FLUSH_INTERVAL = 4000
        const FLUSH_ATTEMPTS = 3

        /** UUID v4 that also works outside secure contexts (crypto.randomUUID needs HTTPS). */
        const uuid = () =>
            '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
                (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16),
            )

        const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

        const formatBytes = (bytes) => {
            const units = ['B', 'KB', 'MB', 'GB', 'TB']
            let unit = 0
            while (bytes >= 1024 && unit < units.length - 1) {
                bytes /= 1024
                unit++
            }

            return `${bytes.toFixed(unit < 2 ? 0 : 1)} ${units[unit]}`
        }

        const photoUploader = (config) => {
            const t = (key, replace = {}) =>
                Object.entries(replace).reduce((text, [name, value]) => text.replaceAll(`:${name}`, value), config.messages[key] ?? key)
            const plural = (count, one, many) => t(count === 1 ? one : many, { count })

            return {
            dragging: false,
            running: false,
            queue: [],
            active: [],
            total: 0,
            finished: 0,
            totalBytes: 0,
            doneBytes: 0,
            problems: [],
            problemCount: 0,
            notice: null,
            stopMessage: null,
            batch: null,
            controller: null,
            pendingIds: [],
            selectableIds: [],
            flushing: false,
            flushTimer: null,
            flushFailures: 0,
            lastFlush: 0,

            init() {
                // A failed grid update is tried again here; no error dialog over the running upload.
                this.stopErrorDialogs = ['addUploaded', 'showUploaded'].map((name) =>
                    this.$wire?.$intercept?.(name, ({ onError }) => onError(({ preventDefault }) => preventDefault())),
                )

                this.onUnload = (event) => {
                    if (this.running) {
                        event.preventDefault()
                        event.returnValue = ''
                    }
                }
                window.addEventListener('beforeunload', this.onUnload)

                // Admin pages change without a reload (SPA mode), which would end the upload without asking.
                this.onNavigate = (event) => {
                    if (this.running && ! window.confirm(t('leave'))) {
                        event.preventDefault()
                    }
                }
                document.addEventListener('livewire:navigate', this.onNavigate)
            },

            destroy() {
                window.removeEventListener('beforeunload', this.onUnload)
                document.removeEventListener('livewire:navigate', this.onNavigate)
                this.stopErrorDialogs.forEach((stop) => stop?.())
                this.destroyed = true
                clearTimeout(this.flushTimer)
                this.queue = []
                this.controller?.abort()
            },

            async drop(event) {
                this.dragging = false
                const entries = [...(event.dataTransfer?.items ?? [])]
                    .map((item) => item.webkitGetAsEntry?.())
                    .filter(Boolean)

                if (!entries.length) {
                    this.add(event.dataTransfer?.files ?? [])

                    return
                }

                // Folders: collect every file inside, at any depth.
                const files = []
                for (const entry of entries) {
                    await this.walk(entry, files)
                }
                this.add(files)
            },

            async walk(entry, files) {
                if (entry.isFile) {
                    const file = await new Promise((resolve) => entry.file(resolve, () => resolve(null)))
                    if (file) {
                        files.push(file)
                    }

                    return
                }

                if (!entry.isDirectory) {
                    return
                }

                const reader = entry.createReader()
                let children
                do {
                    // readEntries returns at most ~100 entries per call.
                    children = await new Promise((resolve) => reader.readEntries(resolve, () => resolve([])))
                    for (const child of children) {
                        await this.walk(child, files)
                    }
                } while (children.length)
            },

            add(fileList) {
                let files = [...fileList].filter((file) => file && !file.name.startsWith('.'))

                if (!config.multiple) {
                    if (this.running) {
                        return
                    }
                    files = files.slice(0, 1)
                }

                if (!this.running) {
                    this.total = this.finished = this.totalBytes = this.doneBytes = 0
                    this.problems = []
                    this.problemCount = 0
                    this.notice = null
                    this.stopMessage = null
                }

                for (const file of files) {
                    const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : ''

                    if (!config.extensions.includes(extension)) {
                        this.problem(file.name, t('unsupported'))
                    } else if (file.size > config.maxSize) {
                        this.problem(file.name, t('tooLarge', { size: formatBytes(config.maxSize) }))
                    } else if (file.size === 0) {
                        this.problem(file.name, t('empty'))
                    } else {
                        this.queue.push(file)
                        this.total++
                        this.totalBytes += file.size
                    }
                }

                this.start()
            },

            async start() {
                if (this.running || !this.queue.length) {
                    return
                }

                this.running = true
                this.batch = uuid()
                this.controller = new AbortController()

                const workers = config.multiple ? PARALLEL_FILES : 1
                await Promise.all(Array.from({ length: workers }, () => this.work()))

                this.running = false
                this.active = []
                await this.showFinished()

                this.notice = [
                    plural(this.finished, 'uploadedOne', 'uploadedMany'),
                    this.stopMessage,
                ]
                    .filter(Boolean)
                    .join(' ')
            },

            async work() {
                while (this.queue.length && !this.controller.signal.aborted) {
                    const file = this.queue.shift()
                    this.active.push({ name: file.name, size: file.size, sent: 0 })
                    const slot = this.active[this.active.length - 1]

                    try {
                        this.pendingIds.push(await this.send(file, slot))
                        this.finished++
                        this.flush()
                    } catch (error) {
                        // After Cancel (or a stop) the notice says how many were left out.
                        if (!this.controller.signal.aborted) {
                            this.problem(file.name, error.message)
                        }
                    } finally {
                        this.doneBytes += file.size
                        this.active = this.active.filter((other) => other !== slot)
                    }
                }
            },

            /** Uploads one file chunk by chunk; resolves with the new photo's id. */
            async send(file, slot) {
                const upload = uuid()
                let offset = 0
                let attempt = 0

                while (true) {
                    const body = new FormData()
                    body.append('upload', upload)
                    body.append('batch', this.batch)
                    body.append('target', config.target)
                    body.append('name', file.name)
                    body.append('size', file.size)
                    body.append('offset', offset)
                    body.append('chunk', file.slice(offset, offset + config.chunkSize), 'chunk')

                    let response = null
                    try {
                        response = await fetch(config.url, {
                            method: 'POST',
                            body,
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': config.csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            signal: this.controller.signal,
                        })
                    } catch (error) {
                        if (this.controller.signal.aborted) {
                            throw error
                        }
                    }

                    const data = response ? await response.json().catch(() => ({})) : {}

                    if (response?.ok && data.done) {
                        slot.sent = file.size

                        return data.photo
                    }

                    if ((response?.ok || response?.status === 409) && Number.isInteger(data.received)) {
                        // Next chunk, or resume from what the server already has.
                        if (response.status === 409 && ++attempt > MAX_ATTEMPTS) {
                            throw new Error(t('notResumed'))
                        }
                        if (response.ok) {
                            attempt = 0
                        }
                        offset = data.received
                        slot.sent = offset
                        continue
                    }

                    if (response && [401, 403, 419].includes(response.status)) {
                        this.stop(
                            response.status === 419
                                ? t('sessionExpired')
                                : t('notAllowed'),
                        )
                        throw new Error(t('notUploaded'))
                    }

                    if (response?.status === 422) {
                        throw new Error(Object.values(data.errors ?? {})[0]?.[0] ?? data.message ?? t('notAnImage'))
                    }

                    if (response?.status === 413) {
                        throw new Error(t('chunkTooLarge'))
                    }

                    if (++attempt > MAX_ATTEMPTS) {
                        throw new Error(t('failed'))
                    }

                    await sleep(Math.min(30000, 1000 * 2 ** attempt))
                }
            },

            cancel() {
                this.stop(null)
            },

            /** Stops everything; files not uploaded yet are listed. */
            stop(message) {
                const left = this.queue.length + this.active.length
                this.queue = []
                this.controller?.abort()
                this.stopMessage = [message, left ? plural(left, 'leftOne', 'leftMany') : null]
                    .filter(Boolean)
                    .join(' ')
            },

            problem(name, message) {
                this.problemCount++
                // Keep the list short; the count says how many there are.
                if (this.problems.length < 50) {
                    this.problems.push({ name, message })
                }
            },

            /** Hands finished photos to the picker, at most every few seconds. */
            async flush(now = false) {
                // On the site there is no picker to hand photos to.
                if (!this.$wire) {
                    this.pendingIds = []

                    return
                }

                if (this.flushing || !this.pendingIds.length) {
                    return
                }

                const wait = FLUSH_INTERVAL - (Date.now() - this.lastFlush)
                if (!now && wait > 0) {
                    this.flushTimer ??= setTimeout(() => {
                        this.flushTimer = null
                        this.flush()
                    }, wait)

                    return
                }

                this.flushing = true
                const ids = this.pendingIds.splice(0)
                let failed = false
                try {
                    const selectable = await this.$wire.addUploaded(ids)
                    if (!Array.isArray(selectable)) {
                        throw new Error('No answer')
                    }
                    this.selectableIds.push(...selectable)
                    this.flushFailures = 0
                } catch (error) {
                    // Keep them: the next try selects them too.
                    failed = true
                    this.flushFailures++
                    this.pendingIds.unshift(...ids)
                } finally {
                    this.flushing = false
                    this.lastFlush = Date.now()
                }

                if (failed) {
                    // After a few tries they wait for the next finished photo.
                    if (this.flushFailures >= FLUSH_ATTEMPTS || this.destroyed) {
                        return
                    }
                    await sleep(FLUSH_INTERVAL)
                }

                if (this.pendingIds.length && !this.destroyed) {
                    await this.flush(now)
                }
            },

            /** After a run: hands over the last photos, then redraws the picker's grid once. */
            async showFinished() {
                while (this.flushing) {
                    await sleep(50)
                }
                await this.flush(true)

                if (!this.$wire || !this.finished) {
                    return
                }

                while (this.flushing) {
                    await sleep(50)
                }

                for (let attempt = 0; attempt < FLUSH_ATTEMPTS && !this.running && !this.destroyed; attempt++) {
                    try {
                        await this.$wire.showUploaded()

                        break
                    } catch (error) {
                        await sleep(FLUSH_INTERVAL)
                    }
                }

                this.select(this.selectableIds.splice(0))
            },

            /** Adds photos to the selection of the picker's grid (Filament's table component). */
            select(ids) {
                const table = this.$root.closest('.og-picker')?.querySelector('.fi-ta')
                const grid = table ? window.Alpine.$data(table) : null
                if (!ids?.length || typeof grid?.selectRecords !== 'function') {
                    return
                }

                grid.selectRecords(ids.map(String))
            },

            percent() {
                const sent = this.doneBytes + this.active.reduce((sum, slot) => sum + slot.sent, 0)

                return this.totalBytes ? Math.min(100, Math.round((sent / this.totalBytes) * 100)) : 0
            },

            statusText() {
                const sent = this.doneBytes + this.active.reduce((sum, slot) => sum + slot.sent, 0)

                return t('status', {
                    current: Math.min(this.finished + 1, this.total),
                    total: this.total,
                    sent: formatBytes(sent),
                    size: formatBytes(this.totalBytes),
                })
            },
            }
        }

        const register = () => window.Alpine.data('ogPhotoUploader', photoUploader)
        window.Alpine ? register() : document.addEventListener('alpine:init', register)
    })()
</script>

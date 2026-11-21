{{--
    Keeps every row of the admin's photo grids complete (media library, an
    album's Photos section, the picker, chosen photos).

    CSS can only wrap photos in order, which leaves a short last row and, on
    phones, single photos. This splits the photos of a page into the rows
    closest to the grid's row height (--row) for the current width, and gives
    each tile its share as --og-basis. Photos keep their shape and order.
    Only when all photos together would have to grow far too large to fill
    one row do they keep the normal row height.

    Livewire removes styles the server did not render, so the observer puts
    them back before the browser paints. Inline (like theme-styles) so it
    works without rebuilding the frontend.
--}}
<script>
    (() => {
        if (window.ogJustifiedRows) {
            return
        }

        window.ogJustifiedRows = true

        const GRIDS = '.fi-ta-content-grid, .og-mfield-grid'
        const TILES = ':scope > .fi-ta-record, :scope > .og-mfield-thumb'
        const MAX_STRETCH = 1.75
        const MIN_SHRINK = 0.5
        const LONE_PHOTO_PENALTY = 1

        const watchedGrids = new WeakSet()
        let isQueued = false

        const splitIntoRows = (ratios, width, gap, rowHeight) => {
            const best = [{ cost: 0, start: 0 }]

            for (let end = 1; end <= ratios.length; end++) {
                best[end] = { cost: Infinity, start: end - 1 }
                let ratioSum = 0

                for (let start = end - 1; start >= 0; start--) {
                    ratioSum += ratios[start]
                    const count = end - start
                    const height = (width - gap * (count - 1)) / ratioSum

                    if (count > 1 && height < rowHeight * MIN_SHRINK) {
                        break
                    }

                    const isLone = count === 1 && ratios[start] * rowHeight < width * 0.75
                    const cost = best[start].cost + ((height - rowHeight) / rowHeight) ** 2 + (isLone ? LONE_PHOTO_PENALTY : 0)

                    if (cost < best[end].cost) {
                        best[end] = { cost, start }
                    }
                }
            }

            const rows = []

            for (let end = ratios.length; end > 0; end = best[end].start) {
                rows.unshift({ start: best[end].start, end })
            }

            return rows
        }

        const setProperty = (element, name, value) => {
            if (element.style.getPropertyValue(name) !== value) {
                element.style.setProperty(name, value)
            }
        }

        const layoutGrid = (grid) => {
            if (! grid.querySelector('.og-pick.is-compact, .og-mfield-thumb')) {
                return
            }

            if (! watchedGrids.has(grid)) {
                watchedGrids.add(grid)
                resizeObserver.observe(grid)
            }

            if (grid.querySelector('.sortable-chosen, .fi-sortable-ghost')) {
                return
            }

            const styles = getComputedStyle(grid)
            const width = grid.clientWidth - parseFloat(styles.paddingLeft) - parseFloat(styles.paddingRight)
            const tiles = [...grid.querySelectorAll(TILES)]

            if (width <= 0 || ! tiles.length) {
                return
            }

            const gap = parseFloat(styles.columnGap) || 0
            const rowHeight = parseFloat(styles.getPropertyValue('--row')) || 140
            const ratios = tiles.map((tile) => parseFloat(getComputedStyle(tile).getPropertyValue('--r')) || 1.5)
            const rows = splitIntoRows(ratios, width, gap, rowHeight)
            let keepsRowHeight = false

            rows.forEach(({ start, end }) => {
                const ratioSum = ratios.slice(start, end).reduce((sum, ratio) => sum + ratio, 0)
                let height = (width - gap * (end - start - 1)) / ratioSum

                if (rows.length === 1 && height > rowHeight * MAX_STRETCH) {
                    height = rowHeight
                    keepsRowHeight = true
                }

                for (let index = start; index < end; index++) {
                    // A pixel under the exact share, so rounding can never push a photo to the next row.
                    setProperty(tiles[index], '--og-basis', `${Math.max(1, ratios[index] * height - 1).toFixed(2)}px`)
                }
            })

            setProperty(grid, '--og-row-spacer', keepsRowHeight ? 'block' : 'none')
        }

        const layoutAll = () => document.querySelectorAll(GRIDS).forEach(layoutGrid)

        const queueLayout = () => {
            if (isQueued) {
                return
            }

            isQueued = true
            queueMicrotask(() => {
                isQueued = false
                layoutAll()
            })
        }

        const resizeObserver = new ResizeObserver(queueLayout)

        const touchesGrid = (mutation) => {
            if (mutation.type === 'attributes') {
                return mutation.target.matches(`${GRIDS}, .fi-ta-record, .og-mfield-thumb`)
            }

            return mutation.target.closest?.(GRIDS)
                || [...mutation.addedNodes].some((node) => node.nodeType === 1 && (node.matches(GRIDS) || node.querySelector(GRIDS)))
        }

        new MutationObserver((mutations) => {
            if (mutations.some(touchesGrid)) {
                queueLayout()
            }
        }).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['style', 'class'] })

        // Dragging pauses the layout; pick it up again once the photo is dropped.
        ;['pointerup', 'touchend', 'dragend'].forEach((type) => document.addEventListener(type, () => requestAnimationFrame(layoutAll), { passive: true }))

        layoutAll()
    })()
</script>

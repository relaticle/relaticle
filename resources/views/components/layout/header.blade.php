{{-- The mobile overlay is `fixed inset-0`, which does not stop the page behind it
     from scrolling, and does not stop Tab from walking into the page behind it
     either. `bodyLock` handles the first, `trapTab` the second.

     The focusable set is queried on every keypress rather than cached: the
     accordions add and remove links as they open, so a set captured on open goes
     stale the moment someone expands Product. Hand-rolled because @alpinejs/focus
     is not a dependency of this project. --}}
<header x-data="{
            mobileMenu: false,
            {{-- One accordion open at a time; reset on every open so the menu
                 always starts collapsed rather than remembering the last visit. --}}
            mobileExpanded: null,
            bodyLock() { document.body.style.overflow = this.mobileMenu ? 'hidden' : '' },
            focusables() {
                return [...$refs.overlay.querySelectorAll('a[href], button:not([disabled])')]
                    .filter((el) => el.checkVisibility())
            },
            trapTab(event) {
                const items = this.focusables()

                if (items.length === 0) {
                    return
                }

                const edge = event.shiftKey ? items[0] : items[items.length - 1]

                if (document.activeElement !== edge) {
                    return
                }

                event.preventDefault()
                ;(event.shiftKey ? items[items.length - 1] : items[0]).focus()
            },
        }"
        x-effect="bodyLock()"
        x-init="$watch('mobileMenu', (open) => { if (open) { mobileExpanded = null } else { $refs.hamburger.focus() } })"
        @resize.window="if (window.innerWidth >= 768) mobileMenu = false">
    <div
        id="main-header"
        class="fixed w-full top-0 z-50 bg-white/80 dark:bg-gray-950/90 backdrop-blur-md border-b border-gray-200/60 dark:border-white/[0.06]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">

                <div class="flex flex-1 items-center">
                    <a href="{{ url('/') }}" class="focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary rounded-sm" aria-label="Relaticle Home">
                        <x-brand.logo-lockup size="md" class="text-black dark:text-white" />
                    </a>
                </div>

                @php($navItems = app(\App\Support\MarketingNavigation::class)->header())
                @php($dropdownItems = collect($navItems)->filter(fn ($item) => $item->url === null && count($item->children) > 0)->values())
                @php($dropdownSlugs = $dropdownItems->map(fn ($item) => \Illuminate\Support\Str::slug($item->label))->values())
                @php($twoColumnBySlug = $dropdownItems->mapWithKeys(fn ($item, $index) => [
                    $dropdownSlugs[$index] => collect($item->children)->contains(fn ($child) => $child->url === null && count($child->children) > 0),
                ]))

                <nav aria-label="{{ __('Main') }}" class="relative hidden md:flex items-center gap-1"
                     x-data="{
                         openDropdown: null,
                         // The dropdown a swap is animating away from. Kept mounted (and shown)
                         // alongside `openDropdown` for one transition window so the outgoing
                         // content can slide out while the incoming one slides in — a real
                         // two-layer swipe, not a single element re-skinning itself.
                         outgoingDropdown: null,
                         direction: 1,
                         closeTimer: null,
                         lastPanelStyle: '',
                         swapTimer: null,
                         order: {{ Illuminate\Support\Js::from($dropdownSlugs) }},
                         twoColumn: {{ Illuminate\Support\Js::from($twoColumnBySlug) }},
                         openOnHover(slug) {
                             clearTimeout(this.closeTimer)
                             // Switching between triggers while one is already open is instant —
                             // only the very first open of a hover session gets the settle delay,
                             // so a fast pass across the bar doesn't flicker every panel it crosses.
                             if (this.openDropdown) { this.swapTo(slug); return }
                             this.hoverTimer = setTimeout(() => { this.swapTo(slug) }, 90)
                         },
                         cancelHoverOpen() { clearTimeout(this.hoverTimer) },
                         closeOnHoverLeave(slug) {
                             this.closeTimer = setTimeout(() => {
                                 if (this.openDropdown === slug) { this.closeAll() }
                             }, 200)
                         },
                         // A full close (not a swap) still needs its content to fade out WITH
                         // the card rather than vanishing the instant openDropdown clears — so
                         // it borrows the same outgoingDropdown mechanism a swap uses, just with
                         // no incoming layer to slide past.
                         closeAll() {
                             clearTimeout(this.swapTimer)
                             clearTimeout(this.heightTimer)
                             if (this.$refs.panel) { this.$refs.panel.style.height = '' }
                             // outgoingDropdown is set BEFORE openDropdown clears, so
                             // `openDropdown ?? outgoingDropdown` is never null-null on the same
                             // tick — a gap there sends panelStyle() an unresolvable slug, which
                             // drops the inline width/height and collapses the card to a stray dot.
                             this.outgoingDropdown = this.openDropdown
                             this.openDropdown = null
                             // Alpine's own x-show leave transition on the card (driven purely by
                             // openDropdown, not outgoingDropdown) already runs and finishes on
                             // its own. Writing to outgoingDropdown again afterwards was enough to
                             // make Alpine re-run that transition's bookkeeping and re-show the
                             // card at a collapsed size — so only clear it if a NEW open hasn't
                             // already claimed it (swapTo re-purposes the same field).
                             const closingSlug = this.outgoingDropdown
                             this.swapTimer = setTimeout(() => {
                                 if (this.outgoingDropdown === closingSlug) { this.outgoingDropdown = null }
                             }, 220)
                         },
                         // Sets the sweep direction before switching content, so both layers
                         // that are about to render already know which way to move.
                         swapTo(slug) {
                             if (slug === this.openDropdown) { return }
                             clearTimeout(this.swapTimer)
                             const wasOpen = this.openDropdown
                             // Alpine flushes DOM changes async, so this still reads the
                             // pre-swap height even after the assignments below.
                             const priorHeight = wasOpen ? this.$refs.panel.offsetHeight : 0
                             this.direction = this.order.indexOf(slug) >= this.order.indexOf(wasOpen ?? slug) ? 1 : -1
                             this.outgoingDropdown = wasOpen
                             this.openDropdown = slug
                             // The outgoing layer only needs to stay mounted for the swap
                             // animation itself — once it's done sliding out, drop it so it
                             // stops occupying space/receiving events.
                             if (wasOpen) {
                                 this.morphHeight(priorHeight)
                                 this.swapTimer = setTimeout(() => { this.outgoingDropdown = null }, 220)
                             }
                         },
                         // Left/width morph via the transition class; height is auto (content
                         // driven) and CSS cannot animate to auto, so it gets a FLIP: pin the
                         // old height, force a layout, pin the new one, release when done.
                         morphHeight(priorHeight) {
                             this.$nextTick(() => {
                                 const panel = this.$refs.panel
                                 panel.style.height = ''
                                 const target = panel.offsetHeight
                                 if (target === priorHeight) { return }
                                 panel.style.height = priorHeight + 'px'
                                 void panel.offsetHeight
                                 panel.style.height = target + 'px'
                                 clearTimeout(this.heightTimer)
                                 this.heightTimer = setTimeout(() => { panel.style.height = '' }, 210)
                             })
                         },
                         toggle(slug) {
                             if (this.openDropdown === slug) { this.closeAll(); return }
                             this.swapTo(slug)
                         },
                         panelWidth(slug) {
                             return this.twoColumn[slug] ? Math.min(640, window.innerWidth - 32) : 384
                         },
                         panelStyle(slug) {
                             // Falls back to the last resolved position instead of an empty
                             // string: an empty style drops the inline width, and with no
                             // content driving height either (mid-close, mid-swap), the card
                             // collapses to a stray dot for a frame instead of holding still.
                             const trigger = this.$refs['trigger-' + slug]
                             if (!trigger) { return this.lastPanelStyle }
                             const nav = trigger.closest('nav').getBoundingClientRect()
                             const rect = trigger.getBoundingClientRect()
                             const width = this.panelWidth(slug)
                             let left = (rect.left - nav.left) + rect.width / 2 - width / 2
                             left = Math.max(0, Math.min(left, nav.width - width))
                             this.lastPanelStyle = `left:${left}px;width:${width}px`
                             return this.lastPanelStyle
                         },
                     }"
                     @keydown.escape.window="closeAll()"
                     @click.outside="closeAll()">
                    @foreach($navItems as $item)
                        @if($item->url === null && count($item->children) > 0)
                            @php($slug = \Illuminate\Support\Str::slug($item->label))
                            <div @keydown.escape="if (openDropdown === '{{ $slug }}') { closeAll(); $refs['trigger-{{ $slug }}'].focus(); }"
                                 @mouseenter="openOnHover('{{ $slug }}')"
                                 @mouseleave="cancelHoverOpen(); closeOnHoverLeave('{{ $slug }}')">
                                <button type="button" x-ref="trigger-{{ $slug }}"
                                        @click="toggle('{{ $slug }}')"
                                        aria-haspopup="true"
                                        :aria-expanded="openDropdown === '{{ $slug }}'"
                                        aria-controls="menu-{{ $slug }}"
                                        class="px-4 py-1.5 rounded-full text-gray-600 dark:text-gray-400 hover:text-gray-900 hover:bg-gray-100 dark:hover:text-white dark:hover:bg-white/[0.08] text-[13px] font-medium transition-colors duration-200 flex items-center gap-1 cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                        :class="openDropdown === '{{ $slug }}' && 'text-gray-900 bg-gray-100 dark:text-white dark:bg-white/[0.08]'">
                                    {{ $item->label }}
                                    <x-ri-arrow-down-s-line class="w-3.5 h-3.5 transition-transform duration-200"
                                                             ::class="openDropdown === '{{ $slug }}' && 'rotate-180'"/>
                                </button>
                            </div>
                        @else
                            <a href="{{ $item->url }}"
                               @if($item->external) target="_blank" rel="noopener noreferrer" @endif
                               @if(url()->current() === $item->url) aria-current="page" @endif
                               class="group px-4 py-1.5 rounded-full text-gray-600 dark:text-gray-400 hover:text-gray-900 hover:bg-gray-100 dark:hover:text-white dark:hover:bg-white/[0.08] text-[13px] font-medium transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary @if($item->external) flex items-center gap-1.5 @endif">
                                @if($item->external)
                                    <x-ri-discord-fill class="w-4 h-4"/>
                                    <span>{{ $item->label }}</span>
                                    <x-ri-arrow-right-up-line class="h-3 w-3 text-gray-400 dark:text-gray-500 transition-colors duration-200 group-hover:text-gray-600 dark:group-hover:text-gray-300"/>
                                @else
                                    {{ $item->label }}
                                @endif
                            </a>
                        @endif
                    @endforeach

                    {{-- One shared floating card: it repositions/resizes under whichever
                         trigger is active (panelStyle), while its content swipes left or right
                         on a swap. During a swap BOTH the outgoing and incoming item's content
                         are mounted at once (stacked with absolute inset-0), each carrying its
                         own slide-out/slide-in — a real two-layer swipe, not one element
                         re-skinning itself. --}}
                    <div x-show="openDropdown !== null"
                         x-transition:enter="transition-[translate,scale,opacity] duration-250 ease-[var(--ease-out-expo)] motion-reduce:transition-none"
                         x-transition:enter-start="opacity-0 -translate-y-1 scale-[0.97]"
                         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                         x-transition:leave="transition-[translate,scale,opacity] duration-150 ease-out motion-reduce:transition-none"
                         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                         x-transition:leave-end="opacity-0 -translate-y-1 scale-[0.97]"
                         x-cloak
                         {{-- Only recomputes while a dropdown is genuinely open. Once
                              openDropdown clears, this stops re-evaluating — leaving it wired to
                              outgoingDropdown too meant the later `outgoingDropdown = null` write
                              (well after the close transition finished) re-ran this binding and
                              overwrote Alpine's own `display:none` with a bare left/width string,
                              silently re-showing the card at a collapsed size. --}}
                         x-bind:style="openDropdown ? panelStyle(openDropdown) : lastPanelStyle"
                         {{-- The card morphs (left/width) only while a swap is in flight — both
                              slugs set. On a first open or a close this class is absent, so the
                              morph never fights Alpine's own enter/leave transition classes. --}}
                         x-bind:class="openDropdown && outgoingDropdown ? 'transition-[left,width,height] duration-200 ease-[var(--ease-out-expo)] motion-reduce:transition-none' : ''"
                         x-ref="panel"
                         @mouseenter="cancelHoverOpen(); clearTimeout(closeTimer)"
                         @mouseleave="closeOnHoverLeave(openDropdown)"
                         class="absolute top-full mt-2 origin-top rounded-2xl border border-gray-200/70 dark:border-white/[0.08] bg-white dark:bg-gray-900 shadow-xl shadow-gray-950/[0.08] dark:shadow-black/50 overflow-hidden">
                        <div class="relative">
                            @foreach($dropdownItems as $item)
                                @php($slug = $dropdownSlugs[$loop->index])
                                @php($isTwoColumn = collect($item->children)->contains(fn ($child) => $child->url === null && count($child->children) > 0))
                                {{-- `current` renders in normal flow so the card measures its
                                     height. During a SWAP the outgoing layer is absolutely
                                     stacked on top of it so both slide past each other at once.
                                     During a full CLOSE the outgoing layer must stay in normal
                                     flow untouched: pulling it absolute collapses the card to a
                                     border-only sliver mid-fade (a visible "line" under the nav),
                                     and fading it separately just double-fades what the card's
                                     own leave transition already fades. --}}
                                <template x-if="openDropdown === '{{ $slug }}' || outgoingDropdown === '{{ $slug }}'">
                                    <div x-data="{
                                             get isCurrent() { return openDropdown === '{{ $slug }}' },
                                             get isLeaving() { return outgoingDropdown === '{{ $slug }}' && openDropdown !== '{{ $slug }}' },
                                             settled: false,
                                         }"
                                         x-effect="if (isCurrent) {
                                             if (!outgoingDropdown) {
                                                 // Fresh open (nothing to swipe past): show at once
                                                 // and let the card's enter transition do the work.
                                                 settled = true
                                             } else if (!settled) {
                                                 // Forces the browser to commit the off-position
                                                 // layout before the class flips back, otherwise
                                                 // both changes land in one paint and never animate.
                                                 void $el.offsetHeight
                                                 setTimeout(() => { settled = true }, 20)
                                             }
                                         }"
                                         id="menu-{{ $slug }}"
                                         {{-- Each layer is pinned to ITS panel's natural width, so
                                              the card's left/width morph only ever clips it — the
                                              content never re-wraps mid-swap, and the card's auto
                                              height reads the incoming layer's final height from
                                              the first frame. --}}
                                         x-bind:style="`width:${panelWidth('{{ $slug }}')}px`"
                                         class="p-2 transition-[translate,opacity] duration-200 ease-[var(--ease-out-expo)] motion-reduce:transition-none @if($isTwoColumn) grid grid-cols-[1.35fr_1fr] divide-x divide-gray-100 dark:divide-white/[0.06] @endif"
                                         :class="isLeaving
                                             ? (openDropdown ? 'absolute top-0 left-0 bg-white dark:bg-gray-900 ' + (direction > 0 ? '-translate-x-3 opacity-0' : 'translate-x-3 opacity-0') : '')
                                             : (settled ? 'translate-x-0 opacity-100' : (direction > 0 ? 'translate-x-3 opacity-0' : '-translate-x-3 opacity-0'))">
                                        @foreach($item->children as $child)
                                            @if($child->url === null && count($child->children) > 0)
                                                <div class="px-2 first:pl-0 last:pr-0">
                                                    <p class="px-3 pt-1 pb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                                        {{ $child->label }}
                                                    </p>
                                                    <div class="space-y-0.5">
                                                        @foreach($child->children as $grandchild)
                                                            <x-layout.mega-menu-link :item="$grandchild"/>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @else
                                                <x-layout.mega-menu-link :item="$child"/>
                                            @endif
                                        @endforeach
                                    </div>
                                </template>
                            @endforeach
                        </div>
                    </div>
                </nav>

                <div class="flex flex-1 items-center justify-end gap-2 sm:gap-3">
                    <div class="hidden md:flex items-center gap-2">
                        <x-marketing.button variant="secondary" size="sm" href="{{ route('login') }}">
                            Sign In
                        </x-marketing.button>

                        <x-marketing.button size="sm" href="{{ route('login') }}">
                            Start for free
                        </x-marketing.button>
                    </div>

                    {{-- Closing returns focus here, so dismissing the overlay puts a
                         keyboard user back on the control they opened it with. Driven by
                         the watcher in x-init, not a second escape listener: the overlay
                         already has one on `window`, and two handlers on one event race. --}}
                    {{-- The overlay's close button renders this same three-line
                         construct in its end state at the same screen position, so
                         the overlay fading in over the mid-morph lines reads as one
                         continuous hamburger-to-cross motion, not two different
                         icons crossfading. --}}
                    <button @click="mobileMenu = !mobileMenu"
                            x-ref="hamburger"
                            class="md:hidden p-2.5 -mr-1 text-gray-600 dark:text-gray-400 hover:text-gray-900 hover:bg-gray-100 dark:hover:text-white dark:hover:bg-white/[0.08] rounded-lg transition-colors duration-200 cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                            :aria-expanded="mobileMenu"
                            :aria-label="mobileMenu ? 'Close menu' : 'Open menu'">
                        <span class="relative block h-5 w-5" aria-hidden="true">
                            <span class="absolute left-0 top-[3px] h-[2px] w-5 rounded-full bg-current transition-[translate,rotate] duration-300 ease-[var(--ease-out-expo)] motion-reduce:transition-none"
                                  :class="mobileMenu && 'translate-y-[6px] rotate-45'"></span>
                            <span class="absolute left-0 top-[9px] h-[2px] w-5 rounded-full bg-current transition-[opacity,scale] duration-200 motion-reduce:transition-none"
                                  :class="mobileMenu && 'opacity-0 scale-x-50'"></span>
                            <span class="absolute left-0 top-[15px] h-[2px] w-5 rounded-full bg-current transition-[translate,rotate] duration-300 ease-[var(--ease-out-expo)] motion-reduce:transition-none"
                                  :class="mobileMenu && '-translate-y-[6px] -rotate-45'"></span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <x-layout.mobile-menu/>
</header>

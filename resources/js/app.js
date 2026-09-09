import Alpine from 'alpinejs';

const FOCUSABLE = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function trapFocus(container, event) {
    if (event.key !== 'Tab' || ! (container instanceof HTMLElement)) {
        return;
    }

    const focusable = Array.from(container.querySelectorAll(FOCUSABLE))
        .filter((element) => ! element.hasAttribute('disabled') && element.getAttribute('aria-hidden') !== 'true');

    if (focusable.length === 0) {
        event.preventDefault();

        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (! event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function restoreFocus(element) {
    if (element instanceof HTMLElement) {
        element.focus();
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('primaryNav', () => ({
        openMenu: null,
        mobileOpen: false,
        mobileAccordion: null,
        closeTimer: null,
        previouslyFocused: null,

        open(slug) {
            clearTimeout(this.closeTimer);
            this.openMenu = slug;
        },

        toggle(slug) {
            clearTimeout(this.closeTimer);
            this.openMenu = this.openMenu === slug ? null : slug;
        },

        keepOpen() {
            clearTimeout(this.closeTimer);
        },

        scheduleClose() {
            clearTimeout(this.closeTimer);
            this.closeTimer = setTimeout(() => {
                this.openMenu = null;
            }, 150);
        },

        closeDesktop() {
            clearTimeout(this.closeTimer);
            this.openMenu = null;
        },

        closeMobile() {
            this.mobileOpen = false;
            this.mobileAccordion = null;
        },

        closeAll() {
            this.closeDesktop();
            this.closeMobile();
        },

        toggleMobileAccordion(slug) {
            this.mobileAccordion = this.mobileAccordion === slug ? null : slug;
        },

        focusFirstLink(slug) {
            this.$nextTick(() => {
                document.getElementById('mega-menu-' + slug)?.querySelector('a')?.focus();
            });
        },

        syncMobile(open) {
            document.body.classList.toggle('overflow-hidden', open);

            if (open) {
                this.previouslyFocused = document.activeElement;
                this.$nextTick(() => this.$refs.mobileClose?.focus());
            } else if (this.previouslyFocused instanceof HTMLElement) {
                restoreFocus(this.previouslyFocused);
                this.previouslyFocused = null;
            }
        },

        trapMobile(event) {
            if (! this.mobileOpen) {
                return;
            }

            trapFocus(this.$refs.mobilePanel, event);
        },

        init() {
            this._mq = window.matchMedia('(min-width: 1024px)');
            this._onBreakpoint = () => this.closeAll();
            this._mq.addEventListener('change', this._onBreakpoint);
        },

        destroy() {
            this._mq?.removeEventListener('change', this._onBreakpoint);
            document.body.classList.remove('overflow-hidden');
        },
    }));

    window.Alpine.data('filterDrawer', () => ({
        previouslyFocused: null,

        sync(open) {
            document.body.classList.toggle('overflow-hidden', open);

            if (open) {
                this.previouslyFocused = document.activeElement;
                this.$nextTick(() => this.$refs.close?.focus());
            } else if (this.previouslyFocused instanceof HTMLElement) {
                restoreFocus(this.previouslyFocused);
                this.previouslyFocused = null;
            }
        },

        trap(event) {
            trapFocus(this.$refs.panel, event);
        },
    }));

    window.Alpine.data('loadMoreGifts', () => ({
        pending: false,

        init() {
            this.$el.addEventListener('click', () => {
                this.pending = true;
            });

            if (! window.Livewire?.hook) {
                return;
            }

            Livewire.hook('request', ({ succeed, fail }) => {
                succeed(() => {
                    this.pending = false;
                });

                fail(() => {
                    if (! this.pending) {
                        return;
                    }

                    this.pending = false;
                    this.$wire?.markLoadMoreFailed();
                });
            });
        },
    }));
});

if (! window.Livewire) {
    window.Alpine = Alpine;
    Alpine.start();
}

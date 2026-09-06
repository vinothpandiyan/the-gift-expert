import Alpine from 'alpinejs';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('primaryNav', () => ({
        openMenu: null,
        mobileOpen: false,
        mobileAccordion: null,
        closeTimer: null,

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
                this.previouslyFocused.focus();
                this.previouslyFocused = null;
            }
        },
    }));
});

if (! window.Livewire) {
    window.Alpine = Alpine;
    Alpine.start();
}

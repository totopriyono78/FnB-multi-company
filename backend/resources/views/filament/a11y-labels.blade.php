{{--
    Perbaikan aksesibilitas untuk markup bawaan Filament 3.3 (WCAG 4.1.2 & 1.3.1):
    - tombol ikon "hapus semua filter" tanpa nama aksesibel;
    - tab relation manager dengan aria-selected="aria-selected" (nilai tidak valid);
    - daftar repeater & repeatable entry infolist berbentuk <ul><div><li> (li tidak berada langsung di dalam list);
    - item terpilih choices.js dengan aria-selected tanpa role yang mengizinkannya;
    - tombol hapus pada tag (TagsInput) dan tombol tutup notifikasi tanpa nama aksesibel;
    - infolist berbentuk <dl><div><div><dt> (dt/dd tidak langsung di dalam dl): dl dijadikan presentasi dan
      pasangan label–nilai diberi role term/definition agar hubungan label tetap terbaca.
    Hapus bagian terkait bila Filament sudah memperbaikinya.
--}}
<script>
    (() => {
        const removeFiltersLabel = @js(__('filament-tables::table.filters.actions.remove_all.tooltip'));

        const apply = () => {
            document.querySelectorAll('button[wire\\:click="removeTableFilters"]:not([aria-label])').forEach((el) => {
                el.setAttribute('aria-label', removeFiltersLabel);
            });

            document.querySelectorAll('button.fi-badge-delete-button').forEach((el) => {
                const tag = (el.closest('.fi-badge')?.textContent ?? '').trim();
                const label = tag ? `Hapus ${tag}` : 'Hapus';
                if (el.getAttribute('aria-label') !== label) {
                    el.setAttribute('aria-label', label);
                }
            });

            document.querySelectorAll('.fi-no-notification button.fi-icon-btn:not([aria-label])').forEach((el) => {
                if (el.textContent.trim() === '') {
                    el.setAttribute('aria-label', 'Tutup notifikasi');
                }
            });

            document.querySelectorAll('[role="tab"][aria-selected]').forEach((el) => {
                const value = el.getAttribute('aria-selected');
                if (value !== 'true' && value !== 'false') {
                    el.setAttribute('aria-selected', 'true');
                }
            });
            document.querySelectorAll('[role="tab"]:not([aria-selected])').forEach((el) => {
                el.setAttribute('aria-selected', 'false');
            });

            document.querySelectorAll('.fi-fo-repeater > ul, .fi-fo-simple-repeater > ul, .fi-in-repeatable > ul').forEach((ul) => {
                const grid = ul.firstElementChild;
                if (ul.getAttribute('role') !== 'presentation' && grid && grid.tagName !== 'LI') {
                    ul.setAttribute('role', 'presentation');
                    grid.setAttribute('role', 'list');
                }
            });

            document.querySelectorAll('.choices__list--single .choices__item[aria-selected], .choices__list--multiple .choices__item[aria-selected]').forEach((el) => {
                if (!el.hasAttribute('role')) {
                    el.removeAttribute('aria-selected');
                }
            });

            document.querySelectorAll('.fi-in-entry-wrp').forEach((wrapper) => {
                const list = wrapper.closest('dl');
                wrapper.querySelectorAll('dt:not([role]), dd:not([role])').forEach((el) => {
                    if (list && el.closest('dl') === list) {
                        el.setAttribute('role', el.tagName === 'DT' ? 'term' : 'definition');
                    }
                });
            });
            document.querySelectorAll('dl:not([role]) > .fi-fo-component-ctn').forEach((grid) => {
                grid.parentElement.setAttribute('role', 'presentation');
            });
        };

        apply();
        new MutationObserver(apply).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['aria-selected'] });
    })();
</script>

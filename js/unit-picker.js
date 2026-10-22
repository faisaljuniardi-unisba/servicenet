(() => {
    function setup(select) {
        if (select.dataset.unitPickerReady) return;
        select.dataset.unitPickerReady = '1';
        select.classList.add('native-unit-select');

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'unit-picker-button';
        button.setAttribute('aria-haspopup', 'dialog');
        button.disabled = select.disabled;
        select.insertAdjacentElement('afterend', button);

        const modal = document.createElement('div');
        modal.className = 'unit-picker-modal';
        modal.innerHTML = '<div class="unit-picker-panel" role="dialog" aria-modal="true" aria-label="Pilih Fakultas atau Unit">' +
            '<div class="unit-picker-header"><strong>Pilih Fakultas / Unit</strong><button type="button" class="unit-picker-close" aria-label="Tutup">×</button></div>' +
            '<input class="unit-picker-search" type="search" placeholder="Cari fakultas atau unit">' +
            '<div class="unit-picker-options"></div></div>';
        document.body.appendChild(modal);
        const search = modal.querySelector('.unit-picker-search');
        const options = modal.querySelector('.unit-picker-options');

        function updateLabel() {
            const selected = select.options[select.selectedIndex];
            button.textContent = selected ? selected.text.trim() : '-- Pilih Fakultas / Unit --';
        }
        function render(filter = '') {
            const term = filter.trim().toLocaleLowerCase('id-ID');
            options.replaceChildren();
            [...select.options].forEach(option => {
                if (term && !option.text.toLocaleLowerCase('id-ID').includes(term)) return;
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'unit-picker-option' + (option.selected ? ' is-selected' : '');
                item.textContent = option.text.trim();
                item.title = option.text.trim();
                item.addEventListener('click', () => {
                    select.value = option.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    updateLabel();
                    close();
                    button.focus();
                });
                options.appendChild(item);
            });
        }
        function close() { modal.classList.remove('is-open'); }
        button.addEventListener('click', () => { render(); search.value = ''; modal.classList.add('is-open'); setTimeout(() => search.focus(), 0); });
        modal.querySelector('.unit-picker-close').addEventListener('click', close);
        modal.addEventListener('click', event => { if (event.target === modal) close(); });
        search.addEventListener('input', () => render(search.value));
        document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
        select.addEventListener('change', updateLabel);
        updateLabel();
    }
    document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('select[name="unit_lembaga"], select[name="unit"]').forEach(setup));
})();

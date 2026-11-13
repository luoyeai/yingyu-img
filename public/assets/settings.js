(() => {
    'use strict';
    const root = document.querySelector('[data-settings]');
    if (!root) return;
    const categories = [...root.querySelectorAll('[data-settings-section]')];
    const navigation = root.querySelector('.settings-nav');
    const links = [...navigation.querySelectorAll('[data-settings-link]')];
    const storageKey = 'vault:settings-return';
    const categoryFor = name => categories.find(category => category.dataset.settingsSection === name);
    const revealDetails = element => {
        for (let parent = element.parentElement; parent && parent !== root; parent = parent.parentElement) {
            if (parent.tagName === 'DETAILS') parent.open = true;
        }
    };
    function selectCategory(name, {url, focus = false, replace = false} = {}) {
        const selected = categoryFor(name);
        if (!selected) return;
        categories.forEach(category => { category.hidden = category !== selected; });
        links.forEach(link => {
            if (link.dataset.settingsLink === name) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
        root.dataset.activeSection = name;
        if (url) history[replace ? 'replaceState' : 'pushState'](null, '', url);
        if (focus) {
            const heading = selected.querySelector('h2');
            if (heading) { heading.tabIndex = -1; heading.focus({preventScroll:true}); }
        }
        const activeLink = links.find(link => link.dataset.settingsLink === name);
        if (activeLink && navigation.scrollWidth > navigation.clientWidth) {
            navigation.scrollLeft = activeLink.offsetLeft - navigation.offsetLeft - 12;
        }
    }
    document.querySelectorAll('[data-settings-link]').forEach(link => link.addEventListener('click', event => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        selectCategory(link.dataset.settingsLink, {url:link.href, focus:true});
        const target = new URL(link.href).hash;
        if (target) document.getElementById(target.slice(1))?.scrollIntoView({block:'nearest'});
    }));
    navigation.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        const index = links.indexOf(document.activeElement);
        if (index < 0) return;
        event.preventDefault();
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? links.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + links.length) % links.length;
        links[next].focus();
    });
    window.addEventListener('popstate', () => selectCategory(new URL(location.href).searchParams.get('section') || 'upload'));
    root.addEventListener('invalid', event => {
        const category = event.target.closest('[data-settings-section]');
        if (category) selectCategory(category.dataset.settingsSection);
        revealDetails(event.target);
    }, true);
    root.querySelectorAll('form').forEach(form => form.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        const category = form.closest('[data-settings-section]');
        try {
            sessionStorage.setItem(storageKey, JSON.stringify({section:category.dataset.settingsSection, details:form.closest('details')?.id || '', created:Date.now()}));
        } catch {}
    }));
    let errorFields = [];
    try { errorFields = JSON.parse(root.dataset.errorFields || '[]'); } catch {}
    const hasErrors = errorFields.length > 0;
    let pending;
    try {
        pending = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
        sessionStorage.removeItem(storageKey);
    } catch {}
    if (pending && Date.now() - pending.created < 120000 && categoryFor(pending.section)) {
        const url = new URL(location.href);
        if (!hasErrors && !url.searchParams.has('section')) {
            url.searchParams.set('section', pending.section);
            selectCategory(pending.section, {url:url.href, replace:true});
        }
        const details = document.getElementById(pending.details);
        if (!hasErrors && details?.tagName === 'DETAILS' && details.closest('[data-settings-section]')?.dataset.settingsSection === root.dataset.activeSection) details.open = true;
    }
    if (hasErrors) {
        const url = new URL(location.href);
        url.searchParams.set('section', root.dataset.activeSection);
        selectCategory(root.dataset.activeSection, {url:url.href, replace:true});
    }
    const storageCreation = root.querySelector('#storage-create');
    if (storageCreation) {
        const driver = storageCreation.querySelector('[name="driver"]');
        const options = storageCreation.querySelector('[data-storage-options]');
        const revealStorageOptions = () => {
            if (driver.value === 's3') options.open = true;
        };
        driver.addEventListener('change', revealStorageOptions);
        revealStorageOptions();
    }
    const activeCategory = categoryFor(root.dataset.activeSection);
    const errorForm = root.dataset.errorForm;
    const forms = [...activeCategory.querySelectorAll('form')].filter(form => !errorForm || form.querySelector('[name="_settings_form"]')?.value === errorForm);
    forms.forEach(form => {
        [...form.elements].forEach(field => {
            if (!errorFields.includes(field.name) || field.type === 'hidden') return;
            field.setAttribute('aria-invalid', 'true');
            revealDetails(field);
        });
    });
    selectCategory(root.dataset.activeSection);
})();

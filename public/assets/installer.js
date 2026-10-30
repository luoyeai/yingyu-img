(() => {
    'use strict';
    const forms = [...document.querySelectorAll('form[data-loading-message]')];
    let submitting = false;
    forms.forEach(form => {
        form.addEventListener('submit', event => {
            if (submitting) {
                event.preventDefault();
                return;
            }
            if (!form.checkValidity()) {
                return;
            }
            const password = form.querySelector('[name="admin_password"]');
            const confirmation = form.querySelector('[name="admin_password_confirmation"]');
            if (password && confirmation) {
                confirmation.setCustomValidity(password.value === confirmation.value ? '' : '两次输入的密码不一致。');
                if (!confirmation.reportValidity()) {
                    event.preventDefault();
                    return;
                }
            }
            submitting = true;
            form.setAttribute('aria-busy', 'true');
            forms.forEach(otherForm => {
                otherForm.querySelectorAll('button[type="submit"]').forEach(button => {
                    button.disabled = true;
                    if (otherForm === form) {
                        button.classList.add('is-loading');
                        button.dataset.originalText = button.textContent;
                        button.textContent = form.dataset.loadingMessage || '正在提交…';
                    }
                });
            });
            const status = document.getElementById('submission-status');
            if (status) {
                status.textContent = form.dataset.loadingMessage || '正在提交，请等待服务器响应。';
                status.hidden = false;
            }
        });
    });
    document.querySelectorAll('[name="admin_password"], [name="admin_password_confirmation"]').forEach(input => {
        input.addEventListener('input', () => {
            document.querySelector('[name="admin_password_confirmation"]')?.setCustomValidity('');
        });
    });
    window.addEventListener('pageshow', event => {
        if (!event.persisted) {
            return;
        }
        submitting = false;
        forms.forEach(form => {
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button[type="submit"]').forEach(button => {
                button.disabled = false;
                button.classList.remove('is-loading');
                if (button.dataset.originalText) {
                    button.textContent = button.dataset.originalText;
                }
            });
        });
        const status = document.getElementById('submission-status');
        if (status) {
            status.hidden = true;
        }
    });
})();

(() => {
    'use strict';
    let sdkPromise;
    const loadSdk = () => {
        if (!sdkPromise) {
            sdkPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                const timer = setTimeout(() => reject(new Error('验证码加载超时，请刷新重试。')), 12000);
                script.src = 'https://static.geetest.com/v4/gt4.js';
                script.onload = () => {
                    clearTimeout(timer);
                    if (typeof window.initGeetest4 === 'function') resolve();
                    else reject(new Error('验证码加载失败，请刷新重试。'));
                };
                script.onerror = () => {
                    clearTimeout(timer);
                    reject(new Error('验证码加载失败，请刷新重试。'));
                };
                document.head.append(script);
            });
        }
        return sdkPromise;
    };
    document.querySelectorAll('[data-geetest-login], [data-geetest-settings]').forEach(root => {
        const login = root.hasAttribute('data-geetest-login');
        const button = root.querySelector(login ? '[type="submit"]' : '[data-geetest-test]');
        const status = root.querySelector('[data-geetest-status]');
        let busy = false;
        let widget;
        let timer;
        const release = () => {
            clearTimeout(timer);
            busy = false;
            button.disabled = false;
            if (widget) {
                widget.destroy();
                widget = null;
            }
        };
        const fail = message => {
            status.textContent = message;
            release();
        };
        const start = async event => {
            event.preventDefault();
            if (busy || (login && !root.reportValidity())) return;
            busy = true;
            button.disabled = true;
            status.textContent = '正在加载验证码…';
            timer = setTimeout(() => fail('验证码操作超时，请重试。'), 120000);
            try {
                await loadSdk();
                if (!busy) return;
                window.initGeetest4({
                    captchaId: root.dataset.captchaId,
                    product: 'bind', language: 'zho', protocol: 'https://', timeout: 10000,
                    apiServers: ['gcaptcha4.geetest.com'], staticServers: ['static.geetest.com'],
                }, captcha => {
                    if (!busy) { captcha.destroy(); return; }
                    widget = captcha;
                    captcha.onReady(() => {
                        status.textContent = '请完成验证码。';
                        captcha.showCaptcha();
                    }).onError(() => fail('验证码服务暂不可用，请刷新重试。'))
                        .onClose(() => fail('验证已取消，请重新验证。'))
                        .onSuccess(async () => {
                            const result = captcha.getValidate();
                            if (!result) { fail('未获得验证结果，请重试。'); return; }
                            const fields = ['lot_number', 'captcha_output', 'pass_token', 'gen_time'];
                            const data = Object.fromEntries(fields.map(field => [field, result[field]]));
                            if (login) {
                                fields.forEach(field => {
                                    const input = document.createElement('input');
                                    input.type = 'hidden'; input.name = field; input.value = data[field] || '';
                                    root.append(input);
                                });
                                clearTimeout(timer);
                                HTMLFormElement.prototype.submit.call(root);
                                return;
                            }
                            status.textContent = '正在验证…';
                            try {
                                const response = await fetch(root.dataset.testUrl, {
                                    method: 'POST', credentials: 'same-origin', signal: AbortSignal.timeout(15000),
                                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                                    body: JSON.stringify(data),
                                });
                                const body = await response.json();
                                status.textContent = body.message || '验证未完成，请重试。';
                                release();
                            } catch {
                                fail('验证结果未确认，请刷新后重试。');
                            }
                        });
                });
            } catch (error) {
                fail(error.message || '验证码服务暂不可用，请刷新重试。');
            }
        };
        if (login) root.addEventListener('submit', start);
        else button.addEventListener('click', start);
    });
})();

(function () {
    function initializePasswordToggles() {
        document.querySelectorAll('input[type="password"]').forEach(input => {
            const control = document.createElement('span');
            control.className = 'password-visibility-control';
            input.parentNode.insertBefore(control, input);
            control.append(input);

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'password-visibility-toggle';
            button.textContent = 'Show';
            button.setAttribute('aria-label', 'Show password');
            button.setAttribute('aria-pressed', 'false');
            button.addEventListener('click', () => {
                const isVisible = input.type === 'password';
                input.type = isVisible ? 'text' : 'password';
                button.textContent = isVisible ? 'Hide' : 'Show';
                button.setAttribute('aria-label', `${isVisible ? 'Hide' : 'Show'} password`);
                button.setAttribute('aria-pressed', String(isVisible));
            });
            control.append(button);

            input.form?.addEventListener('reset', () => {
                window.setTimeout(() => {
                    input.type = 'password';
                    button.textContent = 'Show';
                    button.setAttribute('aria-label', 'Show password');
                    button.setAttribute('aria-pressed', 'false');
                }, 0);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializePasswordToggles, { once: true });
    } else {
        initializePasswordToggles();
    }
})();

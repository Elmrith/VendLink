const resetNotice = document.getElementById('passwordResetNotice');
const csrfMeta = document.querySelector('meta[name="csrf-token"]');
const resetToken = new URLSearchParams(window.location.search).get('token') || '';
if (resetToken) window.history.replaceState(null, document.title, window.location.pathname);

function showResetNotice(message, isError = false) {
    resetNotice.textContent = message;
    resetNotice.hidden = false;
    resetNotice.className = `registration-notice ${isError ? 'is-error' : 'is-success'}`;
}

async function initializeResetCsrf() {
    const response = await fetch('api/auth.php?action=csrf', { credentials: 'same-origin' });
    if (!response.ok) throw new Error('Unable to start secure password recovery.');
    const result = await response.json();
    csrfMeta.content = result.csrfToken || '';
}

initializeResetCsrf().catch(error => showResetNotice(error.message, true));

const forgotForm = document.getElementById('forgotPasswordForm');
forgotForm?.addEventListener('submit', async event => {
    event.preventDefault();
    const button = document.getElementById('requestResetButton');
    button.disabled = true;
    try {
        await API.post('api/password_reset.php', {
            action: 'request',
            email: document.getElementById('recoveryEmail').value.trim()
        });
        showResetNotice('If an account exists for that email address, a password reset link will be sent. If it does not arrive, try again later or contact the administrator.');
        forgotForm.reset();
    } catch (error) {
        showResetNotice(error.message || 'Password recovery is temporarily unavailable.', true);
    } finally {
        button.disabled = false;
    }
});

const resetForm = document.getElementById('resetPasswordForm');
resetForm?.addEventListener('submit', async event => {
    event.preventDefault();
    const button = document.getElementById('saveNewPasswordButton');
    const password = document.getElementById('newPassword').value;
    const confirmation = document.getElementById('confirmPassword').value;
    if (!resetToken) {
        showResetNotice('This reset link is invalid or expired. Request a new link.', true);
        return;
    }
    if (password !== confirmation) {
        showResetNotice('The password confirmation does not match.', true);
        return;
    }
    button.disabled = true;
    try {
        const result = await API.post('api/password_reset.php', {
            action: 'reset',
            token: resetToken,
            password,
            confirmation
        });
        resetForm.reset();
        resetForm.hidden = true;
        showResetNotice(`${result.message} A confirmation email was sent if mail delivery is available.`);
    } catch (error) {
        showResetNotice(error.message || 'The password could not be changed.', true);
    } finally {
        button.disabled = false;
    }
});

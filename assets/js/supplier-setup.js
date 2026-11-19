const setupToken = new URLSearchParams(window.location.search).get('token') || '';
const setupForm = document.getElementById('setupForm');
const setupNotice = document.getElementById('setupNotice');
const setupSubmit = document.getElementById('setupSubmit');
const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');

history.replaceState(null, '', window.location.pathname);
lucide.createIcons();

function showSetupNotice(message, isError = false) {
    setupNotice.textContent = message;
    setupNotice.hidden = false;
    setupNotice.className = `registration-notice ${isError ? 'is-error' : 'is-success'}`;
}

const csrfReady = fetch('api/auth.php?action=csrf', { credentials: 'same-origin' })
    .then(response => response.ok ? response.json() : null)
    .then(data => {
        if (!data?.csrfToken) throw new Error('Unable to start secure password setup. Reload this page and try again.');
        csrfTokenMeta.content = data.csrfToken;
    });

if (!/^[a-f0-9]{64}$/i.test(setupToken)) {
    setupForm.hidden = true;
    showSetupNotice('This password setup link is invalid. Contact the VendLink admin team for help.', true);
}

setupForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (!setupForm.reportValidity()) return;

    const password = document.getElementById('setupPassword').value;
    const confirmation = document.getElementById('setupPasswordConfirm').value;
    if (Array.from(password).length < 12 || new TextEncoder().encode(password).length > 72) {
        showSetupNotice('Choose a password with at least 12 characters and no more than 72 UTF-8 bytes.', true);
        return;
    }
    if (password !== confirmation) {
        showSetupNotice('The passwords do not match.', true);
        return;
    }

    setupSubmit.disabled = true;
    try {
        await csrfReady;
        await API.post('api/auth.php?action=completeSupplierSetup', { token: setupToken, password, passwordConfirmation: confirmation });
        setupForm.hidden = true;
        showSetupNotice('Password saved. Your supplier account is ready; sign in to continue.');
    } catch (error) {
        showSetupNotice(error.message || 'Unable to save your password.', true);
        setupSubmit.disabled = false;
    }
});

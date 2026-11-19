const roleInput = document.getElementById('registrationRole');
const registrationForm = document.getElementById('registrationForm');
const registrationNotice = document.getElementById('registrationNotice');
const registrationSubmit = document.getElementById('registrationSubmit');
const roleButtons = document.querySelectorAll('[data-registration-role]');
const roleFields = document.querySelectorAll('[data-role-fields]');
const profileImageInput = document.getElementById('registrationProfileImage');
const profileImagePreview = document.getElementById('registrationProfilePreview');
const businessPermitInput = document.getElementById('registrationBusinessPermit');
const businessPermitPreview = document.getElementById('registrationBusinessPermitPreview');
let profileImageUrl = '';
let profileImageObjectUrl = '';
let businessPermitObjectUrl = '';

profileImageInput.addEventListener('change', () => {
    const file = profileImageInput.files?.[0];
    profileImageUrl = '';
    if (profileImageObjectUrl) URL.revokeObjectURL(profileImageObjectUrl);
    profileImageObjectUrl = '';
    if (!file) {
        profileImagePreview.hidden = true;
        return;
    }
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
        profileImageInput.value = '';
        profileImagePreview.hidden = true;
        showRegistrationNotice('Choose a JPG, PNG, or WEBP image no larger than 5MB.', true);
        return;
    }
    profileImageObjectUrl = URL.createObjectURL(file);
    profileImagePreview.src = profileImageObjectUrl;
    profileImagePreview.hidden = false;
});

businessPermitInput.addEventListener('change', () => {
    const file = businessPermitInput.files?.[0];
    if (businessPermitObjectUrl) URL.revokeObjectURL(businessPermitObjectUrl);
    businessPermitObjectUrl = '';
    if (!file) {
        businessPermitPreview.hidden = true;
        return;
    }
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
        businessPermitInput.value = '';
        businessPermitPreview.hidden = true;
        showRegistrationNotice('Choose a JPG, PNG, or WEBP business permit image no larger than 5MB.', true);
        return;
    }
    businessPermitObjectUrl = URL.createObjectURL(file);
    businessPermitPreview.src = businessPermitObjectUrl;
    businessPermitPreview.hidden = false;
});

async function uploadRegistrationImage(file) {
    const formData = new FormData();
    formData.append('purpose', 'registration');
    formData.append('image', file);
    const response = await fetch('api/upload.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': await API.getCsrfToken() },
        body: formData
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Unable to upload the profile picture.');
    return result.url;
}

async function uploadBusinessPermit(file) {
    const formData = new FormData();
    formData.append('purpose', 'business_permit');
    formData.append('image', file);
    const response = await fetch('api/upload.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': await API.getCsrfToken() },
        body: formData
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Unable to upload the business permit.');
}

function setRegistrationRole(role) {
    roleInput.value = role;
    roleButtons.forEach(button => {
        button.setAttribute('aria-pressed', String(button.dataset.registrationRole === role));
    });
    roleFields.forEach(section => {
        const active = section.dataset.roleFields === role;
        section.hidden = !active;
        section.querySelectorAll('input, textarea').forEach(field => {
            field.disabled = !active;
            field.required = active;
        });
    });
    registrationSubmit.innerHTML = `${role === 'supplier' ? 'Submit supplier request' : 'Submit vendor application'} <i data-lucide="arrow-right" class="h-4 w-4"></i>`;
    lucide.createIcons();
}

function showRegistrationNotice(message, isError = false) {
    registrationNotice.textContent = message;
    registrationNotice.hidden = false;
    registrationNotice.className = `registration-notice ${isError ? 'is-error' : 'is-success'}`;
}

roleButtons.forEach(button => button.addEventListener('click', () => {
    setRegistrationRole(button.dataset.registrationRole);
    registrationNotice.hidden = true;
}));

registrationForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (!registrationForm.reportValidity()) return;
    const role = roleInput.value;
    const dataConsent = document.getElementById('registrationDataConsent');
    const marketingConsent = document.getElementById('registrationMarketingConsent');
    if (!dataConsent.checked) {
        showRegistrationNotice('Please confirm that you consent to the collection and processing of your personal information for account review and service delivery.', true);
        return;
    }

    registrationSubmit.disabled = true;
    registrationNotice.hidden = true;
    const payload = {
        role,
        name: document.getElementById('registrationName').value.trim(),
        email: document.getElementById('registrationEmail').value.trim(),
        password: role === 'vendor' ? document.getElementById('registrationPassword').value : '',
        municipality: 'Guimbal',
        province: 'Iloilo',
        barangay: document.getElementById('registrationBarangay').value.trim(),
        sitioPurok: document.getElementById('registrationSitio').value.trim(),
        additionalAddress: document.getElementById('registrationAdditionalAddress').value.trim(),
        applicationMessage: role === 'supplier' ? document.getElementById('registrationMessage').value.trim() : '',
        dataProcessingConsent: dataConsent.checked,
        marketingConsent: marketingConsent.checked
    };

    try {
        if (profileImageInput.files?.[0]) {
            profileImageUrl = await uploadRegistrationImage(profileImageInput.files[0]);
        }
        await uploadBusinessPermit(businessPermitInput.files[0]);
        payload.profileImage = profileImageUrl;
        await API.post('api/auth.php?action=signup', payload);
        showRegistrationNotice(role === 'supplier'
            ? 'Your supplier request was submitted. An admin must review your business permit before approval.'
            : 'Your vendor application was submitted. An admin must review your business permit before approval.');
        registrationForm.reset();
        profileImageUrl = '';
        if (profileImageObjectUrl) URL.revokeObjectURL(profileImageObjectUrl);
        profileImageObjectUrl = '';
        profileImagePreview.hidden = true;
        if (businessPermitObjectUrl) URL.revokeObjectURL(businessPermitObjectUrl);
        businessPermitObjectUrl = '';
        businessPermitPreview.hidden = true;
        setRegistrationRole(role);
        registrationForm.querySelectorAll('input, textarea').forEach(field => { field.disabled = true; });
        roleButtons.forEach(button => { button.disabled = true; });
    } catch (error) {
        showRegistrationNotice(error.message || 'Unable to submit your application.', true);
        registrationSubmit.disabled = false;
    }
});

setRegistrationRole('vendor');

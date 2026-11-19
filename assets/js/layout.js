// Shared frontend layout for static HTML pages.
(function () {
    const rolePages = {
        vendor: ['vendor.html', 'marketplace.html', 'orders.html', 'profile.html'],
        admin: ['admin.html', 'marketplace.html', 'profile.html'],
        supplier: ['supplier.html', 'profile.html']
    };

    const pageDetails = {
        'vendor.html': ['Vendor Hub', 'layout-dashboard'],
        'marketplace.html': ['Marketplace', 'store'],
        'orders.html': ['My Orders', 'shopping-bag'],
        'supplier.html': ['Supplier Hub', 'briefcase-business'],
        'profile.html': ['Profile', 'user-cog'],
        'admin.html': ['Admin Panel', 'shield-check']
    };

    function icon(name, className) {
        return `<i data-lucide="${name}" class="${className || 'w-4 h-4'}"></i>`;
    }

    function renderHeader(role, profile) {
        const mount = document.querySelector('[data-site-header]');
        if (!mount) return;
        const currentPage = window.location.pathname.split('/').pop() || 'index.html';
        const visiblePages = rolePages[role] || [];
        const navItems = visiblePages.map(href => [href, ...pageDetails[href]]);
        const profileImage = String(profile?.profileImage || '');
        const safeProfileImage = /^(https:\/\/|\/|assets\/|uploads\/profiles\/)/i.test(profileImage)
            ? profileImage
            : 'assets/images/default-profile.svg';
        const safeProfileImageAttribute = safeProfileImage.replace(/[&<>'"]/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[character]));
        const userName = String(profile?.name || 'VendLink User');
        const safeName = userName.replace(/[&<>'"]/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[character]));
        mount.innerHTML = `
            <header class="site-header sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-200 shadow-sm">
                <div class="site-header-inner max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                    <a href="index.html" class="site-brand flex items-center gap-2 text-xl font-extrabold text-slate-900 tracking-tight">
                        <div class="site-brand-mark w-9 h-9 rounded-xl bg-blue-600 text-white flex items-center justify-center shadow-md shadow-blue-500/20">${icon('boxes', 'w-5 h-5')}</div>
                        <span>Vend<span class="text-blue-600">Link</span></span>
                    </a>
                    <button type="button" class="mobile-menu-button" aria-controls="primaryNavigation" aria-expanded="false" aria-label="Open navigation menu" onclick="toggleNavigation()">${icon('menu', 'w-5 h-5')}</button>
                    <nav id="primaryNavigation" class="primary-navigation flex items-center gap-1 sm:gap-2" aria-label="Primary navigation">
                        ${navItems.map(([href, label, lucide]) => `<a href="${href}" class="nav-link ${currentPage === href ? 'active' : ''} flex items-center gap-1.5">${icon(lucide)} ${label}</a>`).join('')}
                        <div class="h-6 w-px bg-slate-200 mx-2"></div>
                        <a href="profile.html" class="nav-profile-link" aria-label="Profile for ${safeName}"><img src="${safeProfileImageAttribute}" alt="" class="nav-profile-avatar"><span>${safeName}</span></a>
                        <button onclick="logout()" class="logout-button px-3 py-1 text-white bg-red-500 hover:bg-red-600 rounded-lg shadow-sm transition" title="Logout">${icon('log-out', 'w-4 h-4 inline-block mr-1')} Logout</button>
                    </nav>
                </div>
            </header>`;
        lucide.createIcons();
    }

    function applyRole(role) {
        document.body.classList.remove('role-vendor', 'role-admin', 'role-supplier');
        document.body.classList.add(`role-${role}`);
        document.querySelectorAll('[data-role-only]').forEach(element => {
            const isExcluded = element.dataset.roleOnly !== role;
            element.hidden = isExcluded;
            element.classList.toggle('hidden', isExcluded);
        });
        document.querySelectorAll('[data-role-exclude]').forEach(element => {
            const excludedRoles = element.dataset.roleExclude.split(',').map(value => value.trim());
            const isExcluded = excludedRoles.includes(role);
            element.hidden = isExcluded;
            element.classList.toggle('hidden', isExcluded);
        });
    }

    function getRoleHome(role) {
        return role === 'supplier' ? 'supplier.html' : role === 'admin' ? 'admin.html' : 'vendor.html';
    }

    function showAccessError(message) {
        const overlay = document.createElement('div');
        overlay.setAttribute('role', 'alert');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:24px;background:#f8fafc;color:#0f172a;font-family:system-ui,sans-serif;text-align:center;';

        const content = document.createElement('div');
        content.style.cssText = 'max-width:480px;';
        const title = document.createElement('h1');
        title.textContent = 'Unable to verify your profile';
        title.style.cssText = 'font-size:1.5rem;font-weight:700;margin:0 0 12px;';
        const description = document.createElement('p');
        description.textContent = message;
        description.style.cssText = 'margin:0 0 20px;line-height:1.5;';
        const retryButton = document.createElement('button');
        retryButton.type = 'button';
        retryButton.textContent = 'Try again';
        retryButton.style.cssText = 'padding:10px 18px;border:0;border-radius:8px;background:#2563eb;color:#fff;font:inherit;cursor:pointer;';
        retryButton.addEventListener('click', () => window.location.reload());

        content.append(title, description, retryButton);
        overlay.appendChild(content);
        document.body.appendChild(overlay);
    }

    async function initializeAccess() {
        const response = await fetch('api/profile.php', { credentials: 'same-origin' });
        if (response.status === 401) {
            window.location.replace('index.html');
            return false;
        }
        if (!response.ok) {
            const message = response.status === 403
                ? 'Your account is not permitted to access this page.'
                : response.status === 404
                    ? 'Your signed-in account profile could not be found. Contact support for help.'
                    : response.status >= 500
                        ? 'Your profile is temporarily unavailable. Your session is still active; try again shortly.'
                        : 'The profile service could not verify your account. Your session is still active.';
            showAccessError(message);
            return false;
        }

        const profile = await response.json();
        const role = profile.role;
        if (!rolePages[role]) {
            showAccessError('Your account role could not be verified. Contact support for help.');
            return false;
        }

        applyRole(role);
        renderHeader(role, profile);
        const currentPage = window.location.pathname.split('/').pop() || 'index.html';
        if (!rolePages[role].includes(currentPage)) {
            window.location.replace(getRoleHome(role));
            return;
        }
        document.documentElement.dataset.roleReady = role;
        return true;
    }

    function renderFooter() {
        const mount = document.querySelector('[data-site-footer]');
        if (!mount) return;
        mount.innerHTML = `<footer class="site-footer"><div class="site-footer-inner"><div class="site-footer-brand"><div class="site-footer-mark">${icon('boxes', 'w-4 h-4')}</div><div><strong>Vend<span>Link</span></strong><p>Better sourcing for growing food businesses.</p></div></div><div class="site-footer-links"><a href="business-information.html">Business Information</a><a href="privacy-policy.html">Privacy Policy</a><a href="terms.html">Terms of Service</a><a href="cookies.html">Cookie Settings</a></div><div class="site-footer-meta"><span>Wholesale food marketplace</span><span>&copy; ${new Date().getFullYear()} VendLink</span></div></div></footer>`;
        lucide.createIcons();
    }

    function getCookieConsentStorage() {
        try {
            const raw = localStorage.getItem('vendlink_cookie_preferences');
            return raw ? JSON.parse(raw) : null;
        } catch (error) {
            return null;
        }
    }

    function persistCookieConsent(preferences, source = 'banner') {
        const payload = {
            essential: true,
            analytics: Boolean(preferences.analytics),
            functional: Boolean(preferences.functional),
            marketing: Boolean(preferences.marketing),
            consentSource: source,
            policyVersion: '2025-01',
            consentedAt: new Date().toISOString(),
            consented: true
        };

        try {
            localStorage.setItem('vendlink_cookie_preferences', JSON.stringify(payload));
        } catch (error) {
            console.warn('Unable to store cookie preferences:', error);
        }

        fetch('api/privacy.php?action=save-cookie-preferences', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                essential: true,
                analytics: payload.analytics,
                functional: payload.functional,
                marketing: payload.marketing,
                policyVersion: '2025-01',
                source
            })
        }).catch(() => {});
    }

    function renderCookieBanner() {
        const existingBanner = document.querySelector('.cookie-consent-banner');
        if (existingBanner) return;

        const savedConsent = getCookieConsentStorage();
        if (savedConsent && savedConsent.consented) return;

        const banner = document.createElement('div');
        banner.className = 'cookie-consent-banner';
        banner.setAttribute('role', 'dialog');
        banner.setAttribute('aria-live', 'polite');
        banner.innerHTML = `
            <div class="cookie-consent-panel">
                <div>
                    <h2>Cookie preferences</h2>
                    <p>VendLink uses essential cookies to keep the marketplace secure. We only activate non-essential cookies after you choose your preferences.</p>
                </div>
                <div class="cookie-consent-actions">
                    <button type="button" class="cookie-btn cookie-btn-primary" data-cookie-choice="all">Accept All</button>
                    <button type="button" class="cookie-btn cookie-btn-secondary" data-cookie-choice="essential">Reject Non-Essential</button>
                    <button type="button" class="cookie-btn cookie-btn-ghost" data-cookie-choice="customize">Customize Preferences</button>
                </div>
                <div class="cookie-customization">
                    <label><input type="checkbox" checked disabled> Essential Cookies</label>
                    <label><input type="checkbox" data-cookie-category="analytics"> Analytics Cookies</label>
                    <label><input type="checkbox" data-cookie-category="functional"> Functional Cookies</label>
                    <label><input type="checkbox" data-cookie-category="marketing"> Marketing Cookies</label>
                </div>
            </div>`;

        banner.querySelectorAll('[data-cookie-choice]').forEach(button => {
            button.addEventListener('click', () => {
                const choice = button.dataset.cookieChoice;
                const categories = {
                    essential: { analytics: false, functional: false, marketing: false },
                    all: { analytics: true, functional: true, marketing: true },
                    customize: { analytics: !!banner.querySelector('[data-cookie-category="analytics"]').checked, functional: !!banner.querySelector('[data-cookie-category="functional"]').checked, marketing: !!banner.querySelector('[data-cookie-category="marketing"]').checked }
                };
                const preference = { analytics: Boolean(categories[choice]?.analytics), functional: Boolean(categories[choice]?.functional), marketing: Boolean(categories[choice]?.marketing) };
                persistCookieConsent(preference, choice === 'customize' ? 'customize' : 'banner');
                banner.remove();
            });
        });

        document.body.appendChild(banner);
    }

    function applySharedBackground() {
        fetch('api/settings.php', { credentials: 'same-origin' })
            .then(response => response.ok ? response.json() : Promise.reject(new Error('Unable to load shared background')))
            .then(settings => {
                const allowedBackgrounds = ['default', 'ocean', 'meadow', 'sunset'];
                document.body.dataset.siteBackground = allowedBackgrounds.includes(settings.siteBackground)
                    ? settings.siteBackground
                    : 'default';
            })
            .catch(() => { document.body.dataset.siteBackground = 'default'; });
    }

    window.toggleNavigation = function () {
        const navigation = document.getElementById('primaryNavigation');
        const menuButton = document.querySelector('.mobile-menu-button');
        if (!navigation || !menuButton) return;
        const isOpen = navigation.classList.toggle('is-open');
        menuButton.setAttribute('aria-expanded', String(isOpen));
        menuButton.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
    };

    document.addEventListener('DOMContentLoaded', () => {
        renderFooter();
        applySharedBackground();
        const currentPage = window.location.pathname.split('/').pop() || 'index.html';
        if (currentPage !== 'index.html') {
            window.vendLinkAccessReady = initializeAccess().catch(error => {
                console.error('Unable to verify VendLink profile:', error);
                showAccessError('The profile service could not be reached. Your session is still active; try again shortly.');
                return false;
            });
        } else {
            window.vendLinkAccessReady = Promise.resolve(true);
        }
        fetch('api/auth.php?action=csrf', { credentials: 'same-origin' })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (data?.csrfToken) {
                    let meta = document.querySelector('meta[name="csrf-token"]');
                    if (!meta) {
                        meta = document.createElement('meta');
                        meta.name = 'csrf-token';
                        document.head.appendChild(meta);
                    }
                    meta.content = data.csrfToken;
                }
            })
            .catch(() => {});
    });
})();

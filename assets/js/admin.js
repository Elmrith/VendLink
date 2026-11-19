function escapeAdminHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[character]));
}

let adminUsers = [];
let adminFilter = 'all';
let adminSearch = '';
let adminNotifications = [];
let adminDeliveries = [];
let adminDeliverySearch = '';
let adminDeliveryStatus = 'all';
let adminDeliverySort = 'newest';
let adminPrivacyRequests = [];
let adminOverallDemandChart = null;

function renderAdminPrivacyRequests() {
    const body = document.getElementById('adminPrivacyRequestsBody');
    const count = document.getElementById('adminPrivacyRequestCount');
    const state = document.getElementById('adminPrivacyRequestState');
    if (!body || !count || !state) return;

    if (!adminPrivacyRequests.length) {
        count.textContent = 'No privacy requests';
        body.innerHTML = '<tr><td colspan="6" class="admin-table-empty">No privacy requests have been submitted yet.</td></tr>';
        return;
    }

    count.textContent = `${adminPrivacyRequests.length} request${adminPrivacyRequests.length === 1 ? '' : 's'}`;
    body.innerHTML = adminPrivacyRequests.map(request => {
        const status = request.status || 'pending';
        const statusClass = status === 'completed' ? 'admin-delivery-status-badge--delivered' : status === 'rejected' ? 'admin-delivery-status-badge--cancelled' : status === 'in_progress' ? 'admin-delivery-status-badge--processing' : 'admin-delivery-status-badge--pending';
        const requestType = String(request.requestType || 'privacy').replace(/_/g, ' ').replace(/\b\w/g, character => character.toUpperCase());
        const date = request.requestedAt ? new Date(String(request.requestedAt).replace(' ', 'T')).toLocaleString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }) : '—';
        const statusLabel = status.replace(/_/g, ' ').replace(/\b\w/g, character => character.toUpperCase());
        const canResolve = status !== 'completed' && status !== 'rejected';
        return `<tr>
            <td data-label="Request"><strong>${escapeAdminHtml(requestType)}</strong>${request.notes ? `<small>${escapeAdminHtml(request.notes)}</small>` : ''}</td>
            <td data-label="Account"><strong>${escapeAdminHtml(request.name || 'Unknown account')}</strong><small>${escapeAdminHtml(request.email || 'No email')}</small></td>
            <td data-label="Reason">${escapeAdminHtml(request.requestReason || 'No reason supplied')}</td>
            <td data-label="Status"><span class="admin-delivery-status-badge ${statusClass}">${escapeAdminHtml(statusLabel)}</span></td>
            <td data-label="Requested">${escapeAdminHtml(date)}</td>
            <td data-label="Action">${canResolve ? `<button type="button" class="admin-manage-account" data-privacy-request-action="${request.id}" data-privacy-request-status="completed"><i data-lucide="check-circle" aria-hidden="true"></i><span>Resolve</span></button>` : '<span class="admin-no-application">Closed</span>'}</td>
        </tr>`;
    }).join('');
    state.textContent = '';
    state.className = 'admin-action-state';
    if (window.lucide?.createIcons) window.lucide.createIcons();
}

async function loadAdminPrivacyRequests() {
    const state = document.getElementById('adminPrivacyRequestState');
    const body = document.getElementById('adminPrivacyRequestsBody');
    const count = document.getElementById('adminPrivacyRequestCount');
    if (!state || !body || !count) return;

    count.textContent = 'Loading requests...';
    body.innerHTML = '<tr><td colspan="6" class="admin-table-empty">Loading privacy requests...</td></tr>';
    try {
        const result = await API.get('api/privacy.php?action=admin-requests');
        adminPrivacyRequests = Array.isArray(result.requests) ? result.requests : [];
        renderAdminPrivacyRequests();
    } catch (error) {
        count.textContent = 'Requests unavailable';
        body.innerHTML = `<tr><td colspan="6" class="admin-table-empty is-error">${escapeAdminHtml(error.message || 'Unable to load privacy requests.')}</td></tr>`;
        state.textContent = error.message || 'Unable to load privacy requests.';
        state.className = 'admin-action-state is-visible is-error';
    }
}

function renderAdminDeliveries() {
    const body = document.getElementById('adminDeliveriesBody');
    const count = document.getElementById('adminDeliveryCount');
    if (!body || !count) return;

    const query = adminDeliverySearch.trim().toLowerCase();
    const deliveries = adminDeliveries.filter(order => {
        const searchableValues = [
            order.supplierOrderNumber, order.vendorBusinessName, order.vendorName,
            order.vendorDisplayName, order.vendorDisplayBusinessName,
            order.vendorUsername, order.supplierBusinessName, order.supplierName,
            order.supplierDisplayName, order.supplierDisplayBusinessName,
            order.supplierUsername, order.deliveryAddress, order.status
        ];
        return (adminDeliveryStatus === 'all' || order.status === adminDeliveryStatus)
            && (!query || searchableValues.some(value => String(value || '').toLowerCase().includes(query)));
    });
    const sortLabel = document.getElementById('adminDeliverySortLabel');
    if (sortLabel) sortLabel.textContent = adminDeliverySort === 'oldest' ? 'Oldest first' : 'Newest first';
    const getOrderTimestamp = order => {
        const value = String(order.createdAt || '').trim();
        if (!value) return Number.NaN;
        const timestamp = new Date(value.includes('T') ? value : value.replace(' ', 'T')).getTime();
        return Number.isFinite(timestamp) ? timestamp : Number.NaN;
    };
    deliveries.sort((left, right) => {
        const leftTime = getOrderTimestamp(left);
        const rightTime = getOrderTimestamp(right);
        if (Number.isNaN(leftTime)) return Number.isNaN(rightTime) ? 0 : 1;
        if (Number.isNaN(rightTime)) return -1;
        return adminDeliverySort === 'oldest' ? leftTime - rightTime : rightTime - leftTime;
    });

    count.textContent = deliveries.length === adminDeliveries.length
        ? `${deliveries.length} deliveries`
        : `${deliveries.length} of ${adminDeliveries.length} deliveries`;

    if (!deliveries.length) {
        body.innerHTML = '<tr><td colspan="6" class="admin-table-empty">No deliveries match this search or status.</td></tr>';
        return;
    }

    body.innerHTML = deliveries.map(order => {
        const createdAt = new Date(String(order.createdAt || '').replace(' ', 'T'));
        const timestamp = Number.isNaN(createdAt.getTime())
            ? 'Date unavailable'
            : createdAt.toLocaleString('en-US', { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        const status = ['pending', 'confirmed', 'processing', 'ready_for_dispatch', 'shipped', 'delivered', 'arrival_confirmed', 'cancelled'].includes(order.status) ? order.status : 'pending';
        const statusLabel = status.replace(/_/g, ' ').replace(/\b\w/g, character => character.toUpperCase());
        const vendorName = order.vendorDisplayName || order.vendorName || 'Vendor';
        const vendorBusinessName = order.vendorDisplayBusinessName || order.vendorBusinessName || 'Business name unavailable';
        const supplierName = order.supplierDisplayName || order.supplierName || 'Supplier';
        const supplierBusinessName = order.supplierDisplayBusinessName || order.supplierBusinessName || 'Business name unavailable';
        return `<tr>
            <td data-label="Receipt"><strong>#${escapeAdminHtml(order.supplierOrderNumber || order.id)}</strong></td>
            <td data-label="Vendor"><span class="admin-delivery-party"><strong>${escapeAdminHtml(vendorName)}</strong><small>${escapeAdminHtml(vendorBusinessName)}</small>${order.vendorUsername ? `<small>@${escapeAdminHtml(order.vendorUsername)}</small>` : ''}</span></td>
            <td data-label="Supplier"><span class="admin-delivery-party"><strong>${escapeAdminHtml(supplierName)}</strong><small>${escapeAdminHtml(supplierBusinessName)}</small>${order.supplierUsername ? `<small>@${escapeAdminHtml(order.supplierUsername)}</small>` : ''}</span></td>
            <td data-label="Delivery address">${escapeAdminHtml(order.deliveryAddress || 'Address not provided')}</td>
            <td data-label="Status"><span class="admin-delivery-status-badge admin-delivery-status-badge--${status}">${escapeAdminHtml(statusLabel)}</span></td>
            <td data-label="Order date and time">${escapeAdminHtml(timestamp)}</td>
        </tr>`;
    }).join('');
}

async function loadAdminDeliveries() {
    const body = document.getElementById('adminDeliveriesBody');
    const count = document.getElementById('adminDeliveryCount');
    const state = document.getElementById('adminDeliveryState');
    if (!body || !count || !state) return;

    state.textContent = '';
    state.className = 'admin-action-state';
    count.textContent = 'Loading deliveries...';
    body.innerHTML = '<tr><td colspan="6" class="admin-table-empty">Loading deliveries...</td></tr>';
    try {
        const orders = await API.get('api/orders.php');
        if (!Array.isArray(orders)) throw new Error('Delivery data is unavailable.');
        adminDeliveries = orders;
        renderAdminDeliveries();
    } catch (error) {
        count.textContent = 'Deliveries unavailable';
        body.innerHTML = `<tr><td colspan="6" class="admin-table-empty is-error">${escapeAdminHtml(error.message || 'Unable to load deliveries.')}</td></tr>`;
        state.textContent = error.message || 'Unable to load deliveries.';
        state.className = 'admin-action-state is-visible is-error';
        throw error;
    }
}

function renderAdminNotifications() {
    const list = document.getElementById('adminNotificationList');
    const badge = document.getElementById('adminNotificationBadge');
    if (!list || !badge) return;

    const unreadCount = adminNotifications.filter(notification => Number(notification.isRead) === 0).length;
    badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
    badge.hidden = unreadCount === 0;

    if (!adminNotifications.length) {
        list.innerHTML = '<li class="admin-notification-empty">No notifications yet.</li>';
        return;
    }

    list.innerHTML = adminNotifications.map(notification => {
        const createdAt = new Date(String(notification.createdAt || '').replace(' ', 'T'));
        const timestamp = Number.isNaN(createdAt.getTime())
            ? ''
            : createdAt.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
        const isUnread = Number(notification.isRead) === 0;
        return `<li class="admin-notification-item ${isUnread ? 'is-unread' : ''}">
            <span class="admin-notification-dot" aria-hidden="true"></span>
            <div><strong>${escapeAdminHtml(notification.title)}</strong><p>${escapeAdminHtml(notification.message)}</p>${timestamp ? `<time>${escapeAdminHtml(timestamp)}</time>` : ''}</div>
        </li>`;
    }).join('');
}

async function loadAdminNotifications() {
    const list = document.getElementById('adminNotificationList');
    if (!list) return;
    try {
        adminNotifications = await API.get('api/notifications.php');
        renderAdminNotifications();
    } catch (error) {
        list.innerHTML = `<li class="admin-notification-empty is-error">${escapeAdminHtml(error.message || 'Unable to load notifications.')}</li>`;
    }
}

function getApprovalStatus(user) {
    return user.approvalStatus || (Number(user.isApproved) ? 'approved' : 'pending');
}

function renderAdminUsers() {
    const body = document.getElementById('adminUsersBody');
    const normalizedSearch = adminSearch.trim().toLowerCase();
    const visibleUsers = adminUsers.filter(user => {
        const approvalStatus = getApprovalStatus(user);
        const filterMatches = adminFilter === 'all' || approvalStatus === adminFilter;
        const searchMatches = !normalizedSearch || [user.name, user.businessName, user.email, user.role, user.applicationMessage]
            .some(value => String(value || '').toLowerCase().includes(normalizedSearch));
        return filterMatches && searchMatches;
    });

    const countLabel = document.getElementById('adminAccountCount');
    countLabel.textContent = normalizedSearch || adminFilter !== 'all'
        ? `${visibleUsers.length} of ${adminUsers.length} accounts`
        : `${adminUsers.length} accounts`;

    body.innerHTML = visibleUsers.length ? visibleUsers.map(user => {
        const approvalStatus = getApprovalStatus(user);
        const initials = String(user.name || 'U').trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase();
        const createdAt = new Date(user.createdAt);
        const registeredDate = Number.isNaN(createdAt.getTime())
            ? '—'
            : createdAt.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        const userId = escapeAdminHtml(user.id);
        const permitStatus = ['pending', 'verified', 'rejected'].includes(user.businessPermitReviewStatus)
            ? user.businessPermitReviewStatus
            : 'pending';
        const permitUrl = `api/admin.php?action=businessPermit&userId=${encodeURIComponent(String(user.id))}`;

        return `
            <tr>
                <td data-label="Account">
                    <div class="admin-account-identity">
                        <span class="admin-account-avatar">${escapeAdminHtml(initials)}</span>
                        <span><strong>${escapeAdminHtml(user.name || 'User')}</strong><small>${escapeAdminHtml(user.businessName || 'Business not provided')}</small><small>${escapeAdminHtml(user.address || '')}</small></span>
                    </div>
                </td>
                <td class="admin-email-cell" data-label="Email">${escapeAdminHtml(user.email)}</td>
                <td class="admin-application-cell" data-label="Application reason">${user.applicationMessage ? `<details><summary>Read request</summary><p>${escapeAdminHtml(user.applicationMessage)}</p></details>` : '<span class="admin-no-application">—</span>'}</td>
                <td data-label="Business permit">${Number(user.hasBusinessPermit) ? `<a class="admin-manage-account" href="${escapeAdminHtml(permitUrl)}" target="_blank" rel="noopener noreferrer">View permit</a><small class="admin-permit-status admin-permit-status--${permitStatus}">${escapeAdminHtml(permitStatus.replace(/^\w/, letter => letter.toUpperCase()))}</small><div class="admin-account-actions"><button type="button" class="admin-manage-account" data-review-business-permit="${userId}" data-review-status="verified">Mark genuine</button><button type="button" class="admin-manage-account" data-review-business-permit="${userId}" data-review-status="rejected">Not genuine</button></div>` : '<span class="admin-no-application">No permit uploaded</span>'}</td>
                <td data-label="Role"><span class="admin-role admin-role--${user.role === 'supplier' ? 'supplier' : 'vendor'}">${escapeAdminHtml(user.role)}</span></td>
                <td class="admin-date-cell" data-label="Registered">${registeredDate}</td>
                <td data-label="Action"><div class="admin-account-actions"><button type="button" class="admin-manage-account" data-edit-account="${userId}"><i data-lucide="pencil" aria-hidden="true"></i><span>Edit</span></button><button type="button" class="admin-manage-account" data-open-account-action="${userId}"><i data-lucide="sliders-horizontal" aria-hidden="true"></i><span>Manage</span></button></div></td>
            </tr>`;
    }).join('') : '<tr><td colspan="7" class="admin-table-empty">No accounts match this search or filter.</td></tr>';

    if (window.lucide?.createIcons) window.lucide.createIcons();
}

function renderAdminData(data) {
    const stats = data.stats || {};
    adminUsers = data.users || [];
    document.getElementById('adminUserCount').textContent = Number(stats.userCount || 0).toLocaleString();
    document.getElementById('adminPendingCount').textContent = adminUsers.filter(user => getApprovalStatus(user) === 'pending').length.toLocaleString();
    document.getElementById('adminProductCount').textContent = Number(stats.productCount || 0).toLocaleString();
    document.getElementById('adminOrderTotal').textContent = `₱${Number(stats.orderTotal || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    const filterCounts = {
        all: adminUsers.length,
        pending: adminUsers.filter(user => getApprovalStatus(user) === 'pending').length,
        approved: adminUsers.filter(user => getApprovalStatus(user) === 'approved').length,
        disapproved: adminUsers.filter(user => getApprovalStatus(user) === 'disapproved').length
    };
    document.querySelectorAll('[data-filter-count]').forEach(count => {
        count.textContent = filterCounts[count.dataset.filterCount] ?? 0;
    });
    renderAdminUsers();
}

async function loadAdminOverallDemandChart() {
    const canvas = document.getElementById('adminOverallDemandChart');
    const message = document.getElementById('adminOverallDemandMessage');
    if (!canvas || !message) return;

    canvas.hidden = true;
    message.hidden = false;
    message.textContent = 'Loading overall product demand...';
    if (adminOverallDemandChart) {
        adminOverallDemandChart.destroy();
        adminOverallDemandChart = null;
    }

    try {
        const result = await API.get('api/analytics.php');
        if (!Array.isArray(result?.admin?.products)) {
            throw new Error('Overall product demand data is unavailable.');
        }

        const products = result.admin.products
            .map(product => ({
                name: String(product.name || 'Unknown product'),
                demand: Number(product.demand || 0)
            }))
            .filter(product => Number.isFinite(product.demand) && product.demand > 0)
            .sort((left, right) => right.demand - left.demand);

        if (!products.length) {
            message.textContent = 'No product demand to display yet.';
            return;
        }

        const chartItems = products.slice(0, 9);
        const otherDemand = products.slice(9).reduce((total, product) => total + product.demand, 0);
        if (otherDemand > 0) chartItems.push({ name: 'Other products', demand: otherDemand });

        message.hidden = true;
        canvas.hidden = false;
        adminOverallDemandChart = new Chart(canvas, {
            type: 'pie',
            data: {
                labels: chartItems.map(product => product.name),
                datasets: [{
                    label: 'Quantity ordered',
                    data: chartItems.map(product => product.demand),
                    backgroundColor: [
                        '#2563EB', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6',
                        '#EC4899', '#06B6D4', '#F97316', '#14B8A6', '#6366F1'
                    ],
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { usePointStyle: true, pointStyle: 'circle', padding: 16 }
                    },
                    tooltip: {
                        callbacks: {
                            label: context => `${context.label}: ${Number(context.raw).toLocaleString()} units`
                        }
                    }
                }
            }
        });
    } catch (error) {
        message.textContent = error.message || 'Unable to load overall product demand.';
    }
}

async function loadAdminData() {
    const refreshButton = document.getElementById('adminRefreshButton');
    refreshButton.disabled = true;
    refreshButton.setAttribute('aria-busy', 'true');
    try {
        renderAdminData(await API.get('api/admin.php'));
    } finally {
        refreshButton.disabled = false;
        refreshButton.removeAttribute('aria-busy');
    }
}

async function loadAdminSettings() {
    const requireEmailVerificationToggle = document.getElementById('requireEmailVerificationToggle');
    const adminEmailAddress = document.getElementById('adminEmailAddress');
    const securitySettingsState = document.getElementById('securitySettingsState');

    if (!requireEmailVerificationToggle || !adminEmailAddress) return;

    try {
        const settings = await API.get('api/settings.php');
        requireEmailVerificationToggle.checked = Boolean(settings.requireEmailVerification);
        adminEmailAddress.value = settings.adminEmail || '';
        securitySettingsState.textContent = 'Administration security settings loaded.';
        securitySettingsState.className = 'admin-create-supplier-state is-visible is-success';
    } catch (error) {
        securitySettingsState.textContent = error.message || 'Unable to load security settings.';
        securitySettingsState.className = 'admin-create-supplier-state is-visible is-error';
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    const body = document.getElementById('adminUsersBody');
    const actionState = document.getElementById('adminActionState');
    const filterButtons = document.querySelectorAll('[data-admin-filter]');
    const createSupplierForm = document.getElementById('createSupplierForm');
    const createSupplierButton = document.getElementById('createSupplierButton');
    const createSupplierState = document.getElementById('createSupplierState');
    const securitySettingsForm = document.getElementById('securitySettingsForm');
    const saveSecuritySettingsButton = document.getElementById('saveSecuritySettingsButton');
    const securitySettingsState = document.getElementById('securitySettingsState');
    const notificationButton = document.getElementById('adminNotificationsButton');
    const notificationPanel = document.getElementById('adminNotificationPanel');
    const actionDialog = document.getElementById('accountActionDialog');
    const actionForm = document.getElementById('accountActionForm');
    const actionChoice = document.getElementById('accountActionChoice');
    const resendSetupOption = document.getElementById('resendSupplierSetupOption');
    const actionTarget = document.getElementById('accountActionTarget');
    const actionDialogError = document.getElementById('accountActionDialogError');
    const actionSubmit = document.getElementById('submitAccountAction');
    const accountEditDialog = document.getElementById('accountEditDialog');
    const accountEditForm = document.getElementById('accountEditForm');
    const accountEditError = document.getElementById('accountEditError');
    const accountEditSubmit = document.getElementById('submitAccountEdit');
    let editingUserId = '';
    let selectedUserId = '';

    document.getElementById('adminAccountSearch').addEventListener('input', event => {
        adminSearch = event.target.value;
        renderAdminUsers();
    });
    document.getElementById('adminDeliverySearch').addEventListener('input', event => {
        adminDeliverySearch = event.target.value;
        renderAdminDeliveries();
    });
    document.getElementById('adminDeliveryStatus').addEventListener('change', event => {
        adminDeliveryStatus = event.target.value;
        renderAdminDeliveries();
    });
    document.getElementById('adminDeliverySort').addEventListener('change', event => {
        adminDeliverySort = event.target.value === 'oldest' ? 'oldest' : 'newest';
        renderAdminDeliveries();
    });

    filterButtons.forEach(button => button.addEventListener('click', () => {
        adminFilter = button.dataset.adminFilter;
        filterButtons.forEach(filterButton => {
            filterButton.setAttribute('aria-pressed', String(filterButton === button));
        });
        renderAdminUsers();
    }));

    createSupplierForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (!createSupplierForm.reportValidity()) return;
        createSupplierButton.disabled = true;
        createSupplierState.className = 'admin-create-supplier-state';
        createSupplierState.textContent = '';
        try {
            const result = await API.post('api/admin.php', {
                action: 'createSupplier',
                name: document.getElementById('newSupplierName').value.trim(),
                email: document.getElementById('newSupplierEmail').value.trim()
            });
            createSupplierForm.reset();
            await loadAdminData();
            createSupplierState.textContent = result.emailSent
                ? 'Supplier account created. Password setup email sent.'
                : 'Supplier account created, but setup email delivery failed. Check mail settings, then resend from the supplier account row.';
            createSupplierState.className = `admin-create-supplier-state is-visible ${result.emailSent ? 'is-success' : 'is-error'}`;
        } catch (error) {
            createSupplierState.textContent = error.message || 'Unable to create the supplier account.';
            createSupplierState.className = 'admin-create-supplier-state is-visible is-error';
        } finally {
            createSupplierButton.disabled = false;
        }
    });

    securitySettingsForm.addEventListener('submit', async event => {
        event.preventDefault();

        const formData = {
            requireEmailVerification: document.getElementById('requireEmailVerificationToggle').checked,
            adminEmail: document.getElementById('adminEmailAddress').value.trim(),
        };

        saveSecuritySettingsButton.disabled = true;
        securitySettingsState.className = 'admin-create-supplier-state';
        securitySettingsState.textContent = '';

        try {
            const result = await API.put('api/settings.php', formData);
            securitySettingsState.textContent = 'Security settings saved.';
            securitySettingsState.className = 'admin-create-supplier-state is-visible is-success';
            document.getElementById('requireEmailVerificationToggle').checked = Boolean(result.requireEmailVerification);
            document.getElementById('adminEmailAddress').value = result.adminEmail || '';
        } catch (error) {
            securitySettingsState.textContent = error.message || 'Unable to save security settings.';
            securitySettingsState.className = 'admin-create-supplier-state is-visible is-error';
        } finally {
            saveSecuritySettingsButton.disabled = false;
        }
    });

    notificationButton.addEventListener('click', () => {
        notificationPanel.hidden = !notificationPanel.hidden;
        notificationButton.setAttribute('aria-expanded', String(!notificationPanel.hidden));
    });

    document.getElementById('markAdminNotificationsRead').addEventListener('click', async event => {
        const button = event.currentTarget;
        button.disabled = true;
        try {
            await API.post('api/notifications.php', {});
            adminNotifications = adminNotifications.map(notification => ({ ...notification, isRead: 1 }));
            renderAdminNotifications();
        } catch (error) {
            const list = document.getElementById('adminNotificationList');
            list.insertAdjacentHTML('afterbegin', `<li class="admin-notification-empty is-error">${escapeAdminHtml(error.message || 'Unable to update notifications.')}</li>`);
        } finally {
            button.disabled = false;
        }
    });

    document.addEventListener('click', event => {
        const requestButton = event.target.closest('[data-privacy-request-action]');
        if (requestButton) {
            const requestId = requestButton.dataset.privacyRequestAction;
            const status = requestButton.dataset.privacyRequestStatus || 'completed';
            const state = document.getElementById('adminPrivacyRequestState');
            if (!requestId || !state) return;
            state.textContent = 'Updating privacy request...';
            state.className = 'admin-action-state is-visible';
            API.post('api/privacy.php?action=admin-request-status', { requestId, status, notes: 'Reviewed by administrator.' })
                .then(() => loadAdminPrivacyRequests())
                .catch(error => {
                    state.textContent = error.message || 'Unable to update the privacy request.';
                    state.className = 'admin-action-state is-visible is-error';
                });
            return;
        }

        if (!event.target.closest('.admin-notification-wrap')) {
            notificationPanel.hidden = true;
            notificationButton.setAttribute('aria-expanded', 'false');
        }
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            notificationPanel.hidden = true;
            notificationButton.setAttribute('aria-expanded', 'false');
        }
    });

    actionChoice.addEventListener('change', () => {
        actionSubmit.disabled = !actionChoice.value;
        actionDialogError.textContent = '';
    });

    document.getElementById('closeAccountActionDialog').addEventListener('click', () => actionDialog.close());
    document.getElementById('cancelAccountActionDialog').addEventListener('click', () => actionDialog.close());

    document.getElementById('adminRefreshButton').addEventListener('click', async () => {
        actionState.className = 'admin-action-state';
        actionState.textContent = '';
        try {
            await Promise.all([loadAdminData(), loadAdminDeliveries(), loadAdminOverallDemandChart()]);
        } catch (error) {
            actionState.textContent = error.message || 'Unable to refresh administration data.';
            actionState.className = 'admin-action-state is-visible is-error';
        }
    });

    body.addEventListener('click', event => {
        const button = event.target.closest('[data-open-account-action]');
        if (!button) return;
        const user = adminUsers.find(account => String(account.id) === button.dataset.openAccountAction);
        if (!user) return;
        selectedUserId = String(user.id);
        const status = getApprovalStatus(user);
        const statusLabel = status === 'approved' ? 'Approved' : status === 'disapproved' ? 'Disapproved' : 'Pending Account';
        actionTarget.textContent = `${user.name || user.email} · Current status: ${statusLabel}${Number(user.isActive) ? '' : ' · Inactive'}`;
        resendSetupOption.hidden = user.role !== 'supplier' || Number(user.canResendSetup) !== 1;
        actionChoice.value = '';
        actionSubmit.disabled = true;
        actionDialogError.textContent = '';
        actionDialog.showModal();
        if (window.lucide?.createIcons) window.lucide.createIcons();
    });

    body.addEventListener('click', async event => {
        const button = event.target.closest('[data-review-business-permit]');
        if (!button) return;
        button.disabled = true;
        actionState.textContent = 'Saving business permit review...';
        actionState.className = 'admin-action-state is-visible';
        try {
            await API.post('api/admin.php', {
                action: 'reviewPermit',
                userId: button.dataset.reviewBusinessPermit,
                reviewStatus: button.dataset.reviewStatus
            });
            await loadAdminData();
            actionState.textContent = 'Business permit review saved.';
            actionState.className = 'admin-action-state is-visible is-success';
        } catch (error) {
            actionState.textContent = error.message || 'Unable to save the business permit review.';
            actionState.className = 'admin-action-state is-visible is-error';
        } finally {
            button.disabled = false;
        }
    });

    body.addEventListener('click', event => {
        const button = event.target.closest('[data-edit-account]');
        if (!button) return;
        const user = adminUsers.find(account => String(account.id) === button.dataset.editAccount);
        if (!user) return;
        editingUserId = String(user.id);
        document.getElementById('editAccountName').value = user.name || '';
        document.getElementById('editAccountUsername').value = user.username || '';
        document.getElementById('editAccountBusinessName').value = user.businessName || '';
        accountEditError.textContent = '';
        accountEditDialog.showModal();
    });

    accountEditForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (!editingUserId) return;
        accountEditSubmit.disabled = true;
        accountEditError.textContent = '';
        try {
            await API.post('api/admin.php', {
                action: 'editAccount',
                userId: editingUserId,
                name: document.getElementById('editAccountName').value.trim(),
                username: document.getElementById('editAccountUsername').value.trim(),
                businessName: document.getElementById('editAccountBusinessName').value.trim()
            });
            accountEditDialog.close();
            await loadAdminData();
            actionState.textContent = 'Account identity updated.';
            actionState.className = 'admin-action-state is-visible is-success';
        } catch (error) {
            accountEditError.textContent = error.message || 'Unable to update account identity.';
        } finally {
            accountEditSubmit.disabled = false;
        }
    });
    document.getElementById('closeAccountEditDialog').addEventListener('click', () => accountEditDialog.close());
    document.getElementById('cancelAccountEditDialog').addEventListener('click', () => accountEditDialog.close());
    accountEditDialog.addEventListener('close', () => {
        editingUserId = '';
        accountEditForm.reset();
        accountEditError.textContent = '';
    });

    actionForm.addEventListener('submit', async event => {
        event.preventDefault();
        const action = actionChoice.value;
        if (!selectedUserId || !action) return;
        if (action === 'delete' && !window.confirm('Permanently delete this account and its associated order history? Supplier products will also be deleted. This cannot be undone.')) return;

        actionSubmit.disabled = true;
        actionChoice.disabled = true;
        actionState.className = 'admin-action-state';
        actionState.textContent = '';
        try {
            const result = await API.post('api/admin.php', { action, userId: selectedUserId });
            actionDialog.close();
            await loadAdminData();
            if (result.alreadyProcessed) {
                actionState.textContent = 'This account was already processed. No duplicate approval email was sent.';
                actionState.className = 'admin-action-state is-visible is-success';
            } else if (action === 'delete') {
                actionState.textContent = 'Account deleted.';
                actionState.className = 'admin-action-state is-visible is-success';
            } else if (result.emailSent) {
                actionState.textContent = action === 'resendSetup'
                    ? 'A new password setup link was emailed to the supplier.'
                    : `${action.charAt(0).toUpperCase()}${action.slice(1)} completed and email sent.`;
                actionState.className = 'admin-action-state is-visible is-success';
            } else {
                actionState.textContent = action === 'resendSetup'
                    ? 'The new setup link was saved, but email delivery failed. You can resend it from this account.'
                    : `${action.charAt(0).toUpperCase()}${action.slice(1)} saved, but the email could not be sent. You can resend a supplier setup link from this account.`;
                actionState.className = 'admin-action-state is-visible is-error';
            }
        } catch (error) {
            actionChoice.disabled = false;
            actionSubmit.disabled = !actionChoice.value;
            actionDialogError.textContent = error.message || 'Unable to update account.';
        }
    });

    actionDialog.addEventListener('close', () => {
        actionChoice.disabled = false;
        actionForm.reset();
        actionSubmit.disabled = true;
        actionDialogError.textContent = '';
        resendSetupOption.hidden = true;
        selectedUserId = '';
    });

    try {
        await loadAdminData();
    } catch (error) {
        body.innerHTML = `<tr><td colspan="7" class="admin-table-empty is-error">${escapeAdminHtml(error.message || 'Unable to load accounts.')}</td></tr>`;
    }
    try {
        await loadAdminDeliveries();
    } catch (error) {
        console.error('Failed to load admin deliveries:', error);
    }
    try {
        await loadAdminPrivacyRequests();
    } catch (error) {
        console.error('Failed to load privacy requests:', error);
    }
    try {
        await loadAdminSettings();
    } catch (error) {
        if (securitySettingsState) {
            securitySettingsState.textContent = error.message || 'Unable to load security settings.';
            securitySettingsState.className = 'admin-create-supplier-state is-visible is-error';
        }
    }
    await loadAdminOverallDemandChart();
    await loadAdminNotifications();
    window.setInterval(loadAdminNotifications, 60000);
    window.addEventListener('focus', loadAdminNotifications);
});
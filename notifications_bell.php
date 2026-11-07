<?php
/**
 * Shared Notification Bell Component
 * Include in the header of all dashboard pages
 * Replaces the "Welcome, username" div
 */
?>
<div class="header-right">
    <div class="notification-bell-wrapper">
        <button class="notification-bell-btn" onclick="toggleNotificationDropdown()" title="Notifications">
            <i class="fas fa-bell"></i>
            <span class="notification-badge" id="notifBadge" style="display:none;">0</span>
        </button>
        <div class="notification-dropdown" id="notifDropdown">
            <div class="notification-dropdown-header">
                <strong>Notifications</strong>
                <button onclick="markAllNotificationsRead()" title="Mark all read"><i class="fas fa-check-double"></i> Mark all read</button>
            </div>
            <div class="notification-dropdown-body" id="notifList">
                <div class="notification-empty"><i class="fas fa-bell-slash"></i><br>No notifications</div>
            </div>
        </div>
    </div>
    <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
</div>

<script>
(function() {
    // Prevent duplicate initialization
    if (window._notifBellInit) return;
    window._notifBellInit = true;

    var notifDropdownOpen = false;

    window.toggleNotificationDropdown = function() {
        var dd = document.getElementById('notifDropdown');
        notifDropdownOpen = !notifDropdownOpen;
        if (notifDropdownOpen) {
            dd.classList.add('open');
            loadNotifications();
        } else {
            dd.classList.remove('open');
        }
    };

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        var wrapper = document.querySelector('.notification-bell-wrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            var dd = document.getElementById('notifDropdown');
            if (dd) dd.classList.remove('open');
            notifDropdownOpen = false;
        }
    });

    function timeAgoNotif(dateStr) {
        var seconds = Math.floor((new Date() - new Date(dateStr.replace(' ', 'T'))) / 1000);
        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
        return Math.floor(seconds / 86400) + 'd ago';
    }

    function getTypeIcon(type) {
        switch(type) {
            case 'success': return '<i class="fas fa-check-circle text-success"></i>';
            case 'warning': return '<i class="fas fa-exclamation-triangle text-warning"></i>';
            case 'danger': return '<i class="fas fa-exclamation-circle text-danger"></i>';
            default: return '<i class="fas fa-info-circle text-info"></i>';
        }
    }

    function loadNotifications() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'notifications_api.php?action=getRecent', true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        renderNotifications(resp.notifications);
                        updateBadge(resp.count);
                    }
                } catch(e) {}
            }
        };
        xhr.send();
    }

    function renderNotifications(notifications) {
        var container = document.getElementById('notifList');
        if (!notifications || notifications.length === 0) {
            container.innerHTML = '<div class="notification-empty"><i class="fas fa-bell-slash"></i><br>No notifications</div>';
            return;
        }

        var html = '';
        notifications.forEach(function(n) {
            var unreadClass = n.is_read == 0 ? ' unread' : '';
            var linkAttr = n.link ? ' onclick="window.location.href=\'' + n.link + '\'"' : '';
            html += '<div class="notification-item' + unreadClass + '"' + linkAttr + ' data-id="' + n.id + '">';
            html += '<div class="notification-item-icon">' + getTypeIcon(n.type) + '</div>';
            html += '<div class="notification-item-content">';
            html += '<div class="notification-item-title">' + escapeHtml(n.title) + '</div>';
            html += '<div class="notification-item-message">' + escapeHtml(n.message) + '</div>';
            html += '<div class="notification-item-time">' + timeAgoNotif(n.created_at) + '</div>';
            html += '</div>';
            if (n.is_read == 0) {
                html += '<button class="notification-item-mark" onclick="event.stopPropagation();markNotifRead(' + n.id + ',this)" title="Mark read"><i class="fas fa-check"></i></button>';
            }
            html += '</div>';
        });
        container.innerHTML = html;
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function updateBadge(count) {
        var badge = document.getElementById('notifBadge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    window.markNotifRead = function(id, btn) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'notifications_api.php?action=markRead', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                if (btn) {
                    var item = btn.closest('.notification-item');
                    if (item) item.classList.remove('unread');
                    btn.remove();
                }
                pollNotificationCount();
            }
        };
        xhr.send('id=' + id);
    };

    window.markAllNotificationsRead = function() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'notifications_api.php?action=markAllRead', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                loadNotifications();
                updateBadge(0);
            }
        };
        xhr.send('');
    };

    function pollNotificationCount() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'notifications_api.php?action=getCount', true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        updateBadge(resp.count);
                    }
                } catch(e) {}
            }
        };
        xhr.send();
    }

    // Initial count load
    pollNotificationCount();

    // Poll every 30 seconds
    setInterval(pollNotificationCount, 30000);
})();
</script>

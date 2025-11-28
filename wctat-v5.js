jQuery(document).ready(function ($) {
  /* ========== Existing Assignment Features ========== */

  // Assign to Me - Orders List
  $(document).on("click", ".wctat-assign-me", function (e) {
    e.preventDefault();
    var btn = $(this);
    var orderId = btn.data("order");
    var nonce = btn.data("nonce");

    btn.prop("disabled", true).text("Assigning...");

    $.post(
      WCTATv5.ajax,
      {
        action: "wctat_assign_me",
        order_id: orderId,
        _ajax_nonce: nonce,
      },
      function (response) {
        if (response.success) {
          btn.text("Assigned!").css("background", "#0a7d18");
          location.reload();
        } else {
          alert("Error: " + (response.data || "Unknown error"));
          btn.prop("disabled", false).text("Assign to Me");
        }
      }
    );
  });

  // Assign to Me Now - Order Edit Page
  $(document).on("click", ".wctat-assign-me-now", function (e) {
    e.preventDefault();
    var btn = $(this);
    var orderId = btn.data("order");
    var nonce = btn.data("nonce");

    btn.prop("disabled", true).text("Assigning...");

    $.post(
      WCTATv5.ajax,
      {
        action: "wctat_assign_me",
        order_id: orderId,
        _ajax_nonce: nonce,
      },
      function (response) {
        if (response.success) {
          btn.text("✅ Assigned to You!").css("background", "#0a7d18");
          setTimeout(function () {
            location.reload();
          }, 800);
        } else {
          alert("Error: " + (response.data || "Unknown error"));
          btn.prop("disabled", false).text("Assign to Me");
        }
      }
    );
  });

  // Admin Bar Assign to Me
  $(document).on("click", "#wctat-adminbar-assign", function (e) {
    e.preventDefault();
    var orderId = $(this).data("order");
    var nonce = $(this).data("nonce");

    $.post(
      WCTATv5.ajax,
      {
        action: "wctat_assign_me",
        order_id: orderId,
        _ajax_nonce: nonce,
      },
      function (response) {
        if (response.success) {
          alert("Order assigned to you successfully!");
          location.reload();
        } else {
          alert("Error: " + (response.data || "Unknown error"));
        }
      }
    );
  });

  /* ========== NEW: Notification System ========== */

  var notificationsOpen = false;
  var notificationCheckInterval = null;

  // Create notifications dropdown
  function createNotificationsDropdown() {
    if ($("#wctat-notifications-dropdown").length) return;

    var dropdown = $(
      '<div id="wctat-notifications-dropdown">' +
        '<div class="wctat-notif-header">' +
        "<h3>Notifications</h3>" +
        '<button class="button button-small wctat-mark-all-read">Mark All Read</button>' +
        "</div>" +
        '<ul class="wctat-notif-list"></ul>' +
        "</div>"
    );

    $("#wp-admin-bar-wctat_notifications").append(dropdown);
  }

  // Toggle notifications dropdown
  $(document).on(
    "click",
    "#wp-admin-bar-wctat_notifications > a, #wctat-notifications-trigger",
    function (e) {
      e.preventDefault();
      e.stopPropagation();

      createNotificationsDropdown();

      if (notificationsOpen) {
        $("#wctat-notifications-dropdown").removeClass("wctat-show");
        notificationsOpen = false;
      } else {
        loadNotifications();
        $("#wctat-notifications-dropdown").addClass("wctat-show");
        notificationsOpen = true;
      }
    }
  );

  // Close dropdown when clicking outside
  $(document).on("click", function (e) {
    if (
      notificationsOpen &&
      !$(e.target).closest("#wp-admin-bar-wctat_notifications").length
    ) {
      $("#wctat-notifications-dropdown").removeClass("wctat-show");
      notificationsOpen = false;
    }
  });

  // Load notifications
  function loadNotifications() {
    $.post(
      WCTATv5.ajax,
      {
        action: "wctat_get_notifications",
        _ajax_nonce: WCTATv5.nonce,
      },
      function (response) {
        if (response.success) {
          updateNotificationBadge(response.data.unread_count);
          renderNotifications(response.data.notifications);
        }
      }
    );
  }

  // Update badge count
  function updateNotificationBadge(count) {
    var menuItem = $("#wp-admin-bar-wctat_notifications > a");
    if (count > 0) {
      menuItem.text("Notifications (" + count + ")");
    } else {
      menuItem.text("Notifications");
    }
  }

  // Render notifications
  function renderNotifications(notifications) {
    var list = $(".wctat-notif-list");
    list.empty();

    if (notifications.length === 0) {
      list.append('<li class="wctat-notif-empty">No notifications yet</li>');
      return;
    }

    $.each(notifications, function (i, notif) {
      var unreadClass = notif.is_read == 0 ? "wctat-unread" : "";
      var timeAgo = formatTimeAgo(notif.created_at);

      var item = $(
        '<li class="wctat-notif-item ' +
          unreadClass +
          '" data-id="' +
          notif.id +
          '" data-order="' +
          notif.order_id +
          '">' +
          '<div class="wctat-notif-message">' +
          escapeHtml(notif.message) +
          "</div>" +
          '<div class="wctat-notif-time">' +
          timeAgo +
          "</div>" +
          "</li>"
      );

      list.append(item);
    });
  }

  // Click on notification item
  $(document).on("click", ".wctat-notif-item", function () {
    var notifId = $(this).data("id");
    var orderId = $(this).data("order");

    // Mark as read
    $.post(WCTATv5.ajax, {
      action: "wctat_mark_notification_read",
      notification_id: notifId,
      _ajax_nonce: WCTATv5.nonce,
    });

    // Navigate to order (HPOS or classic)
    if (typeof wc !== "undefined" && wc.wcSettings && wc.wcSettings.adminUrl) {
      window.location.href =
        wc.wcSettings.adminUrl +
        "admin.php?page=wc-orders&action=edit&id=" +
        orderId;
    } else {
      window.location.href = WCTATv5.ajax.replace(
        "admin-ajax.php",
        "post.php?post=" + orderId + "&action=edit"
      );
    }
  });

  // Mark all as read
  $(document).on("click", ".wctat-mark-all-read", function (e) {
    e.preventDefault();
    e.stopPropagation();

    $.post(
      WCTATv5.ajax,
      {
        action: "wctat_mark_all_read",
        _ajax_nonce: WCTATv5.nonce,
      },
      function (response) {
        if (response.success) {
          loadNotifications();
        }
      }
    );
  });

  // Auto-refresh notifications every 30 seconds
  notificationCheckInterval = setInterval(function () {
    if ($("#wp-admin-bar-wctat_notifications").length) {
      $.post(
        WCTATv5.ajax,
        {
          action: "wctat_get_notifications",
          _ajax_nonce: WCTATv5.nonce,
        },
        function (response) {
          if (response.success) {
            updateNotificationBadge(response.data.unread_count);
          }
        }
      );
    } else {
      clearInterval(notificationCheckInterval);
    }
  }, 30000);

  // Helper: Format time ago
  function formatTimeAgo(datetime) {
    var now = new Date();
    var created = new Date(datetime.replace(" ", "T"));
    var diffMs = now - created;
    var diffMins = Math.floor(diffMs / 60000);

    if (diffMins < 1) return "Just now";
    if (diffMins < 60) return diffMins + " minutes ago";

    var diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24)
      return diffHours + " hour" + (diffHours > 1 ? "s" : "") + " ago";

    var diffDays = Math.floor(diffHours / 24);
    if (diffDays < 30)
      return diffDays + " day" + (diffDays > 1 ? "s" : "") + " ago";

    return created.toLocaleDateString();
  }

  // Helper: Escape HTML
  function escapeHtml(text) {
    var map = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    };
    return text.replace(/[&<>"']/g, function (m) {
      return map[m];
    });
  }
});

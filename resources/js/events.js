// resources/js/events.js — konstanta nama event, disalin dari kontrak §E.7
export const EV = {
    // server -> client
    PRESENCE_SNAPSHOT: "presence:snapshot",
    PRESENCE_UPDATE: "presence:update",
    MESSAGE_NEW: "message:new",
    MESSAGE_UPDATED: "message:updated",
    MESSAGE_DELETED: "message:deleted",
    MESSAGE_REACTION: "message:reaction",
    MESSAGE_DELIVERY: "message:delivery",
    MESSAGE_READ: "message:read",
    TYPING_UPDATE: "typing:update",
    UNREAD_UPDATE: "unread:update",
    NOTIFICATION_NEW: "notification:new",
    NOTIFICATION_READ: "notification:read",
    SYNC_REQUIRED: "sync:required",
    // client -> server
    TYPING_START: "typing:start",
    TYPING_STOP: "typing:stop",
    MESSAGE_DELIVERED: "message:delivered",
};
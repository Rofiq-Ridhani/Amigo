// resources/js/app.js — entry Vite
import { initChatIndex } from "./chat-index.js";

// Halaman chat saja (login/register tidak punya #user-list)
if (window.AMIGO && document.getElementById("user-list")) {
    initChatIndex();
}
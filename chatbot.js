// AI Chatbot widget logic for Tâm An CFO landing page.
// Talks to /api/chat (a same-origin serverless proxy) — never calls the LLM API directly from the browser.
(function () {
    "use strict";

    var WELCOME_MESSAGE =
        "Xin chào 👋 Mình là trợ lý ảo của **Tâm An CFO**. Mình có thể giúp bạn tìm hiểu về dịch vụ CFO thuê ngoài, quy trình làm việc hoặc thông tin liên hệ. Bạn muốn hỏi gì nào?";

    var toggleBtn = document.getElementById("chatbot-toggle");
    var windowEl = document.getElementById("chatbot-window");
    var closeBtn = document.getElementById("chatbot-close");
    var refreshBtn = document.getElementById("chatbot-refresh");
    var messagesEl = document.getElementById("chatbot-messages");
    var inputEl = document.getElementById("chatbot-input");
    var sendBtn = document.getElementById("chatbot-send");

    var history = []; // { role: 'user' | 'assistant', content: string }
    var isSending = false;

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function renderBotBubble(markdownText) {
        var bubble = document.createElement("div");
        bubble.className = "chat-bubble bot chat-markdown";
        bubble.innerHTML = window.marked ? window.marked.parse(markdownText) : markdownText;
        messagesEl.appendChild(bubble);
        scrollToBottom();
    }

    function renderUserBubble(text) {
        var bubble = document.createElement("div");
        bubble.className = "chat-bubble user";
        bubble.textContent = text;
        messagesEl.appendChild(bubble);
        scrollToBottom();
    }

    function showTyping() {
        var typing = document.createElement("div");
        typing.className = "typing-indicator";
        typing.id = "chatbot-typing";
        typing.innerHTML = "<span></span><span></span><span></span>";
        messagesEl.appendChild(typing);
        scrollToBottom();
    }

    function hideTyping() {
        var typing = document.getElementById("chatbot-typing");
        if (typing) typing.remove();
    }

    function resetConversation() {
        messagesEl.innerHTML = "";
        history = [];
        renderBotBubble(WELCOME_MESSAGE);
    }

    function openChat() {
        windowEl.classList.remove("hidden");
        requestAnimationFrame(function () {
            windowEl.classList.add("open");
        });
        inputEl.focus();
    }

    function closeChat() {
        windowEl.classList.remove("open");
        setTimeout(function () {
            windowEl.classList.add("hidden");
        }, 300);
    }

    function sendMessage() {
        var text = inputEl.value.trim();
        if (!text || isSending) return;

        renderUserBubble(text);
        history.push({ role: "user", content: text });
        inputEl.value = "";
        isSending = true;
        sendBtn.disabled = true;
        showTyping();

        fetch("/api/chat", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ messages: history }),
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                hideTyping();
                if (!result.ok) {
                    renderBotBubble("⚠️ " + (result.data.error || "Đã có lỗi xảy ra, vui lòng thử lại sau."));
                    return;
                }
                var reply = result.data.reply || "Xin lỗi, mình chưa có câu trả lời phù hợp.";
                renderBotBubble(reply);
                history.push({ role: "assistant", content: reply });
            })
            .catch(function () {
                hideTyping();
                renderBotBubble("⚠️ Không thể kết nối tới máy chủ. Vui lòng kiểm tra mạng và thử lại.");
            })
            .finally(function () {
                isSending = false;
                sendBtn.disabled = false;
            });
    }

    toggleBtn.addEventListener("click", function () {
        if (windowEl.classList.contains("open")) {
            closeChat();
        } else {
            openChat();
        }
    });

    closeBtn.addEventListener("click", closeChat);

    refreshBtn.addEventListener("click", function () {
        refreshBtn.classList.add("spinning");
        resetConversation();
        setTimeout(function () {
            refreshBtn.classList.remove("spinning");
        }, 500);
    });

    sendBtn.addEventListener("click", sendMessage);
    inputEl.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
            e.preventDefault();
            sendMessage();
        }
    });

    resetConversation();
})();

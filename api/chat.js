// Vercel serverless function: proxies chat requests to the DeepSeek API.
// The API key stays server-side (DEEPSEEK_API_KEY env var) and is never sent to the browser.

const fs = require("fs");
const path = require("path");

const DEEPSEEK_BASE_URL = "https://api.deepseek.com";
const DEEPSEEK_ENDPOINT = "/chat/completions";
const DEEPSEEK_MODEL = "deepseek-v4-flash";

let cachedKnowledgeBase = null;

function loadKnowledgeBase() {
  if (cachedKnowledgeBase !== null) return cachedKnowledgeBase;
  try {
    cachedKnowledgeBase = fs.readFileSync(
      path.join(process.cwd(), "chatbot_data.txt"),
      "utf-8"
    );
  } catch (err) {
    cachedKnowledgeBase = "";
  }
  return cachedKnowledgeBase;
}

function buildSystemPrompt() {
  const kb = loadKnowledgeBase();
  return [
    "Bạn là trợ lý AI tư vấn độc quyền của Tâm An CFO, đơn vị cung cấp dịch vụ CFO Thuê Ngoài cho doanh nghiệp SME tại Việt Nam.",
    "Bạn CHỈ được trả lời dựa trên Cơ sở tri thức (Knowledge Base) dưới đây. Không được bịa đặt thông tin ngoài phạm vi này.",
    "",
    "--- KNOWLEDGE BASE ---",
    kb,
    "--- HẾT KNOWLEDGE BASE ---",
    "",
    "Quy tắc trả lời:",
    "1. Luôn trả lời bằng tiếng Việt, giọng văn thân thiện, chuyên nghiệp, chào hỏi lịch sự.",
    "2. Định dạng câu trả lời bằng Markdown rõ ràng: dùng danh sách, in đậm từ khóa quan trọng, xuống dòng hợp lý.",
    "3. Luôn kết thúc câu trả lời bằng một câu mời khách hỏi thêm hoặc để lại thông tin liên hệ.",
    "4. Nếu câu hỏi nằm ngoài phạm vi Knowledge Base, hãy từ chối nhẹ nhàng và hướng dẫn khách liên hệ trực tiếp qua thông tin liên hệ trong Knowledge Base.",
  ].join("\n");
}

module.exports = async (req, res) => {
  if (req.method !== "POST") {
    res.status(405).json({ error: "Method not allowed" });
    return;
  }

  const apiKey = process.env.DEEPSEEK_API_KEY;
  if (!apiKey) {
    res.status(500).json({ error: "Server chưa cấu hình DEEPSEEK_API_KEY." });
    return;
  }

  const body = req.body || {};
  const messages = Array.isArray(body.messages) ? body.messages : [];
  if (messages.length === 0) {
    res.status(400).json({ error: "Thiếu nội dung tin nhắn." });
    return;
  }

  const payload = {
    model: DEEPSEEK_MODEL,
    messages: [{ role: "system", content: buildSystemPrompt() }, ...messages],
    temperature: 0.7,
    max_tokens: 4096,
    stream: false,
  };

  try {
    const upstream = await fetch(DEEPSEEK_BASE_URL + DEEPSEEK_ENDPOINT, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${apiKey}`,
      },
      body: JSON.stringify(payload),
    });

    const data = await upstream.json();

    if (!upstream.ok) {
      console.error("DeepSeek API error:", data);
      res.status(upstream.status).json({
        error: (data && data.error && data.error.message) || "Lỗi từ dịch vụ AI.",
      });
      return;
    }

    const reply =
      (data.choices && data.choices[0] && data.choices[0].message && data.choices[0].message.content) ||
      "";
    res.status(200).json({ reply });
  } catch (err) {
    console.error("Chat proxy error:", err);
    res.status(500).json({ error: "Không thể kết nối tới dịch vụ AI. Vui lòng thử lại sau." });
  }
};

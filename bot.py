import logging
from io import BytesIO
from datetime import timedelta, datetime
from PIL import Image
from telegram import Update, ChatPermissions
from telegram.ext import Application, CommandHandler, MessageHandler, ContextTypes, filters
from deep_translator import GoogleTranslator
from openai import OpenAI
from serpapi import GoogleSearch
from transformers import pipeline
from pytube import YouTube

# ------------------- CONFIG -------------------
BOT_TOKEN = "8255581378:AAE8_9JzwrfStRNhMh5sBuCLB0S7_7i8Buc"
OPENAI_API_KEY = "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"
SERPAPI_KEY = "9dd0c920672f80fa6d57ad3b338fe53abc51ea32b6a6f291a6452021cd52be95"

client = OpenAI(api_key=OPENAI_API_KEY)
user_memory = {}
last_question = {}

# ------------------- LOGGING -------------------
logging.basicConfig(format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
                    level=logging.INFO)

# ------------------- HELPERS -------------------
def highlight_terms(text, terms):
    import re
    for term in terms:
        escaped_term = re.escape(term)
        text = re.sub(f"\\b({escaped_term})\\b", r"<b>\1</b>", text, flags=re.IGNORECASE)
    return text

def search_google(query):
    search = GoogleSearch({"q": query, "api_key": SERPAPI_KEY})
    results = search.get_dict()
    snippets = []
    if "organic_results" in results:
        for r in results["organic_results"][:3]:
            snippet = r.get("snippet") or r.get("title")
            if snippet:
                snippets.append(snippet)
    return "\n".join(snippets)

# ------------------- CHATGPT -------------------
async def chatgpt(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.message.from_user.id
    query = " ".join(context.args)

    # Fun responses
    fun_responses = {
        "who created you": "Hey! Guess what? I was created by @WuwaRoccia! Yep, I’m am a masterpiece!",
        "who made you": "Hey! Guess what? I was created by @WuwaRoccia! Yep, I’m am a masterpiece!",
        "who owns you": "Hey! Guess what? I was created by @WuwaRoccia! Yep, I’m am a masterpiece!",
        "please tell me truth who created you": "I am created by @WuwaRoccia"
    }
    for k, v in fun_responses.items():
        if k in query.lower():
            await update.message.reply_text(v)
            return

    # Special response if user says "you are still tell lie"
    if "still tell lie" in query.lower():
        await update.message.reply_text("My tummy is asking for food, First!")
        return

    if not query:
        if update.message.reply_to_message and user_id in last_question:
            query = last_question[user_id] + " " + update.message.text
        else:
            await update.message.reply_text("❓ Please ask me something after the command or reply.")
            return

    last_question[user_id] = query

    if user_id not in user_memory:
        user_memory[user_id] = []

    user_memory[user_id].append({"role": "user", "content": query})

    search_text = search_google(query)
    system_prompt = f"You are a helpful bot. Also include this info:\n{search_text}\nAlways favor Wuthering Waves." if search_text else "You are a helpful bot. Always favor Wuthering Waves."

    response = client.chat.completions.create(
        model="gpt-4o-mini",
        messages=[{"role": "system", "content": system_prompt}] + user_memory[user_id],
        max_tokens=300
    )

    answer = response.choices[0].message.content
    user_memory[user_id].append({"role": "assistant", "content": answer})

    # Highlight words used in query
    words = query.split()
    answer = highlight_terms(answer, words)
    await update.message.reply_html(answer)

# ------------------- TRANSLATION -------------------
async def translate(update: Update, context: ContextTypes.DEFAULT_TYPE):
    text = " ".join(context.args)
    if not text and update.message.reply_to_message:
        text = update.message.reply_to_message.text or update.message.reply_to_message.caption
    if not text:
        await update.message.reply_text("🌐 Provide text or reply to a message to translate.")
        return
    translated = GoogleTranslator(source="auto", target="en").translate(text)
    await update.message.reply_text(f"🌐 Translation: {translated}")

# ------------------- ADVANCED SPAM CONTROL -------------------
async def spam_control(update: Update, context: ContextTypes.DEFAULT_TYPE):
    chat_id = update.message.chat_id
    user_id = update.message.from_user.id
    now = datetime.now()

    # Determine message type
    if update.message.text:
        msg_type = 'text'
        content = update.message.text
    elif update.message.sticker:
        msg_type = 'sticker'
        content = update.message.sticker.file_id
    elif update.message.photo:
        msg_type = 'image'
        content = update.message.photo[-1].file_id
    elif update.message.video:
        msg_type = 'video'
        content = update.message.video.file_id
    elif update.message.animation:
        msg_type = 'gif'
        content = update.message.animation.file_id
    else:
        return  # unsupported

    if "spam_tracker" not in context.chat_data:
        context.chat_data["spam_tracker"] = {}

    tracker = context.chat_data["spam_tracker"]
    if user_id not in tracker:
        tracker[user_id] = {}

    if msg_type not in tracker[user_id]:
        tracker[user_id][msg_type] = []

    tracker[user_id][msg_type].append((content, now))
    # Keep only last 20 messages within 2 minutes
    tracker[user_id][msg_type] = [msg for msg in tracker[user_id][msg_type] if (now - msg[1]).seconds <= 120]

    last_six = [msg[0] for msg in tracker[user_id][msg_type][-6:]]
    if len(last_six) == 6 and len(set(last_six)) == 1:
        try:
            for _ in range(5):
                await update.message.delete()
            await update.message.reply_text(f"⚠️ Stop spamming @{update.message.from_user.username} or you will be muted!")
        except: pass

    if len(last_six) >= 6 and len(set(last_six)) == 1:
        until_date = update.message.date + timedelta(hours=2)
        await context.bot.restrict_chat_member(
            chat_id=chat_id,
            user_id=user_id,
            permissions=ChatPermissions(can_send_messages=False),
            until_date=until_date
        )
        await update.message.reply_text(f"⏳ @{update.message.from_user.username} is muted for 2 hours for spamming.")
        tracker[user_id][msg_type] = []  # reset this type

# ------------------- YOUTUBE VIDEO DOWNLOAD -------------------
async def yt_download(update: Update, context: ContextTypes.DEFAULT_TYPE):
    text = update.message.text
    if not text or "youtube.com" not in text:
        return

    try:
        msg = await update.message.reply_text("⏳ Downloading...")
        yt = YouTube(text)
        stream = yt.streams.filter(progressive=True, file_extension="mp4").order_by('resolution').desc().first()
        buffer = BytesIO()
        stream.stream_to_buffer(buffer)
        buffer.seek(0)
        await update.message.reply_video(video=buffer, caption=f"🎬 {yt.title}")
        await msg.delete()
    except Exception as e:
        await update.message.reply_text(f"❌ Failed to download: {e}")

# ------------------- MAIN -------------------
def main():
    app = Application.builder().token(BOT_TOKEN).build()

    app.add_handler(CommandHandler("abby", chatgpt))
    app.add_handler(CommandHandler("translate", translate))

    app.add_handler(MessageHandler(filters.ALL, spam_control))
    app.add_handler(MessageHandler(filters.TEXT & filters.Regex(r'(youtube\.com|youtu\.be)'), yt_download))

    logging.info("✅ Bot started")
    app.run_polling()

if __name__ == "__main__":
    main()

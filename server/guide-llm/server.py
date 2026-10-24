"""案内チャットの LLM(自宅 PC。2026-10-24、利用者の企画書「模擬店案内・会場内ルート案内システム」)。

Unsloth で模型を 1 つ読み、/v1/chat/completions の形(OpenAI と同じ形の一部)で答える。
ngrok でこの口を https で出し、VPS の PHP(src/lib/guide-chat.php の km_guide_llm_chat)が Bearer の鍵を付けて呼ぶ。

使ってよい模型は 4 つだけ(利用者の決定): Gemma4 E4B / 12B QAT / Qwen3.5-9B / GPT-OSS-20B。
名前は guide-llm.env の GUIDE_LLM_MODEL に、Hugging Face の unsloth の名前で入れる(ALLOWED_FAMILIES で確かめる)。

守ること(企画書 §8):
  - 鍵(KM_GUIDE_LLM_KEY。32 字以上)が無い要求は 401。鍵は定数時間で比べる
  - 待ち受けは 127.0.0.1 だけ(外へは ngrok だけが出す)
  - 同時に 1 件ずつ(順番待ち)・出す字数の上限・受ける本文の上限
  - **質問と答えの本文はログに書かない**(書くのは時間と字数だけ)

起動は start-guide-llm.ps1(WSL の Ubuntu で動かす)。
"""

from __future__ import annotations

import logging
import os
import re
import secrets
import threading
import time
from typing import Any

import uvicorn
from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse

LOG = logging.getLogger("guide-llm")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

# 利用者が決めた 4 つ。名前にこの言葉が入っているものだけ読む(ほかの模型を取り違えて読まない)
ALLOWED_FAMILIES = {
    "gemma4-e4b": re.compile(r"gemma-?4.*e4b", re.IGNORECASE),
    "gemma-12b-qat": re.compile(r"gemma.*12b.*qat|gemma.*qat.*12b", re.IGNORECASE),
    "qwen3.5-9b": re.compile(r"qwen3\.?5.*9b", re.IGNORECASE),
    "gpt-oss-20b": re.compile(r"gpt-oss.*20b", re.IGNORECASE),
}

MODEL_NAME = os.environ.get("GUIDE_LLM_MODEL", "").strip()
API_KEY = os.environ.get("KM_GUIDE_LLM_KEY", "").strip()
MAX_SEQ = int(os.environ.get("GUIDE_LLM_MAX_SEQ", "4096"))
PORT = int(os.environ.get("GUIDE_LLM_PORT", "8000"))
# 1 回に出す字数(トークン)の上限。PHP は 160 と 300 を頼む
MAX_NEW_TOKENS_CAP = 512
# 受ける本文の上限。PHP が送るのは記録 5 件ぶんの短い JSON
MAX_BODY_BYTES = 32 * 1024
MAX_MESSAGES = 20


def family_of(name: str) -> str | None:
    for family, pattern in ALLOWED_FAMILIES.items():
        if pattern.search(name):
            return family
    return None


def clean_output(text: str) -> str:
    """模型ごとの考えの部分を落とす。Qwen の <think>…</think>、GPT-OSS の analysis → final の final だけを残す"""
    text = re.sub(r"<think>.*?</think>", "", text, flags=re.DOTALL)
    # GPT-OSS(harmony)の形: 特別な字を落として読むと "analysis…assistantfinal…" になる。最後の final の後ろだけ
    if "assistantfinal" in text:
        text = text.rsplit("assistantfinal", 1)[1]
    return text.strip()


class Engine:
    """模型を 1 つ持ち、1 件ずつ答える"""

    def __init__(self, name: str) -> None:
        # Unsloth は import の順で速くなるところがある(公式の書き方どおり、transformers より先に読む)
        from unsloth import FastLanguageModel  # noqa: WPS433

        self.lock = threading.Lock()
        started = time.time()
        try:
            self.model, self.tokenizer = FastLanguageModel.from_pretrained(
                model_name=name, max_seq_length=MAX_SEQ, load_in_4bit=True
            )
            FastLanguageModel.for_inference(self.model)
        except Exception:  # Gemma などは FastModel で読む(Unsloth の案内どおり)
            from unsloth import FastModel  # noqa: WPS433

            self.model, self.tokenizer = FastModel.from_pretrained(
                model_name=name, max_seq_length=MAX_SEQ, load_in_4bit=True
            )
            FastModel.for_inference(self.model)
        LOG.info("model loaded in %.1fs", time.time() - started)

    def _inputs(self, messages: list[dict[str, Any]]):
        kwargs = dict(
            add_generation_prompt=True,
            return_tensors="pt",
            return_dict=True,
            tokenize=True,
            # Qwen は考えを出さない・GPT-OSS は考えを短く。使わない模型は無視する(テンプレートの変数として渡るだけ)
            enable_thinking=False,
            reasoning_effort="low",
        )
        try:
            return self.tokenizer.apply_chat_template(messages, **kwargs)
        except Exception:
            # 画像も読む模型(Gemma)の processor は、本文を [{"type":"text","text":…}] の形で受ける
            converted = [
                {"role": m["role"], "content": [{"type": "text", "text": m["content"]}]} for m in messages
            ]
            return self.tokenizer.apply_chat_template(converted, **kwargs)

    def chat(self, messages: list[dict[str, Any]], max_new_tokens: int, temperature: float) -> tuple[str, int, int]:
        with self.lock:
            inputs = self._inputs(messages).to(self.model.device)
            prompt_tokens = int(inputs["input_ids"].shape[-1])
            output = self.model.generate(
                **inputs,
                max_new_tokens=max_new_tokens,
                do_sample=temperature > 0,
                temperature=max(temperature, 1e-5),
                top_p=0.9,
            )
            new_tokens = output[0][prompt_tokens:]
            decoder = getattr(self.tokenizer, "tokenizer", self.tokenizer)
            text = decoder.decode(new_tokens, skip_special_tokens=True)
            return clean_output(text), prompt_tokens, int(new_tokens.shape[-1])


def validate_messages(raw: Any) -> list[dict[str, str]]:
    if not isinstance(raw, list) or not 1 <= len(raw) <= MAX_MESSAGES:
        raise HTTPException(400, "messages")
    messages = []
    for item in raw:
        if not isinstance(item, dict) or item.get("role") not in ("system", "user", "assistant"):
            raise HTTPException(400, "role")
        content = item.get("content")
        if not isinstance(content, str):
            raise HTTPException(400, "content")
        messages.append({"role": item["role"], "content": content})
    return messages


def create_app(engine: Engine | None) -> FastAPI:
    app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)

    def authorized(request: Request) -> bool:
        header = request.headers.get("authorization", "")
        token = header[7:].strip() if header.lower().startswith("bearer ") else ""
        return bool(API_KEY) and secrets.compare_digest(token.encode(), API_KEY.encode())

    @app.get("/health")
    def health() -> dict[str, bool]:
        # 中身は言わない(模型の名前も出さない)
        return {"ok": engine is not None}

    @app.post("/v1/chat/completions")
    async def chat(request: Request):
        if not authorized(request):
            return JSONResponse({"error": "unauthorized"}, status_code=401)
        body = await request.body()
        if len(body) > MAX_BODY_BYTES:
            return JSONResponse({"error": "too large"}, status_code=413)
        try:
            payload = await request.json()
        except Exception:
            return JSONResponse({"error": "json"}, status_code=400)
        messages = validate_messages(payload.get("messages"))
        max_tokens = payload.get("max_tokens", 256)
        if not isinstance(max_tokens, int) or max_tokens < 1:
            max_tokens = 256
        max_tokens = min(max_tokens, MAX_NEW_TOKENS_CAP)
        temperature = payload.get("temperature", 0.2)
        if not isinstance(temperature, (int, float)) or not 0 <= temperature <= 1.5:
            temperature = 0.2
        if engine is None:
            return JSONResponse({"error": "not ready"}, status_code=503)
        started = time.time()
        # 生成は重いので、待っている間もほかの要求(health)を受けられるよう別の糸で動かす
        import anyio  # noqa: WPS433

        text, prompt_tokens, completion_tokens = await anyio.to_thread.run_sync(
            engine.chat, messages, max_tokens, float(temperature)
        )
        # **本文は書かない**。時間と字数だけ
        LOG.info("answered in %.1fs (prompt %d tokens, output %d tokens)", time.time() - started, prompt_tokens, completion_tokens)
        return {
            "object": "chat.completion",
            "choices": [{"index": 0, "message": {"role": "assistant", "content": text}, "finish_reason": "stop"}],
            "usage": {"prompt_tokens": prompt_tokens, "completion_tokens": completion_tokens},
        }

    return app


def main() -> None:
    if len(API_KEY) < 32:
        raise SystemExit("KM_GUIDE_LLM_KEY は 32 字以上にしてください(guide-llm.env)")
    family = family_of(MODEL_NAME)
    if family is None:
        raise SystemExit(
            "GUIDE_LLM_MODEL は Gemma4 E4B / 12B QAT / Qwen3.5-9B / GPT-OSS-20B のどれか(unsloth の名前)にしてください: "
            + repr(MODEL_NAME)
        )
    LOG.info("loading %s (%s)", MODEL_NAME, family)
    engine = Engine(MODEL_NAME)
    # 外へは ngrok だけが出す。ここは 127.0.0.1 だけで待つ
    uvicorn.run(create_app(engine), host="127.0.0.1", port=PORT, log_level="warning", access_log=False)


if __name__ == "__main__":
    main()

"""案内チャットの門番(自宅 PC。2026-10-24、利用者の企画書「模擬店案内・会場内ルート案内システム」)。

2026-10-25 に作り直した(利用者の指示「WSL は使わない」「Unsloth に API リクエストする」)。
模型はこのプログラムでは読まない。**利用者が動かしている Unsloth の API**(OpenAI と同じ形の /v1/chat/completions)へ
頼みを渡し、返事を返すだけ。Python の標準ライブラリだけで動く(Windows の python でそのまま。入れるものは無い)。

    VPS の PHP(src/lib/guide-chat.php)─ https ─ ngrok ─ この門番(127.0.0.1)─ Unsloth の API(この PC の中)

門番を挟む理由(企画書 §8「API キーや秘密情報を管理」「LLM の API を無制限に呼び出せないようにする」):
  - ngrok の URL は外から誰でも叩ける。**鍵(KM_GUIDE_LLM_KEY)の無い頼みは 401** で、Unsloth へ渡さない(定数時間で比べる)
  - 受ける本文・頼みの数(メッセージ)・出す字数に上限を置く。同時に 1 件ずつ渡す(GPU を取り合わない)
  - 模型の名前は**こちらで決めて付け直す**(使ってよい 4 つ: Gemma4 E4B / 12B QAT / Qwen3.5-9B / GPT-OSS-20B だけ)
  - Qwen の <think>…</think>、GPT-OSS の考えの部分は落として返す
  - **質問と答えの本文はログに書かない**(書くのは時間と字数だけ)

起動は start-guide-llm.ps1(設定は guide-llm.env)。
"""

from __future__ import annotations

import json
import logging
import os
import re
import secrets
import threading
import time
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any
from urllib.parse import urlsplit

LOG = logging.getLogger("guide-llm")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

# 利用者が決めた 4 つ。名前にこの言葉が入っているものだけ通す(ほかの模型を取り違えて使わない)
ALLOWED_FAMILIES = {
    "gemma4-e4b": re.compile(r"gemma-?4.*e4b", re.IGNORECASE),
    "gemma-12b-qat": re.compile(r"gemma.*12b.*qat|gemma.*qat.*12b", re.IGNORECASE),
    "qwen3.5-9b": re.compile(r"qwen3\.?5.*9b", re.IGNORECASE),
    "gpt-oss-20b": re.compile(r"gpt-oss.*20b", re.IGNORECASE),
}

MODEL_NAME = os.environ.get("GUIDE_LLM_MODEL", "").strip()
API_KEY = os.environ.get("KM_GUIDE_LLM_KEY", "").strip()
PORT = int(os.environ.get("GUIDE_LLM_PORT", "8888"))
UPSTREAM = os.environ.get("UNSLOTH_API_URL", "").strip().rstrip("/")
UPSTREAM_KEY = os.environ.get("UNSLOTH_API_KEY", "").strip()
# 1 回に出す字数(トークン)の上限。PHP は 160 と 300 を頼む
MAX_NEW_TOKENS_CAP = 512
# 受ける本文の上限。PHP が送るのは記録 5 件ぶんの短い JSON
MAX_BODY_BYTES = 32 * 1024
MAX_MESSAGES = 20
# Unsloth の返事を待つ時間(PHP は 20 秒・30 秒で切るので、それより少し長く)
UPSTREAM_TIMEOUT = 40
# Unsloth の返事の大きさの上限
MAX_UPSTREAM_BYTES = 256 * 1024

UPSTREAM_LOCK = threading.Lock()


class BadRequest(Exception):
    def __init__(self, status: int, message: str) -> None:
        super().__init__(message)
        self.status = status


def family_of(name: str) -> str | None:
    for family, pattern in ALLOWED_FAMILIES.items():
        if pattern.search(name):
            return family
    return None


def is_local_url(url: str) -> bool:
    """Unsloth の API はこの PC の中だけ(外のサーバーへ鍵や質問を流さない)"""
    parts = urlsplit(url)
    return parts.scheme in ("http", "https") and parts.hostname in ("127.0.0.1", "localhost", "::1")


def clean_output(text: str) -> str:
    """模型ごとの考えの部分を落とす。Qwen の <think>…</think>、GPT-OSS の analysis → final の final だけを残す"""
    text = re.sub(r"<think>.*?</think>", "", text, flags=re.DOTALL)
    # 閉じていない <think>(字数の上限で切れた)は、そこから後ろを落とす
    text = re.sub(r"<think>.*$", "", text, flags=re.DOTALL)
    if "assistantfinal" in text:
        text = text.rsplit("assistantfinal", 1)[1]
    return text.strip()


def authorized(header: str) -> bool:
    token = header[7:].strip() if header.lower().startswith("bearer ") else ""
    return bool(API_KEY) and secrets.compare_digest(token.encode(), API_KEY.encode())


def build_upstream_request(payload: Any) -> dict[str, Any]:
    """受けた頼みを確かめ、Unsloth へ渡す形に作り直す(余計な項目は渡さない)"""
    if not isinstance(payload, dict):
        raise BadRequest(400, "json")
    raw = payload.get("messages")
    if not isinstance(raw, list) or not 1 <= len(raw) <= MAX_MESSAGES:
        raise BadRequest(400, "messages")
    messages = []
    for item in raw:
        if not isinstance(item, dict) or item.get("role") not in ("system", "user", "assistant"):
            raise BadRequest(400, "role")
        if not isinstance(item.get("content"), str):
            raise BadRequest(400, "content")
        messages.append({"role": item["role"], "content": item["content"]})
    max_tokens = payload.get("max_tokens", 256)
    if not isinstance(max_tokens, int) or isinstance(max_tokens, bool) or max_tokens < 1:
        max_tokens = 256
    temperature = payload.get("temperature", 0.2)
    if not isinstance(temperature, (int, float)) or isinstance(temperature, bool) or not 0 <= temperature <= 1.5:
        temperature = 0.2
    return {
        "model": MODEL_NAME,
        "messages": messages,
        "max_tokens": min(max_tokens, MAX_NEW_TOKENS_CAP),
        "temperature": float(temperature),
        "stream": False,
        # Qwen は考えを出さない(対応していない API は無視する)
        "chat_template_kwargs": {"enable_thinking": False},
    }


def call_upstream(body: dict[str, Any]) -> dict[str, Any]:
    request = urllib.request.Request(
        UPSTREAM + "/v1/chat/completions",
        data=json.dumps(body, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json", **({"Authorization": "Bearer " + UPSTREAM_KEY} if UPSTREAM_KEY else {})},
        method="POST",
    )
    with UPSTREAM_LOCK:   # 同時に 1 件ずつ
        with urllib.request.urlopen(request, timeout=UPSTREAM_TIMEOUT) as response:
            data = response.read(MAX_UPSTREAM_BYTES + 1)
    if len(data) > MAX_UPSTREAM_BYTES:
        raise BadRequest(502, "upstream too large")
    decoded = json.loads(data)
    text = decoded["choices"][0]["message"]["content"]
    if not isinstance(text, str):
        raise BadRequest(502, "upstream content")
    usage = decoded.get("usage") if isinstance(decoded.get("usage"), dict) else {}
    return {"text": clean_output(text), "usage": usage}


def upstream_alive() -> bool:
    try:
        request = urllib.request.Request(
            UPSTREAM + "/v1/models",
            headers={"Authorization": "Bearer " + UPSTREAM_KEY} if UPSTREAM_KEY else {},
        )
        with urllib.request.urlopen(request, timeout=3) as response:
            return response.status == 200
    except (urllib.error.URLError, OSError, ValueError):
        return False


class Handler(BaseHTTPRequestHandler):
    server_version = "guide-llm"
    sys_version = ""

    def log_message(self, format: str, *args: Any) -> None:  # noqa: A002
        # 既定の 1 行ログ(URL など)も出さない。時間と字数だけを下で書く
        return

    def send_json(self, status: int, body: dict[str, Any]) -> None:
        data = json.dumps(body, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self) -> None:  # noqa: N802
        if self.path == "/health":
            # 中身は言わない(模型の名前も出さない)。Unsloth が答えるかだけ
            self.send_json(200, {"ok": upstream_alive()})
            return
        self.send_json(404, {"error": "not found"})

    def do_POST(self) -> None:  # noqa: N802
        if self.path != "/v1/chat/completions":
            self.send_json(404, {"error": "not found"})
            return
        if not authorized(self.headers.get("Authorization", "")):
            self.send_json(401, {"error": "unauthorized"})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            length = -1
        if length < 0 or length > MAX_BODY_BYTES:
            self.send_json(413, {"error": "too large"})
            return
        started = time.time()
        # 受けた頼みの誤りは 400
        try:
            body = build_upstream_request(json.loads(self.rfile.read(length) or b"null"))
        except BadRequest as error:
            self.send_json(error.status, {"error": str(error)})
            return
        except ValueError:
            self.send_json(400, {"error": "json"})
            return
        # Unsloth の返事の誤り・届かないときは 502
        try:
            result = call_upstream(body)
        except BadRequest as error:
            self.send_json(error.status, {"error": str(error)})
            return
        except (ValueError, KeyError, IndexError, TypeError):
            self.send_json(502, {"error": "upstream reply"})
            return
        except (urllib.error.URLError, OSError) as error:
            LOG.warning("unsloth API unreachable: %s", type(error).__name__)
            self.send_json(502, {"error": "upstream"})
            return
        usage = result["usage"]
        # **本文は書かない**。時間と字数だけ
        LOG.info("answered in %.1fs (prompt %s tokens, output %s tokens)", time.time() - started,
                 usage.get("prompt_tokens", "?"), usage.get("completion_tokens", "?"))
        self.send_json(200, {
            "object": "chat.completion",
            "choices": [{"index": 0, "message": {"role": "assistant", "content": result["text"]}, "finish_reason": "stop"}],
            "usage": usage,
        })


def main() -> None:
    if len(API_KEY) < 32:
        raise SystemExit("KM_GUIDE_LLM_KEY は 32 字以上にしてください(guide-llm.env)")
    if family_of(MODEL_NAME) is None:
        raise SystemExit(
            "GUIDE_LLM_MODEL は Gemma4 E4B / 12B QAT / Qwen3.5-9B / GPT-OSS-20B のどれかにしてください: " + repr(MODEL_NAME)
        )
    if not is_local_url(UPSTREAM):
        raise SystemExit("UNSLOTH_API_URL はこの PC の中(http://127.0.0.1:番号 など)にしてください: " + repr(UPSTREAM))
    LOG.info("model %s (%s) / waiting on 127.0.0.1:%d", MODEL_NAME, family_of(MODEL_NAME), PORT)
    # 外へは ngrok だけが出す。ここは 127.0.0.1 だけで待つ
    ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()


if __name__ == "__main__":
    main()

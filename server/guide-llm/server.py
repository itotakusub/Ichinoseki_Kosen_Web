"""案内チャットの門番(自宅 PC。2026-10-24、利用者の企画書「模擬店案内・会場内ルート案内システム」)。

2026-10-25 に作り直した(利用者の指示「WSL は使わない」「Unsloth に API リクエストする」)。
同じ日に、頼む先を Unsloth から **Bionic** に替えた(利用者「Unsloth にバグが報告されていて使えないので Bionic に代替」)。
模型はこのプログラムでは読まない。**利用者が動かしている Bionic の API**(OpenAI と同じ形の /v1/chat/completions)へ
頼みを渡し、返事を返すだけ。Python の標準ライブラリだけで動く(Windows の python でそのまま。入れるものは無い)。

    VPS の PHP(src/lib/guide-chat.php)─ https ─ ngrok ─ この門番(127.0.0.1)─ Bionic の API(LLM_API_URL。この PC の中)

門番を挟む理由(企画書 §8「API キーや秘密情報を管理」「LLM の API を無制限に呼び出せないようにする」):
  - ngrok の URL は外から誰でも叩ける。**鍵(KM_GUIDE_LLM_KEY)の無い頼みは 401** で、Bionic へ渡さない(定数時間で比べる)
  - 受ける本文・頼みの数(メッセージ)・出す字数に上限を置く。同時に 1 件ずつ渡す(GPU を取り合わない)
  - 模型の名前は**こちらで決めて付け直す**(使ってよい 4 つ: Gemma4 E4B / 12B QAT / Qwen3.5-9B / GPT-OSS-20B だけ)。
    Bionic の模型の識別名(例 3885219b…)は名前から 4 つのどれか分からないので、GUIDE_LLM_FAMILY で言い切る。
    起こすときに、その識別名が Bionic の /v1/models にあるかも確かめる(違う模型を読んだまま出さない)
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

# 利用者が決めた 4 つ。名前にこの言葉が入っているか、GUIDE_LLM_FAMILY でこの鍵を言い切ったものだけ通す
# (ほかの模型を取り違えて使わない)
ALLOWED_FAMILIES = {
    "gemma4-e4b": re.compile(r"gemma-?4.*e4b", re.IGNORECASE),
    "gemma-12b-qat": re.compile(r"gemma.*12b.*qat|gemma.*qat.*12b", re.IGNORECASE),
    "qwen3.5-9b": re.compile(r"qwen3\.?5.*9b", re.IGNORECASE),
    "gpt-oss-20b": re.compile(r"gpt-oss.*20b", re.IGNORECASE),
}

MODEL_NAME = os.environ.get("GUIDE_LLM_MODEL", "").strip()
MODEL_FAMILY = os.environ.get("GUIDE_LLM_FAMILY", "").strip().lower()
API_KEY = os.environ.get("KM_GUIDE_LLM_KEY", "").strip()
PORT = int(os.environ.get("GUIDE_LLM_PORT", "8888"))
UPSTREAM = os.environ.get("LLM_API_URL", "").strip().rstrip("/")
UPSTREAM_KEY = os.environ.get("LLM_API_KEY", "").strip()
# 1 回に出す字数(トークン)の上限。PHP は 160 と 300 を頼む
MAX_NEW_TOKENS_CAP = 512
# 受ける本文の上限。PHP が送るのは記録 5 件ぶんの短い JSON
MAX_BODY_BYTES = 32 * 1024
MAX_MESSAGES = 20
# Bionic の返事を待つ時間(PHP は 20 秒・30 秒で切るので、それより少し長く)
UPSTREAM_TIMEOUT = 40
# Bionic の返事の大きさの上限
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


def resolve_family(name: str, declared: str) -> str | None:
    """模型が 4 つのどれか。言い切った GUIDE_LLM_FAMILY を先に見る。名前からも分かるなら、食い違えば通さない"""
    by_name = family_of(name)
    if not declared:
        return by_name
    if declared not in ALLOWED_FAMILIES or (by_name is not None and by_name != declared):
        return None
    return declared


def is_local_url(url: str) -> bool:
    """Bionic の API はこの PC の中だけ(外のサーバーへ鍵や質問を流さない)"""
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
    """受けた頼みを確かめ、Bionic へ渡す形に作り直す(余計な項目は渡さない)"""
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


def upstream_headers() -> dict[str, str]:
    return {"Authorization": "Bearer " + UPSTREAM_KEY} if UPSTREAM_KEY else {}


def post_upstream(body: dict[str, Any]) -> bytes:
    request = urllib.request.Request(
        UPSTREAM + "/v1/chat/completions",
        data=json.dumps(body, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json", **upstream_headers()},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=UPSTREAM_TIMEOUT) as response:
        return response.read(MAX_UPSTREAM_BYTES + 1)


def call_upstream(body: dict[str, Any]) -> dict[str, Any]:
    with UPSTREAM_LOCK:   # 同時に 1 件ずつ
        try:
            data = post_upstream(body)
        except urllib.error.HTTPError as error:
            # 知らない項目を断る API がある。1 度だけ chat_template_kwargs を外して頼み直す
            if error.code not in (400, 422) or "chat_template_kwargs" not in body:
                raise
            data = post_upstream({k: v for k, v in body.items() if k != "chat_template_kwargs"})
    if len(data) > MAX_UPSTREAM_BYTES:
        raise BadRequest(502, "upstream too large")
    decoded = json.loads(data)
    text = decoded["choices"][0]["message"]["content"]
    if not isinstance(text, str):
        raise BadRequest(502, "upstream content")
    usage = decoded.get("usage") if isinstance(decoded.get("usage"), dict) else {}
    return {"text": clean_output(text), "usage": usage}


def listed_models() -> list[str] | None:
    """Bionic の /v1/models にある模型の識別名。一覧の口が無い API(404)なら None。届かなければ例外"""
    request = urllib.request.Request(UPSTREAM + "/v1/models", headers=upstream_headers())
    try:
        with urllib.request.urlopen(request, timeout=5) as response:
            decoded = json.loads(response.read(MAX_UPSTREAM_BYTES))
    except urllib.error.HTTPError as error:
        if error.code == 404:
            return None
        raise
    items = decoded.get("data") if isinstance(decoded, dict) else None
    if not isinstance(items, list):
        return None
    return [item["id"] for item in items if isinstance(item, dict) and isinstance(item.get("id"), str)]


def upstream_alive() -> bool:
    try:
        listed_models()
        return True
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
            # 中身は言わない(模型の名前も出さない)。Bionic が答えるかだけ
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
        # Bionic の返事の誤り・届かないときは 502
        try:
            result = call_upstream(body)
        except BadRequest as error:
            self.send_json(error.status, {"error": str(error)})
            return
        except (ValueError, KeyError, IndexError, TypeError):
            self.send_json(502, {"error": "upstream reply"})
            return
        except (urllib.error.URLError, OSError) as error:
            LOG.warning("bionic API unreachable: %s", type(error).__name__)
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
    if not MODEL_NAME:
        raise SystemExit("GUIDE_LLM_MODEL(Bionic の模型の識別名)を入れてください(guide-llm.env)")
    family = resolve_family(MODEL_NAME, MODEL_FAMILY)
    if family is None:
        raise SystemExit(
            "模型は Gemma4 E4B / 12B QAT / Qwen3.5-9B / GPT-OSS-20B のどれかにしてください。識別名から分からないときは "
            "GUIDE_LLM_FAMILY に " + " / ".join(ALLOWED_FAMILIES) + " のどれかを入れます: "
            + repr(MODEL_NAME) + " / " + repr(MODEL_FAMILY)
        )
    if not is_local_url(UPSTREAM):
        raise SystemExit("LLM_API_URL はこの PC の中(http://127.0.0.1:番号 など)にしてください: " + repr(UPSTREAM))
    try:
        listed = listed_models()
    except (urllib.error.URLError, OSError, ValueError) as error:
        raise SystemExit(f"Bionic の API が答えません({UPSTREAM}/v1/models: {type(error).__name__})。先に Bionic で模型を読み、API を出してください")
    if listed is None:
        LOG.warning("Bionic の API に模型の一覧が無いので、読み込まれている模型は確かめません")
    elif MODEL_NAME not in listed:
        raise SystemExit(
            "Bionic に GUIDE_LLM_MODEL の模型がありません。読み込まれているのは: " + (", ".join(listed) or "(なし)")
        )
    LOG.info("model %s (%s) / waiting on 127.0.0.1:%d", MODEL_NAME, family, PORT)
    # 外へは ngrok だけが出す。ここは 127.0.0.1 だけで待つ
    ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()


if __name__ == "__main__":
    main()

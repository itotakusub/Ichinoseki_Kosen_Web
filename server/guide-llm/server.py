"""案内チャットの門番(自宅 PC。2026-10-24、利用者の企画書「模擬店案内・会場内ルート案内システム」)。

2026-10-25 に作り直した(利用者の指示「WSL は使わない」「Unsloth に API リクエストする」)。同じ日に Unsloth のバグで Bionic に替えた。
2026-10-29、利用者「Unsloth API が使えるようになったので変更お願いします。あと、AI を選択できるようにしてください」:
**頼み先を 2 つ持つ**(Unsloth と Bionic。どちらもこの PC の中)。既定は GUIDE_LLM_BACKEND / GUIDE_LLM_MODEL で、
Website の管理画面「案内チャット」で選んだ模型があれば、頼みの model(「unsloth:<id>」「bionic:<id>」)でそちらへ渡す。
模型はこのプログラムでは読まない。Python の標準ライブラリだけで動く(Windows の python でそのまま。入れるものは無い)。

    VPS の PHP(src/lib/guide-chat.php)─ https ─ ngrok ─ この門番(127.0.0.1)─ Unsloth の API / Bionic の API(この PC の中)

門番を挟む理由(企画書 §8「API キーや秘密情報を管理」「LLM の API を無制限に呼び出せないようにする」):
  - ngrok の URL は外から誰でも叩ける。**鍵(KM_GUIDE_LLM_KEY)の無い頼みは 401**(定数時間で比べる)
  - 受ける本文・頼みの数(メッセージ)・出す字数に上限を置く。同時に 1 件ずつ渡す(GPU を取り合わない)
  - **模型は頼み先の一覧にあるものだけ**(一覧に無い名前は既定へ回す)。読まれていない模型は、ほかの模型を外してから読み込む
    (GPU に 1 つだけ。Bionic は LM Studio と同じ /api/v1/models/load・unload、Unsloth は /v1/load・/v1/unload)
  - Unsloth には**道具を使わせない**(enable_tools・mcp_enabled を必ず false。Unsloth は Python やターミナルを動かす道具を持つ)
  - 考えの部分(Qwen の <think>…</think>、GPT-OSS の analysis)は落として返す
  - **質問と答えの本文はログに書かない**(書くのは時間と字数と模型の名前だけ)

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

BACKEND_NAMES = ("unsloth", "bionic")
# 考えの指定。Website(管理画面「案内チャット」の「考える」)から受ける値と、guide-llm.env で書ける値
REQUESTABLE_EFFORTS = ("none", "low", "medium", "high")
REASONING_EFFORT_CHOICES = ("none", "low", "medium", "high", "off")
# 知らない項目を断る API には、これを外して頼み直す
OPTIONAL_KEYS = ("chat_template_kwargs", "reasoning_effort", "enable_thinking")

API_KEY = os.environ.get("KM_GUIDE_LLM_KEY", "").strip()
PORT = int(os.environ.get("GUIDE_LLM_PORT", "8192"))
DEFAULT_BACKEND = os.environ.get("GUIDE_LLM_BACKEND", "").strip().lower()
DEFAULT_MODEL = os.environ.get("GUIDE_LLM_MODEL", "").strip()
# 考えを止める指定の既定(空なら none。止められない模型では low に置き換える。off は付けない)
EFFORT_OVERRIDE = os.environ.get("GUIDE_LLM_REASONING_EFFORT", "").strip().lower()
# 模型を読むときの長さ(Bionic の context_length / Unsloth の max_seq_length。空なら頼み先の既定)
CONTEXT_LENGTH = os.environ.get("GUIDE_LLM_CONTEXT_LENGTH", "").strip()
# 1 回に出す字数(トークン)の上限。PHP は 80・160・300・400 を頼む
MAX_NEW_TOKENS_CAP = 512
# 考える模型は、考える分を上乗せする(上乗せしないと考えで字数を使い切り、本文が空になる。2026-10-25、GPT-OSS 20B で測った)
REASONING_HEADROOM = 768
# 受ける本文の上限。PHP が送る一番大きいのは、関係の率を付けさせる一覧(300 件まで)
MAX_BODY_BYTES = 64 * 1024
MAX_MESSAGES = 20
# 返事を待つ時間(PHP は 1 回 20〜40 秒で切るので、それより少し長く)
UPSTREAM_TIMEOUT = 45
# 模型を読み込む・外すのを待つ時間
LOAD_TIMEOUT = 900
MAX_UPSTREAM_BYTES = 512 * 1024
# 頼み先の模型の一覧を読み直す間隔(秒)
CATALOG_TTL = 60

UPSTREAM_LOCK = threading.Lock()   # 会話は同時に 1 件ずつ
LOAD_LOCK = threading.Lock()       # 読み込み・外すのも同時に 1 つ
CATALOG_LOCK = threading.Lock()
CATALOG: dict[str, dict[str, Any]] = {}
CATALOG_AT = 0.0
# 読み込み中・失敗した模型(鍵 → "loading" / "failed")
LOAD_STATE: dict[str, str] = {}


class BadRequest(Exception):
    def __init__(self, status: int, message: str) -> None:
        super().__init__(message)
        self.status = status


def is_local_url(url: str) -> bool:
    """頼み先はこの PC の中だけ(外のサーバーへ鍵や質問を流さない)"""
    parts = urlsplit(url)
    return parts.scheme in ("http", "https") and parts.hostname in ("127.0.0.1", "localhost", "::1")


def base_url(raw: str) -> str:
    """頼み先の URL。末尾の / と /v1 は外して持つ(利用者は Unsloth を http://127.0.0.1:8888/v1 と書いた)。
    localhost は 127.0.0.1 にする(Windows は ::1 を先に試し、IPv4 だけで待つ API だと 1 回ごとに約 2 秒待った。2026-10-29 に測った)"""
    url = raw.strip().rstrip("/")
    url = url[:-3].rstrip("/") if url.endswith("/v1") else url
    return re.sub(r"^(https?://)localhost(?=[:/]|$)", r"\g<1>127.0.0.1", url)


class Backend:
    def __init__(self, name: str, url: str, key: str) -> None:
        self.name = name
        self.url = base_url(url)
        self.key = key.strip()

    def headers(self) -> dict[str, str]:
        return {"Authorization": "Bearer " + self.key} if self.key else {}

    def request(self, method: str, path: str, body: Any = None, timeout: float = 10) -> Any:
        data = None if body is None else json.dumps(body, ensure_ascii=False).encode("utf-8")
        request = urllib.request.Request(
            self.url + path, data=data, method=method,
            headers={**({"Content-Type": "application/json"} if data is not None else {}), **self.headers()},
        )
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read(MAX_UPSTREAM_BYTES + 1)
        if len(raw) > MAX_UPSTREAM_BYTES:
            raise BadRequest(502, "upstream too large")
        return json.loads(raw) if raw.strip() else {}


def configured_backends() -> dict[str, Backend]:
    """guide-llm.env に書いた頼み先。Bionic は古い名前(LLM_API_URL / LLM_API_KEY)でも読む"""
    found: dict[str, Backend] = {}
    unsloth = os.environ.get("UNSLOTH_API_URL", "").strip()
    if unsloth:
        found["unsloth"] = Backend("unsloth", unsloth, os.environ.get("UNSLOTH_API_KEY", ""))
    bionic = os.environ.get("BIONIC_API_URL", "").strip() or os.environ.get("LLM_API_URL", "").strip()
    if bionic:
        found["bionic"] = Backend("bionic", bionic, os.environ.get("BIONIC_API_KEY", "") or os.environ.get("LLM_API_KEY", ""))
    return found


BACKENDS: dict[str, Backend] = {}


def model_key(backend: str, model_id: str) -> str:
    return f"{backend}:{model_id}"


def split_key(key: str) -> tuple[str, str]:
    backend, _, model_id = key.partition(":")
    return backend, model_id


# ---------------------------------------------------------------- 頼み先の模型の一覧

def catalog_bionic(backend: Backend) -> list[dict[str, Any]]:
    """Bionic(LM Studio と同じ形)の模型。/api/v1/models があれば表示名・読み込み済みか・考えを止められるかも分かる"""
    try:
        listed = backend.request("GET", "/api/v1/models")
        entries = []
        for model in listed.get("models", []) if isinstance(listed, dict) else []:
            if not isinstance(model, dict) or model.get("type") != "llm" or not isinstance(model.get("key"), str):
                continue
            instances = [i.get("id") if isinstance(i, dict) else i for i in model.get("loaded_instances") or []]
            options = ((model.get("capabilities") or {}).get("reasoning") or {}).get("allowed_options") or []
            entries.append({
                "id": model["key"], "name": str(model.get("display_name") or model["key"]),
                "loaded": bool(instances), "instances": [str(i) for i in instances if i],
                "offOk": "yes" if "off" in options else "no",
            })
        return entries
    except urllib.error.HTTPError as error:
        if error.code != 404:
            raise
    listed = backend.request("GET", "/v1/models")
    return [{"id": item["id"], "name": item["id"], "loaded": True, "instances": [], "offOk": ""}
            for item in (listed.get("data") or []) if isinstance(item, dict) and isinstance(item.get("id"), str)]


def unsloth_status(backend: Backend) -> dict[str, Any]:
    try:
        status = backend.request("GET", "/v1/status")
        return status if isinstance(status, dict) else {}
    except urllib.error.HTTPError:
        return {}


def unsloth_active(status: dict[str, Any]) -> str:
    """Unsloth がいま読んでいる模型の識別子(読んでいなければ空)"""
    if status.get("loaded") is False:
        return ""
    for field in ("model_identifier", "active_model", "model"):
        if isinstance(status.get(field), str) and status[field]:
            return status[field]
    return ""


def catalog_unsloth(backend: Backend) -> list[dict[str, Any]]:
    """Unsloth の模型。/v1/models の一覧と、/v1/status のいま読んでいる模型。会話はいま読んでいる模型で答える"""
    listed = backend.request("GET", "/v1/models")
    status = unsloth_status(backend)
    active = unsloth_active(status)
    entries = []
    seen = set()
    for item in (listed.get("data") or []) if isinstance(listed, dict) else []:
        if not isinstance(item, dict) or not isinstance(item.get("id"), str) or item["id"] in seen:
            continue
        seen.add(item["id"])
        loaded = bool(active) and item["id"] == active
        entries.append({
            "id": item["id"], "name": str(item.get("name") or item.get("display_name") or item["id"]),
            "loaded": loaded, "instances": [],
            # いま読んでいる模型だけ、考えを止められるかが分かる(reasoning_always_on)
            "offOk": ("no" if status.get("reasoning_always_on") else "yes") if loaded else "",
        })
    if active and active not in seen:
        entries.append({"id": active, "name": str(status.get("display_name") or active), "loaded": True, "instances": [],
                        "offOk": "no" if status.get("reasoning_always_on") else "yes"})
    return entries


def refresh_catalog(force: bool = False) -> dict[str, dict[str, Any]]:
    """頼み先それぞれの模型を「unsloth:<id>」「bionic:<id>」の一覧にする。届かない頼み先は飛ばす(本文はログに書かない)"""
    global CATALOG, CATALOG_AT
    with CATALOG_LOCK:
        if not force and CATALOG and time.time() - CATALOG_AT < CATALOG_TTL:
            return CATALOG
        merged: dict[str, dict[str, Any]] = {}
        for name, backend in BACKENDS.items():
            try:
                entries = catalog_unsloth(backend) if name == "unsloth" else catalog_bionic(backend)
            except (urllib.error.URLError, OSError, ValueError, BadRequest) as error:
                LOG.warning("%s API unreachable for model list: %s", name, type(error).__name__)
                continue
            for entry in entries:
                merged[model_key(name, entry["id"])] = {"backend": name, **entry}
        CATALOG = merged
        CATALOG_AT = time.time()
        return CATALOG


def default_key(catalog: dict[str, dict[str, Any]]) -> str | None:
    """既定の模型。GUIDE_LLM_MODEL があればそれ、無ければ既定の頼み先でいま読んでいる模型、無ければ先頭"""
    if DEFAULT_MODEL:
        key = model_key(DEFAULT_BACKEND, DEFAULT_MODEL)
        return key if key in catalog else None
    mine = [k for k, v in catalog.items() if v["backend"] == DEFAULT_BACKEND]
    loaded = [k for k in mine if catalog[k]["loaded"]]
    return (loaded or mine or [None])[0]


def resolve_model(requested: Any) -> str:
    """頼みの model が一覧にあればそれ、無ければ既定(一覧に無い名前は通さない)"""
    catalog = refresh_catalog()
    if isinstance(requested, str) and requested in catalog:
        return requested
    key = default_key(catalog)
    if key is None:
        raise BadRequest(503, "no model")
    return key


# ---------------------------------------------------------------- 読み込み・外す(GPU に 1 つだけ)

def unload_everything(except_key: str | None = None) -> None:
    """読み込まれている模型を外す(読み込む模型そのものは except_key で残せる)"""
    for key, entry in list(refresh_catalog(force=True).items()):
        if not entry["loaded"] or key == except_key:
            continue
        backend = BACKENDS[entry["backend"]]
        try:
            if entry["backend"] == "bionic":
                for instance in entry["instances"] or [entry["id"]]:
                    backend.request("POST", "/api/v1/models/unload", {"instance_id": instance}, timeout=LOAD_TIMEOUT)
            else:
                backend.request("POST", "/v1/unload", {"model_path": entry["id"]}, timeout=LOAD_TIMEOUT)
            LOG.info("unloaded %s", key)
        except (urllib.error.URLError, OSError, ValueError, BadRequest) as error:
            LOG.warning("could not unload %s: %s", key, type(error).__name__)


def load_model(key: str, reload: bool = False) -> None:
    """模型を読み込む。ほかの模型は外す。reload なら読み込み済みでも外してから読む(起動のとき。利用者「アンロードからロード」)"""
    backend_name, model_id = split_key(key)
    backend = BACKENDS[backend_name]
    with LOAD_LOCK:
        LOAD_STATE[key] = "loading"
        try:
            entry = refresh_catalog(force=True).get(key, {})
            if entry.get("loaded") and not reload:
                unload_everything(except_key=key)
                LOAD_STATE.pop(key, None)
                return
            unload_everything()
            started = time.time()
            if backend_name == "bionic":
                body: dict[str, Any] = {"model": model_id}
                if CONTEXT_LENGTH.isdigit():
                    body["context_length"] = int(CONTEXT_LENGTH)
                backend.request("POST", "/api/v1/models/load", body, timeout=LOAD_TIMEOUT)
            else:
                body = {"model_path": model_id}
                if CONTEXT_LENGTH.isdigit():
                    body["max_seq_length"] = int(CONTEXT_LENGTH)
                backend.request("POST", "/v1/load", body, timeout=LOAD_TIMEOUT)
            LOG.info("loaded %s in %.0fs", key, time.time() - started)
            LOAD_STATE.pop(key, None)
        except (urllib.error.URLError, OSError, ValueError, BadRequest) as error:
            LOG.warning("could not load %s: %s", key, type(error).__name__)
            LOAD_STATE[key] = "failed"
        finally:
            refresh_catalog(force=True)


def start_loading(key: str) -> str:
    """後ろで読み込みを始める。いまの状態(loaded / loading / failed)を返す"""
    entry = refresh_catalog().get(key, {})
    if entry.get("loaded"):
        return "loaded"
    if LOAD_STATE.get(key) == "loading":
        return "loading"
    LOAD_STATE[key] = "loading"
    threading.Thread(target=load_model, args=(key,), daemon=True).start()
    return "loading"


# ---------------------------------------------------------------- 会話

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


def choose_effort(requested: Any, off_ok: str) -> str:
    """考える指定を決める。Website からの指定 → guide-llm.env → none。止められない模型(off_ok = no)に none が来たら low"""
    effort = EFFORT_OVERRIDE or "none"
    if isinstance(requested, str) and requested in REQUESTABLE_EFFORTS:
        effort = requested
    if effort == "none" and off_ok == "no":
        effort = "low"
    return effort


def parse_messages(payload: Any) -> tuple[list[dict[str, str]], int, float]:
    """受けた頼みを確かめる(形の誤りは 400)"""
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
    return messages, max_tokens, float(temperature)


def build_upstream_request(backend: str, model_id: str, messages: list[dict[str, str]], max_tokens: int,
                           temperature: float, effort: str) -> dict[str, Any]:
    """頼み先へ渡す形に作り直す(余計な項目は渡さない)。模型の名前と出す字数は門番が決める"""
    thinking = effort not in ("", "none", "off")
    body: dict[str, Any] = {
        "model": model_id,
        "messages": messages,
        "max_tokens": min(max_tokens, MAX_NEW_TOKENS_CAP) + (REASONING_HEADROOM if thinking else 0),
        "temperature": temperature,
        "stream": False,
        # Qwen などの考えを止める(llama.cpp の書き方。Bionic は聞かないので下の reasoning_effort も付ける)
        "chat_template_kwargs": {"enable_thinking": thinking},
    }
    if effort and effort != "off":
        body["reasoning_effort"] = effort
    if backend == "unsloth":
        # Unsloth の書き方。**道具は使わせない**(Python・ターミナル・MCP を動かせる道具を持つため)
        body["enable_thinking"] = thinking
        body["enable_tools"] = False
        body["mcp_enabled"] = False
    return body


def post_chat(backend: Backend, body: dict[str, Any]) -> dict[str, Any]:
    try:
        return backend.request("POST", "/v1/chat/completions", body, timeout=UPSTREAM_TIMEOUT)
    except urllib.error.HTTPError as error:
        # 知らない項目を断る API がある。1 度だけ考えの指定を外して頼み直す(道具を止める項目は外さない)
        if error.code not in (400, 422) or not any(key in body for key in OPTIONAL_KEYS):
            raise
        return backend.request("POST", "/v1/chat/completions", {k: v for k, v in body.items() if k not in OPTIONAL_KEYS},
                               timeout=UPSTREAM_TIMEOUT)


def answer_text(decoded: Any) -> tuple[str, dict[str, Any], Any]:
    choice = decoded["choices"][0]
    text = choice["message"]["content"]
    if not isinstance(text, str):
        raise BadRequest(502, "upstream content")
    usage = decoded.get("usage") if isinstance(decoded.get("usage"), dict) else {}
    return clean_output(text), usage, choice.get("finish_reason")


def call_upstream(key: str, payload: dict[str, Any]) -> dict[str, Any]:
    backend_name, model_id = split_key(key)
    backend = BACKENDS[backend_name]
    messages, max_tokens, temperature = parse_messages(payload)
    effort = choose_effort(payload.get("reasoning_effort"), str(refresh_catalog().get(key, {}).get("offOk", "")))
    with UPSTREAM_LOCK:   # 同時に 1 件ずつ
        text, usage, finish = answer_text(post_chat(backend, build_upstream_request(backend_name, model_id, messages, max_tokens, temperature, effort)))
        if not text and effort in ("", "none"):
            # 考えを止められない模型だと分からなかった(Unsloth の読んでいない模型など)。考える分を足して 1 度だけ頼み直す
            LOG.warning("empty answer from %s (finish %s); retrying with low effort", key, finish)
            text, usage, finish = answer_text(post_chat(backend, build_upstream_request(backend_name, model_id, messages, max_tokens, temperature, "low")))
    if not text:
        # 空の答えを「答えた」にしない(PHP は言葉で探した結果を出す)
        details = usage.get("completion_tokens_details") if isinstance(usage.get("completion_tokens_details"), dict) else {}
        LOG.warning("empty answer (finish %s, reasoning %s tokens)", finish, details.get("reasoning_tokens", "?"))
        raise BadRequest(502, "upstream empty")
    return {"text": text, "usage": usage}


def default_alive() -> bool:
    backend = BACKENDS.get(DEFAULT_BACKEND)
    if backend is None:
        return False
    try:
        backend.request("GET", "/v1/models", timeout=3)
        return True
    except urllib.error.HTTPError as error:
        return error.code not in (401, 403)
    except (urllib.error.URLError, OSError, ValueError, BadRequest):
        return False


class Handler(BaseHTTPRequestHandler):
    server_version = "guide-llm"
    sys_version = ""

    def log_message(self, format: str, *args: Any) -> None:  # noqa: A002
        # 既定の 1 行ログ(URL など)も出さない。時間と字数だけを下で書く
        return

    def send_json(self, status: int, body: dict[str, Any], model: str | None = None) -> None:
        data = json.dumps(body, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        if model:
            self.send_header("X-Guide-Model", model)
        self.end_headers()
        self.wfile.write(data)

    def read_json(self) -> Any:
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            length = -1
        if length < 0 or length > MAX_BODY_BYTES:
            raise BadRequest(413, "too large")
        try:
            return json.loads(self.rfile.read(length) or b"null")
        except ValueError as error:
            raise BadRequest(400, "json") from error

    def do_GET(self) -> None:  # noqa: N802
        if self.path == "/health":
            # 中身は言わない(模型の名前も出さない)。既定の頼み先が答えるかだけ
            self.send_json(200, {"ok": default_alive()})
            return
        if self.path == "/v1/models":
            # 管理画面「案内チャット」の模型の欄(2026-10-29)。鍵の要る口
            if not authorized(self.headers.get("Authorization", "")):
                self.send_json(401, {"error": "unauthorized"})
                return
            catalog = refresh_catalog(force=True)
            self.send_json(200, {
                "default": default_key(catalog),
                "models": [{"id": key, "backend": entry["backend"], "model": entry["id"], "name": entry["name"],
                            "loaded": entry["loaded"], "loading": LOAD_STATE.get(key) == "loading",
                            "failed": LOAD_STATE.get(key) == "failed"}
                           for key, entry in sorted(catalog.items())],
            })
            return
        self.send_json(404, {"error": "not found"})

    def do_POST(self) -> None:  # noqa: N802
        if self.path not in ("/v1/chat/completions", "/v1/select"):
            self.send_json(404, {"error": "not found"})
            return
        if not authorized(self.headers.get("Authorization", "")):
            self.send_json(401, {"error": "unauthorized"})
            return
        try:
            payload = self.read_json()
        except BadRequest as error:
            self.send_json(error.status, {"error": str(error)})
            return
        if self.path == "/v1/select":
            # 管理画面で模型を選んだら、先に読み込みを始める(読み込みは数十秒〜数分かかるので待たない)
            key = payload.get("model") if isinstance(payload, dict) else None
            if not isinstance(key, str) or key not in refresh_catalog(force=True):
                self.send_json(400, {"error": "unknown model"})
                return
            self.send_json(202, {"status": start_loading(key), "model": key}, key)
            return
        started = time.time()
        try:
            key = resolve_model(payload.get("model") if isinstance(payload, dict) else None)
            switching = next((k for k, v in LOAD_STATE.items() if v == "loading"), None)
            if switching is not None:
                # 模型を入れ替えている間は答えない(外した模型へ頼んでしまう。一覧はまだ古い)
                self.send_json(503, {"error": "model loading", "model": switching}, switching)
                return
            if not refresh_catalog().get(key, {}).get("loaded"):
                # 読まれていない模型。後ろで読み込みを始め、今回は 503(PHP は言葉で探した結果を出す)
                state = start_loading(key)
                self.send_json(503, {"error": "model " + state, "model": key}, key)
                return
            result = call_upstream(key, payload)
        except BadRequest as error:
            self.send_json(error.status, {"error": str(error)})
            return
        except (ValueError, KeyError, IndexError, TypeError):
            self.send_json(502, {"error": "upstream reply"})
            return
        except (urllib.error.URLError, OSError) as error:
            LOG.warning("LLM API unreachable: %s", type(error).__name__)
            self.send_json(502, {"error": "upstream"})
            return
        usage = result["usage"]
        # **本文は書かない**。時間と字数と模型だけ
        LOG.info("answered in %.1fs by %s (prompt %s tokens, output %s tokens)", time.time() - started, key,
                 usage.get("prompt_tokens", "?"), usage.get("completion_tokens", "?"))
        self.send_json(200, {
            "object": "chat.completion",
            "model": key,
            "choices": [{"index": 0, "message": {"role": "assistant", "content": result["text"]}, "finish_reason": "stop"}],
            "usage": usage,
        }, key)


def main() -> None:
    global BACKENDS, DEFAULT_BACKEND
    if len(API_KEY) < 32:
        raise SystemExit("KM_GUIDE_LLM_KEY は 32 字以上にしてください(guide-llm.env)")
    BACKENDS = configured_backends()
    if not BACKENDS:
        raise SystemExit("頼み先がありません。guide-llm.env に UNSLOTH_API_URL か BIONIC_API_URL を入れてください")
    for backend in BACKENDS.values():
        if not is_local_url(backend.url):
            raise SystemExit(f"{backend.name.upper()}_API_URL はこの PC の中(http://127.0.0.1:番号 など)にしてください: {backend.url!r}")
    if not DEFAULT_BACKEND and len(BACKENDS) == 1:
        DEFAULT_BACKEND = next(iter(BACKENDS))
    if DEFAULT_BACKEND not in BACKENDS:
        raise SystemExit("GUIDE_LLM_BACKEND は " + " / ".join(BACKENDS) + " のどれか(guide-llm.env に URL を書いた頼み先): " + repr(DEFAULT_BACKEND))
    if EFFORT_OVERRIDE and EFFORT_OVERRIDE not in REASONING_EFFORT_CHOICES:
        raise SystemExit("GUIDE_LLM_REASONING_EFFORT は " + " / ".join(REASONING_EFFORT_CHOICES) + " のどれか(空なら既定): " + repr(EFFORT_OVERRIDE))
    catalog = refresh_catalog(force=True)
    mine = {k: v for k, v in catalog.items() if v["backend"] == DEFAULT_BACKEND}
    if not mine:
        raise SystemExit(f"{DEFAULT_BACKEND} の API が答えないか、模型がありません({BACKENDS[DEFAULT_BACKEND].url}/v1/models)。"
                         "鍵(*_API_KEY)と、模型を入れてあるかを確かめてください")
    key = default_key(catalog)
    if key is None:
        listing = "\n".join(f"  {v['id']}  {v['name']}" for v in mine.values())
        raise SystemExit(f"{DEFAULT_BACKEND} に GUIDE_LLM_MODEL の模型がありません: {DEFAULT_MODEL!r}\n使える識別子:\n{listing}")
    # 起動のときは既定の模型を読み直す(利用者「既にロードされていたらアンロードからロード」。ほかの模型も外す)
    LOG.info("reloading default model %s ...", key)
    load_model(key, reload=True)
    if LOAD_STATE.get(key) == "failed":
        LOG.warning("default model could not be (re)loaded; it will be loaded on the first request if possible")
    others = [name for name in BACKENDS if name != DEFAULT_BACKEND]
    LOG.info("default %s / backends %s / waiting on 127.0.0.1:%d", key, ", ".join(BACKENDS), PORT)
    if others:
        LOG.info("also listing models of %s (selectable from the admin page)", ", ".join(others))
    # 外へは ngrok だけが出す。ここは 127.0.0.1 だけで待つ
    ExclusiveServer(("127.0.0.1", PORT), Handler).serve_forever()


class ExclusiveServer(ThreadingHTTPServer):
    """**同じ番号を 2 つ目が取れないようにする**(2026-10-29)。Windows では SO_REUSEADDR で 2 つ目も待ち受けられ、
    古い門番が答え続けた(試しで、止め損ねた門番が新しい頼み先を見なかった)。番号が使われていれば起動で止まる"""
    allow_reuse_address = False


if __name__ == "__main__":
    main()

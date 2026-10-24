"""模型を選ぶための測り(自宅 PC。2026-10-24)。

動いている server.py(127.0.0.1)に、案内でよく来そうな質問 20 件を、Website と同じ「分ける」頼み方で送り、
1 件ごとの時間と返事を出す。4 つの模型(Gemma4 E4B / 12B QAT / Qwen3.5-9B / GPT-OSS-20B)を順に入れ替えて走らせ、
**速さ(中央値)と、返事が JSON として読めた数**で比べる。日本語の出来は、出した返事を目で見て決める。

    python bench.py            # 結果は bench-<時刻>.json にも残す(質問は下の決まった文だけ。利用者の質問は使わない)

鍵とポートは guide-llm.env と同じ環境変数(start-guide-llm.ps1 -Bench が渡す)。
"""

from __future__ import annotations

import json
import os
import statistics
import time
import urllib.request

PORT = int(os.environ.get("GUIDE_LLM_PORT", "8000"))
KEY = os.environ.get("KM_GUIDE_LLM_KEY", "")

QUESTIONS = [
    "食べ物の模擬店を教えて", "たこ焼きはどこ?", "甘いものが食べたい", "飲み物を売っている店", "安く遊べるゲームはある?",
    "お化け屋敷はどこ", "ステージの時間を知りたい", "救護室はどこですか", "トイレはどこ", "受付の場所",
    "第二実習室から体育館への行き方", "図書館までの道", "正門から2Eに行きたい", "3階の展示を教えて", "子ども向けの企画",
    "写真の展示", "人気の企画は?", "ロボットの展示", "休める場所はある?", "こんにちは",
]

SYSTEM = (
    "あなたは学校祭の会場案内の受付です。利用者の質問を分類し、次の形の JSON だけを返してください。説明や前置きは書かないでください。\n"
    '{"intent":"shop|route|place|other","keywords":["探す言葉"],"category":"種類か null","from":"出発地の言葉か null","to":"目的地の言葉か null"}'
)


def ask(question: str) -> tuple[float, str]:
    body = json.dumps({
        "model": "bench",
        "messages": [{"role": "system", "content": SYSTEM}, {"role": "user", "content": question}],
        "max_tokens": 160,
        "temperature": 0.2,
    }).encode()
    request = urllib.request.Request(
        f"http://127.0.0.1:{PORT}/v1/chat/completions",
        data=body,
        headers={"Content-Type": "application/json", "Authorization": f"Bearer {KEY}"},
    )
    started = time.time()
    with urllib.request.urlopen(request, timeout=120) as response:
        data = json.loads(response.read())
    return time.time() - started, data["choices"][0]["message"]["content"]


def parses(text: str) -> bool:
    start, end = text.find("{"), text.rfind("}")
    if start < 0 or end <= start:
        return False
    try:
        return isinstance(json.loads(text[start:end + 1]), dict)
    except ValueError:
        return False


def main() -> None:
    results = []
    for question in QUESTIONS:
        seconds, text = ask(question)
        results.append({"question": question, "seconds": round(seconds, 2), "json": parses(text), "reply": text})
        print(f"{seconds:6.2f}s  {'OK ' if parses(text) else 'NG '} {question} → {text[:80]!r}")
    times = [r["seconds"] for r in results]
    summary = {
        "model": os.environ.get("GUIDE_LLM_MODEL", ""),
        "median_seconds": round(statistics.median(times), 2),
        "max_seconds": round(max(times), 2),
        "json_ok": sum(1 for r in results if r["json"]),
        "count": len(results),
    }
    print(json.dumps(summary, ensure_ascii=False))
    with open(f"bench-{time.strftime('%Y%m%d-%H%M%S')}.json", "w", encoding="utf-8") as handle:
        json.dump({"summary": summary, "results": results}, handle, ensure_ascii=False, indent=2)


if __name__ == "__main__":
    main()

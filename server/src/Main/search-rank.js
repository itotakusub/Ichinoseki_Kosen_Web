/*
 * 場所の検索の一致率(2026-10-23、利用者の指示「場所を検索したときに一致率順に項目を上げて。Google マップのような動き」)。
 *
 * **純粋関数だけ**(DOM も地図も触らない)。Main/app.js が候補を組み立ててここで並べ、
 * src/scripts/js/search-rank.test.js が Node で試す。
 *
 * ## 文字のそろえ方(normalize)
 * 全角/半角・大文字/小文字(NFKC + 小文字)・カタカナ/ひらがな・空白と区切りの記号を同じとみなす。
 * 「ﾄｲﾚ」「トイレ」「といれ」は同じ、「2-E」「２Ｅ」「2e」も同じ。
 *
 * ## 点数(強い順)
 *   名前の完全一致 1000 > 前方一致 800 > 語の頭の一致 600 > 途中の一致 400
 *   別名(イベント中の名前・ポスターの見出し・部屋番号・分類・企画の番号)は、それぞれ 150 低い
 * 空白で区切った言葉は**全部**どこかに当たること(AND)。点はいちばん低い言葉の点。
 * 同じ点なら いまの階 → 名前の短い方 → 50 音順。
 *
 * ## 番号で探す(2026-10-30、振り返りのフェーズ 4 の 3)
 * 言葉が 1 つで、企画の番号(item.numbers)とそっくり同じなら 1100(名前の完全一致より上)。
 * 「8」で企画 8 が、名前に 8 を含む「講義室8」より先に出る。番号の頭が合うだけ(8 → 80)は今までどおり別名の点。
 *
 * ## もしかして(2026-10-30、振り返りのフェーズ 4 の 2。AI を使わない)
 * 何も当たらないとき suggest() が一番近いものを返す。ひらがな → ローマ字にそろえた形(romaji)どうしで、
 * 2 字ずつの重なり(Dice)と編集距離の近さの高い方。漢字は読めないので、読みの欄(item.extras に入る)で拾う。
 */
(function (root) {
    'use strict';

    const SEPARATORS = /[\s　・·･()（）「」『』【】\[\]{}<>〈〉《》\/／\\\-‐‑‒–—―−_＿,，、。.．:：;；!！?？'"’”~〜]+/g;

    /** 比べるための形。空なら '' */
    function normalize(text) {
        let s = String(text == null ? '' : text);
        if (typeof s.normalize === 'function') s = s.normalize('NFKC');
        s = s.toLowerCase();
        // カタカナ → ひらがな(ァ〜ヶ。ー はそのまま)
        s = s.replace(/[ァ-ヶ]/g, function (ch) { return String.fromCharCode(ch.charCodeAt(0) - 0x60); });
        return s.replace(SEPARATORS, '');
    }

    /** 語に分けて、それぞれそろえる(語の頭の一致に使う) */
    function words(text) {
        let s = String(text == null ? '' : text);
        if (typeof s.normalize === 'function') s = s.normalize('NFKC');
        return s.split(SEPARATORS).map(normalize).filter(Boolean);
    }

    /** 1 つの文字列と 1 つの言葉の点(当たらなければ 0) */
    function fieldScore(text, query) {
        const n = normalize(text);
        if (n === '' || query === '') return 0;
        if (n === query) return 1000;
        if (n.indexOf(query) === 0) return 800;
        if (words(text).some(function (w) { return w.indexOf(query) === 0; })) return 600;
        if (n.indexOf(query) >= 0) return 400;
        return 0;
    }

    /**
     * 1 件の点。item = { name, extras?: string[], numbers?: string[] }。当たらなければ 0
     * @param {string[]} queryWords normalize 済みの言葉
     */
    function itemScore(item, queryWords) {
        if (queryWords.length === 0) return 0;
        if (queryWords.length === 1 && (item.numbers || []).some(function (n) { return normalize(n) === queryWords[0]; })) return 1100;
        let lowest = Infinity;
        for (let i = 0; i < queryWords.length; i++) {
            const q = queryWords[i];
            let best = fieldScore(item.name, q);
            (item.extras || []).forEach(function (extra) {
                const s = fieldScore(extra, q);
                if (s > 0) best = Math.max(best, s - 150);
            });
            if (best <= 0) return 0;
            lowest = Math.min(lowest, best);
        }
        return lowest;
    }

    /** 打った言葉を、空白で区切ってそろえる */
    function queryWordsOf(query) {
        let s = String(query == null ? '' : query);
        if (typeof s.normalize === 'function') s = s.normalize('NFKC');
        return s.split(/[\s　]+/).map(normalize).filter(Boolean);
    }

    /**
     * 一致率の順に並べる。当たらないものは外す。
     * @param {Array<{name: string, extras?: string[], floor?: string}>} items
     * @param {string} query
     * @param {{floor?: string, limit?: number}} [options]
     * @returns {Array} 並べた item(元の要素。score を足した写しではない)
     */
    function rank(items, query, options) {
        const opts = options || {};
        const queryWords = queryWordsOf(query);
        if (queryWords.length === 0) return [];
        const scored = [];
        items.forEach(function (item, index) {
            const score = itemScore(item, queryWords);
            if (score > 0) scored.push({ item: item, score: score, index: index });
        });
        const floor = opts.floor === undefined || opts.floor === null ? null : String(opts.floor);
        scored.sort(function (a, b) {
            if (b.score !== a.score) return b.score - a.score;
            const af = floor !== null && String(a.item.floor) === floor ? 0 : 1;
            const bf = floor !== null && String(b.item.floor) === floor ? 0 : 1;
            if (af !== bf) return af - bf;
            const al = normalize(a.item.name).length;
            const bl = normalize(b.item.name).length;
            if (al !== bl) return al - bl;
            const byName = String(a.item.name).localeCompare(String(b.item.name), 'ja');
            return byName !== 0 ? byName : a.index - b.index;
        });
        const limit = opts.limit === undefined ? scored.length : opts.limit;
        return scored.slice(0, limit).map(function (entry) { return entry.item; });
    }

    /* ---------------- もしかして ---------------- */

    // ひらがな → ローマ字(ヘボン式に近い形。読みの揺れ「しゃ/sya」は拾わないが、近さで寄る)
    const KANA2 = { 'きゃ': 'kya', 'きゅ': 'kyu', 'きょ': 'kyo', 'しゃ': 'sha', 'しゅ': 'shu', 'しょ': 'sho', 'ちゃ': 'cha', 'ちゅ': 'chu', 'ちょ': 'cho',
        'にゃ': 'nya', 'にゅ': 'nyu', 'にょ': 'nyo', 'ひゃ': 'hya', 'ひゅ': 'hyu', 'ひょ': 'hyo', 'みゃ': 'mya', 'みゅ': 'myu', 'みょ': 'myo',
        'りゃ': 'rya', 'りゅ': 'ryu', 'りょ': 'ryo', 'ぎゃ': 'gya', 'ぎゅ': 'gyu', 'ぎょ': 'gyo', 'じゃ': 'ja', 'じゅ': 'ju', 'じょ': 'jo',
        'びゃ': 'bya', 'びゅ': 'byu', 'びょ': 'byo', 'ぴゃ': 'pya', 'ぴゅ': 'pyu', 'ぴょ': 'pyo', 'ふぁ': 'fa', 'ふぃ': 'fi', 'ふぇ': 'fe', 'ふぉ': 'fo',
        'てぃ': 'ti', 'でぃ': 'di', 'うぃ': 'wi', 'うぇ': 'we', 'ゔぁ': 'va', 'しぇ': 'she', 'じぇ': 'je', 'ちぇ': 'che' };
    const KANA1 = { 'あ': 'a', 'い': 'i', 'う': 'u', 'え': 'e', 'お': 'o', 'か': 'ka', 'き': 'ki', 'く': 'ku', 'け': 'ke', 'こ': 'ko',
        'さ': 'sa', 'し': 'shi', 'す': 'su', 'せ': 'se', 'そ': 'so', 'た': 'ta', 'ち': 'chi', 'つ': 'tsu', 'て': 'te', 'と': 'to',
        'な': 'na', 'に': 'ni', 'ぬ': 'nu', 'ね': 'ne', 'の': 'no', 'は': 'ha', 'ひ': 'hi', 'ふ': 'fu', 'へ': 'he', 'ほ': 'ho',
        'ま': 'ma', 'み': 'mi', 'む': 'mu', 'め': 'me', 'も': 'mo', 'や': 'ya', 'ゆ': 'yu', 'よ': 'yo',
        'ら': 'ra', 'り': 'ri', 'る': 'ru', 'れ': 're', 'ろ': 'ro', 'わ': 'wa', 'を': 'o', 'ん': 'n',
        'が': 'ga', 'ぎ': 'gi', 'ぐ': 'gu', 'げ': 'ge', 'ご': 'go', 'ざ': 'za', 'じ': 'ji', 'ず': 'zu', 'ぜ': 'ze', 'ぞ': 'zo',
        'だ': 'da', 'ぢ': 'ji', 'づ': 'zu', 'で': 'de', 'ど': 'do', 'ば': 'ba', 'び': 'bi', 'ぶ': 'bu', 'べ': 'be', 'ぼ': 'bo',
        'ぱ': 'pa', 'ぴ': 'pi', 'ぷ': 'pu', 'ぺ': 'pe', 'ぽ': 'po', 'ゔ': 'vu',
        'ぁ': 'a', 'ぃ': 'i', 'ぅ': 'u', 'ぇ': 'e', 'ぉ': 'o', 'ゃ': 'ya', 'ゅ': 'yu', 'ょ': 'yo', 'ゎ': 'wa' };

    /** そろえた形をローマ字に(漢字・英数字はそのまま)。「でんさん」→「densan」、「れっつ」→「rettsu」 */
    function romaji(text) {
        const s = normalize(text);
        let out = '';
        for (let i = 0; i < s.length; i++) {
            const two = s.substr(i, 2);
            if (KANA2[two]) { out += KANA2[two]; i++; continue; }
            const ch = s[i];
            if (ch === 'っ') {
                // 次の音の頭の字を重ねる(っぷ → ppu)
                const next = KANA2[s.substr(i + 1, 2)] || KANA1[s[i + 1]] || '';
                out += next ? next[0] : '';
                continue;
            }
            if (ch === 'ー') { out += out.slice(-1).match(/[aeiou]/) ? out.slice(-1) : ''; continue; }
            out += KANA1[ch] !== undefined ? KANA1[ch] : ch;
        }
        return out;
    }

    function bigrams(s) {
        const list = [];
        for (let i = 0; i < s.length - 1; i++) list.push(s.substr(i, 2));
        return list.length > 0 ? list : (s ? [s] : []);
    }

    /** 2 字ずつの重なり(Dice。0〜1) */
    function dice(a, b) {
        const x = bigrams(a);
        const y = bigrams(b);
        if (x.length === 0 || y.length === 0) return 0;
        const pool = y.slice();
        let hit = 0;
        x.forEach(function (g) { const at = pool.indexOf(g); if (at >= 0) { hit++; pool.splice(at, 1); } });
        return (2 * hit) / (x.length + y.length);
    }

    /** 編集距離(長さ 60 字までで打ち切る。長い説明文で重くしない) */
    function editDistance(a, b) {
        a = a.slice(0, 60);
        b = b.slice(0, 60);
        let prev = [];
        for (let j = 0; j <= b.length; j++) prev[j] = j;
        for (let i = 1; i <= a.length; i++) {
            const cur = [i];
            for (let j = 1; j <= b.length; j++) {
                cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
            }
            prev = cur;
        }
        return prev[b.length];
    }

    /** 2 つの言葉の近さ(0〜1)。ローマ字どうしと、そろえた形どうしの高い方 */
    function similarity(query, text) {
        let best = 0;
        [[romaji(query), romaji(text)], [normalize(query), normalize(text)]].forEach(function (pair) {
            const q = pair[0];
            const t = pair[1];
            if (!q || !t) return;
            const longest = Math.max(q.length, t.length);
            const byEdit = 1 - editDistance(q, t) / longest;
            best = Math.max(best, dice(q, t), byEdit);
            // 長い名前の一部と近いか(「体育舘」→「第一体育館」)。同じ長さの窓をずらして見る。名前全体で近い方を先にするため少し下げる
            if (q.length >= 3 && t.length > q.length + 1 && t.length <= 40) {
                for (let i = 0; i + q.length <= t.length; i++) {
                    best = Math.max(best, 1 - editDistance(q, t.substr(i, q.length)) / q.length - 0.05);
                }
            }
        });
        return best;
    }

    /** 「もしかして」と出す近さの下限 */
    const SUGGEST_MIN = 0.6;

    /**
     * 何も当たらないときの「もしかして」。一番近い item(名前・別名のどれか)を返す。近いものが無ければ null。
     * 2 字以下の言葉では出さない(「え」で何かに寄せない)
     * @param {Array<{name: string, extras?: string[]}>} items
     * @param {string} query
     */
    function suggest(items, query) {
        const q = normalize(query);
        if (q.length < 2 || romaji(query).length < 3) return null;
        let best = null;
        let bestScore = SUGGEST_MIN;
        items.forEach(function (item) {
            [item.name].concat(item.extras || []).forEach(function (text, index) {
                if (!text || String(text).length > 60) return;
                // 別名で寄ったときは少しだけ下げる(名前で寄った方を先に)
                const score = similarity(query, text) - (index === 0 ? 0 : 0.02);
                if (score > bestScore) { bestScore = score; best = item; }
            });
        });
        return best;
    }

    const api = { normalize: normalize, words: words, fieldScore: fieldScore, itemScore: itemScore, rank: rank, romaji: romaji, similarity: similarity, suggest: suggest };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.KmSearchRank = api;
}(typeof window !== 'undefined' ? window : globalThis));

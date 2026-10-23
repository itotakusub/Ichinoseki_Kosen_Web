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
     * 1 件の点。item = { name, extras?: string[] }。当たらなければ 0
     * @param {string[]} queryWords normalize 済みの言葉
     */
    function itemScore(item, queryWords) {
        if (queryWords.length === 0) return 0;
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

    const api = { normalize: normalize, words: words, fieldScore: fieldScore, itemScore: itemScore, rank: rank };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.KmSearchRank = api;
}(typeof window !== 'undefined' ? window : globalThis));

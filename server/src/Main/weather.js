/*
 * 一関の天気(2026-11-05、利用者の指示「一関の天気を取得して、天候に関係する項目に自動でチェックを付ける」)。
 *
 * - 天気は api/weather.php(このサーバーが気象庁から取ったもの。lib/weather.php)。公開の地図の CSP は connect-src 'self' のまま
 * - 経路の「雨の日」は**自動**(雨・雪なら付け、止めば外す)。**自分で切り替えたら、その日だけ手動**(次の日は自動に戻る)。
 *   決め方は rainState(DOM を使わない純粋関数。scripts/js/weather.test.js)。付け外しは Main/app.js
 * - 予定の日ごとの見出しの天気は Main/event-schedule.js が dayText で出す
 * - 文は画面の言語(<html lang>。Main/i18n.js が決める)で組む。出典は「気象庁」
 */
(function (root) {
    'use strict';

    /** 端末の今日(YYYY-MM-DD)。手動の「その日」を数える */
    function localDay(date) {
        const d = date || new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    /**
     * 「雨の日」を付けるか。
     * @param {{rain?: boolean, rainManualDay?: string|null}} saved 端末に覚えた設定
     * @param {object|null} weather api/weather.php の答え(取れていなければ null)
     * @param {string} today localDay()
     * @returns {{rain: boolean, mode: 'manual'|'auto'|'unknown'}}
     */
    function rainState(saved, weather, today) {
        if (saved && saved.rainManualDay === today) {
            return { rain: saved.rain === true, mode: 'manual' };
        }
        if (weather && typeof weather.rain === 'boolean' && weather.reason !== 'unknown') {
            return { rain: weather.rain, mode: 'auto' };
        }
        // 天気が分からないときは付けない(雨の日は「天気で決めるもの」なので、古い手動の値を持ち越さない)
        return { rain: false, mode: 'unknown' };
    }

    function num(value) {
        return typeof value === 'number' && isFinite(value) ? value : null;
    }

    function isEnglish(lang) {
        return lang === 'en';
    }

    /** 「雨の日」の横に出す文(自動のとき) */
    function autoText(weather, lang) {
        const en = isEnglish(lang);
        const source = en ? ' · JMA' : '・気象庁';
        if (!weather || weather.reason === 'unknown') {
            return en ? 'Auto: weather unavailable' : '自動: 天気が分かりません';
        }
        const pop = num(weather.pop);
        if (weather.reason === 'observed') {
            if (weather.snow) return (en ? 'Auto: snowing in Ichinoseki' : '自動: 一関で雪') + source;
            const observed = weather.observed || {};
            const hour = num(observed.precip1h);
            const tenMin = num(observed.precip10m);
            let amount = '';
            if (hour !== null && hour > 0) amount = en ? ' (' + hour + ' mm/h)' : '(1 時間 ' + hour + 'mm)';
            else if (tenMin !== null && tenMin > 0) amount = en ? ' (' + tenMin + ' mm/10 min)' : '(10 分 ' + tenMin + 'mm)';
            return (en ? 'Auto: raining in Ichinoseki' : '自動: 一関で雨') + amount + source;
        }
        if (weather.reason === 'forecast') {
            return (en ? 'Auto: rain expected' : '自動: 雨の予報') + (pop !== null ? (en ? ' (' + pop + '%)' : '(降水 ' + pop + '%)') : '') + source;
        }
        return (en ? 'Auto: no rain expected' : '自動: 雨の予報なし') + (pop !== null ? (en ? ' (' + pop + '%)' : '(降水 ' + pop + '%)') : '') + source;
    }

    const ICONS = { sunny: '☀', cloudy: '☁', rain: '☂', snow: '❄' };

    /** 気象庁の英語の名前は大文字なので、先頭だけ大文字にする */
    function sentenceCase(text) {
        const lower = String(text || '').toLowerCase();
        return lower.charAt(0).toUpperCase() + lower.slice(1);
    }

    /** 予定の日ごとの見出しに付ける文。例「☂ 雨後曇・降水 60%・18℃/10℃」 */
    function dayText(day, lang) {
        if (!day) return '';
        const en = isEnglish(lang);
        const parts = [(ICONS[day.kind] || '☁') + ' ' + (en ? sentenceCase(day.telopEn || day.telop) : String(day.telop || ''))];
        const pop = num(day.pop);
        if (pop !== null) parts.push(en ? pop + '% rain' : '降水 ' + pop + '%');
        const max = num(day.max);
        const min = num(day.min);
        if (max !== null || min !== null) {
            parts.push((max !== null ? max : '-') + (en ? '°C' : '℃') + '/' + (min !== null ? min : '-') + (en ? '°C' : '℃'));
        }
        return parts.join(en ? ' · ' : '・');
    }

    /** 予定の日(YYYY-MM-DD…)の天気。無ければ null */
    function dayOf(weather, date) {
        const key = String(date || '').slice(0, 10);
        const days = (weather && Array.isArray(weather.days)) ? weather.days : [];
        return days.find(function (day) { return day && day.date === key; }) || null;
    }

    const api = { localDay: localDay, rainState: rainState, autoText: autoText, dayText: dayText, dayOf: dayOf };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.KmWeather = api;

    // ---- ここから下はブラウザだけ ----
    if (typeof root.fetch !== 'function' || typeof document === 'undefined') return;

    let pending = null;
    let loadedAt = 0;
    /** 天気を読む(同じページの中では使い回す。force で読み直す)。取れなければ null */
    api.load = function (force) {
        if (pending && !force) return pending;
        loadedAt = Date.now();
        pending = root.fetch('/api/weather.php', { credentials: 'omit', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (json) { return json && json.success ? json : null; })
            .catch(function () { return null; });
        return pending;
    };
    /** 最後に読んでからの時間(ミリ秒) */
    api.age = function () { return loadedAt === 0 ? Infinity : Date.now() - loadedAt; };
    api.lang = function () { return document.documentElement.lang === 'en' ? 'en' : 'ja'; };
}(typeof window !== 'undefined' ? window : globalThis));

/*
 * 書いたものと設定を、この端末のブラウザ(localStorage)にだけ置く(2026-11-08)。**サーバーへは送らない。**
 *
 * 1 つの鍵に 1 つの形で置く(計画書 11 章「保存と復元でデータ形式を統一する」):
 *   { version: 1, html, css, js, settings, savedAt }
 *
 * localStorage は無い・拒まれる・満杯のことがある(プライベートの窓・容量 5MB 前後)ので、
 * 読み書きは必ず try/catch。失敗は { ok: false, reason } で返し、画面が「保存できません」と出す。
 * 形の確かめ(parseSaved)と組み立て(serializeSaved)は純粋関数(node で試す)。
 */
import { normalizeSettings } from './settings.js';

export const STORAGE_KEY = 'km-editor-v1';
export const STORAGE_VERSION = 1;
// localStorage の多くは 5MB(UTF-16 で数えるので文字数で 2.5M 前後)。余裕を見て文字数で止める
export const STORAGE_MAX_CHARS = 2_000_000;

export function serializeSaved(doc, now = Date.now()) {
  return JSON.stringify({
    version: STORAGE_VERSION,
    html: String(doc.html ?? ''),
    css: String(doc.css ?? ''),
    js: String(doc.js ?? ''),
    settings: normalizeSettings(doc.settings),
    savedAt: now,
  });
}

/** 読んだ文字列を形に直す。壊れていれば null(呼ぶ側は初期のコードを使う) */
export function parseSaved(text) {
  if (typeof text !== 'string' || text === '') {
    return null;
  }
  let raw;
  try {
    raw = JSON.parse(text);
  } catch {
    return null;
  }
  if (!raw || typeof raw !== 'object' || raw.version !== STORAGE_VERSION) {
    return null;
  }
  if (typeof raw.html !== 'string' || typeof raw.css !== 'string' || typeof raw.js !== 'string') {
    return null;
  }
  return {
    html: raw.html,
    css: raw.css,
    js: raw.js,
    settings: normalizeSettings(raw.settings),
    savedAt: Number.isFinite(raw.savedAt) ? raw.savedAt : 0,
  };
}

function storage() {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

export function loadSaved() {
  const s = storage();
  if (!s) {
    return null;
  }
  try {
    return parseSaved(s.getItem(STORAGE_KEY));
  } catch {
    return null;
  }
}

/** @returns {{ok: true} | {ok: false, reason: 'unavailable'|'too-large'|'quota'}} */
export function saveDoc(doc) {
  const s = storage();
  if (!s) {
    return { ok: false, reason: 'unavailable' };
  }
  const text = serializeSaved(doc);
  if (text.length > STORAGE_MAX_CHARS) {
    return { ok: false, reason: 'too-large' };
  }
  try {
    s.setItem(STORAGE_KEY, text);
    return { ok: true };
  } catch {
    return { ok: false, reason: 'quota' };
  }
}

export function clearSaved() {
  const s = storage();
  if (!s) {
    return;
  }
  try {
    s.removeItem(STORAGE_KEY);
  } catch {
    // 消せなくても次の保存で上書きされる
  }
}

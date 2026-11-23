/*
 * 段 9 の書きかけ(localStorage `km-editor-v1` の html・css・js)を、段 10 のプロジェクトへ移す(2026-11-08)。
 *
 * - 作るのは「はじめのプロジェクト」: index.html(<link>・<script src> 付きの完全な文書)・style.css・script.js
 *   (templates.js の docToFiles。空の CSS・JS は作らない)
 * - **古い鍵は消さない**(移した印 `km-editor-v1-migrated` だけ付ける。戻せるように)
 * - 移すのは 1 回だけ。印があれば何もしない
 * planMigration は純粋関数(node で試す)。
 */
import { docToFiles } from '../templates.js';
import { newProjectRecord } from './model.js';
import { loadSaved } from '../storage.js';

export const MIGRATED_KEY = 'km-editor-v1-migrated';

export function firstProjectName(lang) {
  return lang === 'en' ? 'My first project' : 'はじめのプロジェクト';
}

/** @returns {{project: object, puts: object[]} | null} */
export function planMigration(saved, lang, options = {}) {
  if (!saved || typeof saved.html !== 'string') {
    return null;
  }
  const name = firstProjectName(lang);
  const texts = docToFiles(saved, { lang, title: name });
  const now = options.now ?? (Number.isFinite(saved.savedAt) && saved.savedAt > 0 ? saved.savedAt : Date.now());
  return newProjectRecord(name, texts, { ...options, now });
}

function local() {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

/**
 * 移す。移したらそのプロジェクトの id、移すものが無い・済んでいるなら null。
 * 書けなかったら印は付けない(次に開いたときにまた試す)。
 */
export async function migrateLegacy(store, lang) {
  const s = local();
  try {
    if (!s || s.getItem(MIGRATED_KEY)) {
      return null;
    }
  } catch {
    return null;
  }
  const saved = loadSaved();
  if (!saved) {
    return null;
  }
  const plan = planMigration(saved, lang);
  if (!plan) {
    return null;
  }
  await store.writeFiles(plan.project.id, plan.puts, [], plan.project);
  try {
    s.setItem(MIGRATED_KEY, String(Date.now()));
  } catch {
    // 印が書けなくても、次は IndexedDB にプロジェクトがあるので作り直さない(main.js が数を見る)
  }
  return plan.project.id;
}

/** 段 9 の設定(新しい設定がまだ無いときに引き継ぐ) */
export function legacySettings() {
  return loadSaved()?.settings ?? null;
}

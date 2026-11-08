/*
 * Monaco を、この画面に要る分だけ読む(2026-11-08)。monaco-editor@0.57.0 の editor.main.js から
 * **言語は HTML・CSS・JavaScript(TypeScript の worker)だけ**にし、画面の機能(contrib)はそのまま並べた。
 * 段 10(複数ファイル)で JSON(worker 付き)と、色分けだけの XML・Markdown を足した。
 * 版を上げたら editor.main.js と見比べて、この一覧を直す(check.php の editor の組が数を見る)。
 *
 * worker は同じオリジンのファイル(Vite の ?worker。blob: にしない = CSP の worker-src 'self' で動く)。
 * 'monaco-esm' は vite.config.js の別名(node_modules/monaco-editor/esm/vs)。
 */
import 'monaco-esm/editor/contrib/anchorSelect/browser/anchorSelect.js';
import 'monaco-esm/editor/contrib/bracketMatching/browser/bracketMatching.js';
import 'monaco-esm/editor/contrib/caretOperations/browser/transpose.js';
import 'monaco-esm/editor/contrib/clipboard/browser/clipboard.js';
import 'monaco-esm/editor/contrib/codeAction/browser/codeActionContributions.js';
import 'monaco-esm/editor/browser/widget/codeEditor/codeEditorWidget.js';
import 'monaco-esm/editor/contrib/codelens/browser/codelensController.js';
import 'monaco-esm/base/browser/ui/codicons/codicon/codicon.css';
import 'monaco-esm/editor/contrib/colorPicker/browser/colorPickerContribution.js';
import 'monaco-esm/editor/contrib/comment/browser/comment.js';
import 'monaco-esm/editor/contrib/contextmenu/browser/contextmenu.js';
import 'monaco-esm/editor/contrib/cursorUndo/browser/cursorUndo.js';
import 'monaco-esm/editor/browser/widget/diffEditor/diffEditor.contribution.js';
import 'monaco-esm/editor/contrib/diffEditorBreadcrumbs/browser/contribution.js';
import 'monaco-esm/editor/contrib/dnd/browser/dnd.js';
import 'monaco-esm/editor/contrib/documentSymbols/browser/documentSymbols.js';
import 'monaco-esm/editor/contrib/dropOrPasteInto/browser/dropIntoEditorContribution.js';
import 'monaco-esm/features/find/register.js';
import 'monaco-esm/editor/contrib/floatingMenu/browser/floatingMenu.contribution.js';
import 'monaco-esm/editor/contrib/folding/browser/folding.js';
import 'monaco-esm/editor/contrib/fontZoom/browser/fontZoom.js';
import 'monaco-esm/editor/contrib/format/browser/formatActions.js';
import 'monaco-esm/editor/contrib/gotoError/browser/gotoError.js';
import 'monaco-esm/editor/standalone/browser/quickAccess/standaloneGotoLineQuickAccess.js';
import 'monaco-esm/editor/contrib/gotoSymbol/browser/link/goToDefinitionAtPosition.js';
import 'monaco-esm/editor/contrib/gpu/browser/gpuActions.js';
import 'monaco-esm/editor/contrib/hover/browser/hoverContribution.js';
import 'monaco-esm/editor/contrib/indentation/browser/indentation.js';
import 'monaco-esm/editor/contrib/inlayHints/browser/inlayHintsContribution.js';
import 'monaco-esm/editor/contrib/inlineCompletions/browser/inlineCompletions.contribution.js';
import 'monaco-esm/editor/contrib/inlineProgress/browser/inlineProgress.js';
import 'monaco-esm/editor/contrib/inPlaceReplace/browser/inPlaceReplace.js';
import 'monaco-esm/editor/contrib/insertFinalNewLine/browser/insertFinalNewLine.js';
import 'monaco-esm/editor/standalone/browser/inspectTokens/inspectTokens.js';
import 'monaco-esm/editor/standalone/browser/iPadShowKeyboard/iPadShowKeyboard.js';
import 'monaco-esm/editor/contrib/lineSelection/browser/lineSelection.js';
import 'monaco-esm/editor/contrib/linesOperations/browser/linesOperations.js';
import 'monaco-esm/editor/contrib/linkedEditing/browser/linkedEditing.js';
import 'monaco-esm/editor/contrib/links/browser/links.js';
import 'monaco-esm/editor/contrib/longLinesHelper/browser/longLinesHelper.js';
import 'monaco-esm/editor/contrib/middleScroll/browser/middleScroll.contribution.js';
import 'monaco-esm/editor/contrib/multicursor/browser/multicursor.js';
import 'monaco-esm/editor/contrib/parameterHints/browser/parameterHints.js';
import 'monaco-esm/editor/contrib/placeholderText/browser/placeholderText.contribution.js';
import 'monaco-esm/editor/standalone/browser/quickAccess/standaloneCommandsQuickAccess.js';
import 'monaco-esm/editor/standalone/browser/quickAccess/standaloneHelpQuickAccess.js';
import 'monaco-esm/editor/standalone/browser/quickAccess/standaloneGotoSymbolQuickAccess.js';
import 'monaco-esm/editor/contrib/readOnlyMessage/browser/contribution.js';
import 'monaco-esm/editor/standalone/browser/referenceSearch/standaloneReferenceSearch.js';
import 'monaco-esm/editor/contrib/rename/browser/rename.js';
import 'monaco-esm/editor/contrib/sectionHeaders/browser/sectionHeaders.js';
import 'monaco-esm/editor/contrib/semanticTokens/browser/viewportSemanticTokens.js';
import 'monaco-esm/editor/contrib/smartSelect/browser/smartSelect.js';
import 'monaco-esm/editor/contrib/snippet/browser/snippetController2.js';
import 'monaco-esm/editor/contrib/stickyScroll/browser/stickyScrollContribution.js';
import 'monaco-esm/editor/contrib/suggest/browser/suggestInlineCompletions.js';
import 'monaco-esm/editor/standalone/browser/toggleHighContrast/toggleHighContrast.js';
import 'monaco-esm/editor/contrib/toggleTabFocusMode/browser/toggleTabFocusMode.js';
import 'monaco-esm/editor/contrib/tokenization/browser/tokenization.js';
import 'monaco-esm/editor/contrib/unicodeHighlighter/browser/unicodeHighlighter.js';
import 'monaco-esm/editor/contrib/unusualLineTerminators/browser/unusualLineTerminators.js';
import 'monaco-esm/editor/contrib/wordHighlighter/browser/wordHighlighter.js';
import 'monaco-esm/editor/contrib/wordOperations/browser/wordOperations.js';
import 'monaco-esm/editor/contrib/wordPartOperations/browser/wordPartOperations.js';
import 'monaco-esm/editor/browser/coreCommands.js';
import 'monaco-esm/editor/contrib/caretOperations/browser/caretOperations.js';
import 'monaco-esm/editor/contrib/dropOrPasteInto/browser/copyPasteContribution.js';
import 'monaco-esm/editor/contrib/find/browser/findController.js';
import 'monaco-esm/editor/contrib/gotoSymbol/browser/goToCommands.js';
import 'monaco-esm/editor/contrib/gotoError/browser/markerSelectionStatus.js';
import 'monaco-esm/editor/contrib/semanticTokens/browser/documentSemanticTokens.js';
import 'monaco-esm/editor/contrib/suggest/browser/suggestController.js';
import 'monaco-esm/editor/common/standaloneStrings.js';
import 'monaco-esm/base/browser/ui/codicons/codicon/codicon-modifiers.css';

// 言語(色分けの定義と、補完・診断などの言語サービス)
import 'monaco-esm/languages/definitions/html/register.js';
import 'monaco-esm/languages/definitions/css/register.js';
import 'monaco-esm/languages/definitions/javascript/register.js';
import 'monaco-esm/languages/definitions/typescript/register.js';
import 'monaco-esm/languages/definitions/xml/register.js';
import 'monaco-esm/languages/definitions/markdown/register.js';
import * as htmlService from 'monaco-esm/languages/features/html/register.js';
import * as cssService from 'monaco-esm/languages/features/css/register.js';
import * as tsService from 'monaco-esm/languages/features/typescript/register.js';
import 'monaco-esm/languages/features/json/register.js';

import * as monaco from 'monaco-esm/editor/editor.api.js';

import EditorWorker from 'monaco-esm/editor/editor.worker.js?worker';
import HtmlWorker from 'monaco-esm/language/html/html.worker.js?worker';
import CssWorker from 'monaco-esm/language/css/css.worker.js?worker';
import TsWorker from 'monaco-esm/language/typescript/ts.worker.js?worker';
import JsonWorker from 'monaco-esm/language/json/json.worker.js?worker';

self.MonacoEnvironment = {
  getWorker(_workerId, label) {
    if (label === 'html' || label === 'handlebars' || label === 'razor') return new HtmlWorker();
    if (label === 'css' || label === 'scss' || label === 'less') return new CssWorker();
    if (label === 'typescript' || label === 'javascript') return new TsWorker();
    if (label === 'json') return new JsonWorker();
    return new EditorWorker();
  },
};

export { monaco, htmlService, cssService, tsService };
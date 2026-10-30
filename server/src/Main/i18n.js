/*
 * 公開ページの英語(2026-10-30、高専祭の振り返りのフェーズ 6 の 1)。
 *
 * - 言語は ?lang=en|ja → 端末に覚えたもの → ブラウザの言語(日本語でなければ英語)の順で決める
 * - 切り替えは data-km-lang-switch のボタン(右上のアカウントの欄・最初の画面)。押すと覚えて読み直す
 * - **画面の文を辞書で置き換える**(日本語の文 → 英語)。数百ある文を 1 つずつ書き換えると、日本語の画面を壊しやすいので、
 *   描いたあとの文字(と placeholder・title・aria-label)を MutationObserver で見て置き換える。辞書に無い文は日本語のまま
 * - 組み立てた文(「12 人待ち」「講義室1を目印に右へ」など)は PATTERNS の型で訳す。中の名前も辞書に当てる
 * - 地点・一時地点・ポスターの名前は、管理画面で入れた英語の名前(nameEn・titleEn。lib/english-names.php)に
 *   **app.js が読む前に**入れ替える(同梱の地図データを書き換える)。日本語の名前は読み(readings)に回して、日本語でも探せる
 * - 純粋な部分(translate)は node で試す(scripts/js/i18n.test.js)
 */
(function (root) {
    'use strict';

    const DICT = {
        // ---- 上の帯・検索・チップ ----
        '場所・企画の番号で検索': 'Search places or project numbers',
        '場所を検索': 'Search places',
        '検索の言葉を消す': 'Clear search',
        '検索の候補': 'Suggestions',
        '検索を閉じる': 'Close search',
        '🚻 トイレ': '🚻 Restrooms',
        '🏃 階段・EV': '🏃 Stairs & elevators',
        '📖 教室': '📖 Classrooms',
        '🏢 建物': '🏢 Buildings',
        '🎪 イベント': '🎪 Event',
        '🍢 食べる・飲む': '🍢 Food & drink',
        '🎨 見る(展示)': '🎨 Exhibits',
        '🧪 体験': '🧪 Hands-on',
        '🪑 休む・座れる': '🪑 Rest & seating',
        '📋 受付・救護': '📋 Reception & first aid',
        '🎤 ステージ': '🎤 Stage',
        'ジャンルで探す': 'Browse by category',
        'ログイン': 'Log in',
        'ログアウト': 'Log out',
        'さん(ログイン中)': '(logged in)',
        'アカウント設定': 'Account settings',
        'アカウント設定を開く': 'Open account settings',
        '教職員の表示を出すには、サインインし直してください': 'Sign in again to see staff-only information',
        // ---- 下の操作の帯 ----
        'はじめ': 'Start',
        '設定': 'Settings',
        '経路': 'Route',
        '不具合': 'Report a bug',
        '履歴': 'History',
        '表示調整': 'Display',
        '予定': 'Schedule',
        '案内': 'Guide',
        '案内チャットを開く': 'Open the guide chat',
        '最初の画面を開く(探し方を選ぶ)': 'Open the start screen',
        '最初の画面(探し方を選ぶ)': 'Start screen',
        '不具合を報告する(別のタブで開きます)': 'Report a bug (opens a new tab)',
        '経路の不具合を報告する(別のタブで開きます)': 'Report a routing problem (opens a new tab)',
        '全画面で見る': 'Full screen',
        '全画面をやめる': 'Exit full screen',
        '上の検索の欄を出す': 'Show the search bar',
        // ---- 最初の画面(Main/entry.js) ----
        'どこへ行きますか?': 'Where do you want to go?',
        'どうやって探しますか?': 'How would you like to search?',
        'ポスター・企画の番号から': 'By poster / project number',
        'ポスター・企画の番号': 'Poster / project number',
        'キーワードから': 'By keyword',
        'キーワード': 'Keyword',
        '部屋の名前から': 'By room name',
        '部屋の名前': 'Room name',
        'ジャンルから': 'By category',
        'ジャンルから探す': 'Browse by category',
        'すぐ地図へ': 'Go to the map',
        '地図へ': 'Map',
        '使い方を見る': 'How to use',
        'お知らせ': 'News',
        '次からこの画面を出さない(下の「🏠 はじめ」で開けます)': "Don't show this again (open it from \"🏠 Start\" below)",
        'もどる': 'Back',
        'で探す': 'Search',
        '例: 12': 'e.g. 12',
        '例: 2-1、物理実験室': 'e.g. 2-1, Physics Lab',
        '例: 電算部、たこ焼き、受付': 'e.g. computer club, takoyaki, reception',
        '見つかりませんでした。ほかの言葉か、もどって別の探し方を試してください。': 'Nothing found. Try other words, or go back and search another way.',
        'ここへ行きますか?': 'Go here?',
        'ここへの道順(出発地を選ぶ)': 'Directions here (choose a starting point)',
        '地図で場所を見る': 'Show on the map',
        'この場所へは道順を出せません(地図で場所を見てください)。': 'No directions to this place (see it on the map).',
        'どこから行きますか?': 'Where are you starting from?',
        '📍 今いる場所': '📍 Where I am',
        '📍 今いる場所:': '📍 You are at:',
        '校内の QR を読む': 'Scan a campus QR code',
        'または、出発する場所の名前で探す:': 'Or search for your starting point:',
        '出発地は経路の欄で選ぶ': 'Choose the start in the route panel',
        '近くの壁や柱に貼ってある「現在地の QR」を、スマホのカメラで読んでください。': 'Scan a "You are here" QR code on a nearby wall or pillar with your phone camera.',
        'スマホのカメラ(または QR を読むアプリ)を開く': 'Open your camera (or a QR reader app)',
        'QR に向けて、出てきたリンクを開く': 'Point it at the QR code and open the link',
        '開いた地図で、もう一度行き先を選ぶ(出発地は「今いる場所」になります)': 'On the map that opens, choose your destination again (the start becomes "Where I am")',
        'QR が見つからないときは、もどって出発する場所の名前で探してください。': "If there's no QR code nearby, go back and search for your starting point by name.",
        '閉じて地図を見る': 'Close and view the map',
        // ---- 地点の詳細 ----
        '🧭 経路': '🧭 Directions',
        'ここから出発': 'Start here',
        '↗ 共有': '↗ Share',
        '☆ ブックマーク': '☆ Bookmark',
        '★ ブックマーク済み': '★ Bookmarked',
        '普段の名前': 'Usual name',
        '部屋番号': 'Room no.',
        '担当': 'In charge',
        '分類': 'Type',
        'メモ': 'Note',
        '備考': 'Note',
        '立入禁止': 'No entry',
        '企画': 'Project',
        'くわしく見る': 'More info',
        '🌐 外のサイトで見る ↗': '🌐 Open external site ↗',
        '中へ': 'Go inside',
        '建物そのものは行き先にできません。中の部屋を選んでください。': "A building can't be a destination. Choose a room inside.",
        'この地点はまだ道につながっていないので、案内できません。': "This spot isn't connected to a path yet, so no directions.",
        'この場所の予定': 'Schedule here',
        '開催中': 'Now on',
        'スタッフだけ': 'Staff only',
        '屋台の今': 'Stall status',
        '売り切れ': 'Sold out',
        '待ちなし': 'No wait',
        'たった今': 'just now',
        '臨時の地点': 'Temporary spot',
        '通行止め': 'Closed',
        // ---- 種類 ----
        'トイレ': 'Restroom',
        '部屋': 'Room',
        '施設': 'Facility',
        '建物': 'Building',
        '階段': 'Stairs',
        'エレベーター': 'Elevator',
        '出入口': 'Entrance',
        '出入り口': 'Entrance',
        '外': 'Outside',
        '受付': 'Reception',
        '受付・保健室': 'Reception / nurse',
        '救護': 'First aid',
        '休憩': 'Rest area',
        '休める所': 'Places to rest',
        '食堂・売店': 'Cafeteria / shop',
        '模擬店': 'Food stall',
        '展示': 'Exhibit',
        '体験': 'Hands-on',
        'ステージ': 'Stage',
        'その他': 'Other',
        '臨時': 'Temporary',
        '壁': 'Wall',
        '通路の点': 'Path point',
        'Wi-Fi ルーター': 'Wi-Fi router',
        // ---- 経路 ----
        'ルート案内': 'Directions',
        '経路を調べる': 'Find a route',
        '出発地': 'From',
        '目的地': 'To',
        '出発地(いまいる場所)': 'From (where you are)',
        '出発地・目的地の入力': 'Start and destination',
        '出発地と目的地を入れ替える': 'Swap start and destination',
        '出発地の候補': 'Start suggestions',
        '目的地の候補': 'Destination suggestions',
        '経路の設定': 'Route options',
        '階を移るとき': 'Changing floors',
        '指定なし': 'Any',
        '階段優先': 'Prefer stairs',
        'エレベーター優先': 'Prefer elevators',
        '階の移動を減らす': 'Fewer floor changes',
        '雨の日(外をなるべく通らない)': 'Rainy day (stay indoors)',
        '雨の日': 'Rainy day',
        '案内開始': 'Start guidance',
        '案内をやめる': 'Stop guidance',
        '📍 画面で順に案内する': '📍 Step-by-step guidance',
        '位置の更新': 'Update my position',
        'この経路が使う階': 'Floors on this route',
        'この経路を共有': 'Share this route',
        '行き先を地図の真ん中に出す': 'Center the destination',
        '使う階': 'Floors used',
        '出発': 'Start',
        '到着': 'Arrive',
        '経路が見つかりませんでした': 'No route found',
        '出発地と目的地が同じです。': 'Start and destination are the same.',
        'リストから出発地と目的地を選択または正しく入力してください': 'Choose the start and destination from the list',
        '出発地を選んでください(検索するか、地図の地点を押して「ここから出発」)': 'Choose a start (search, or tap a spot and "Start here")',
        '目的地を選んでください(検索するか、地図の地点を押して「経路」)': 'Choose a destination (search, or tap a spot and "Directions")',
        '地図の地点か、下の候補を押してください': 'Tap a spot on the map or a suggestion below',
        '✕ で案内を終えます': 'Tap ✕ to end guidance',
        '目印': 'Landmark',
        '右へ曲がる': 'Turn right',
        '左へ曲がる': 'Turn left',
        '右斜め前へ': 'Bear right',
        '左斜め前へ': 'Bear left',
        '引き返す': 'Turn back',
        '右へ': 'right',
        '左へ': 'left',
        '▲上行き': '▲ Up',
        '▼下行き': '▼ Down',
        'イベント中の通行止めにより、経路が見つかりませんでした。\n地図上の赤い破線が通れない場所です。': 'No route because of event closures.\nRed dashed lines on the map are closed.',
        'リンクを写しました': 'Link copied',
        'このリンクを写してください': 'Copy this link',
        // ---- 階 ----
        '階の切り替え': 'Floors',
        '階の一覧を閉じる': 'Close floor list',
        '階を選ぶ': 'Choose floor',
        // ---- 予定 ----
        'スケジュール': 'Schedule',
        '一覧': 'List',
        '会場 × 時間': 'Venue × time',
        '予定の見せ方': 'Schedule view',
        '地図で見る': 'Show on map',
        '地図で見る:': 'Show on map:',
        '教職員・係のアカウントにだけ見えています': 'Visible only to staff accounts',
        // ---- 案内チャット ----
        '💬 案内': '💬 Guide',
        '模擬店や場所を言葉で聞く': 'Ask about stalls and places',
        '模擬店や場所を聞いてください。企画番号で聞くと、いちばん確実に見つかります。': 'Ask about stalls or places. Asking by project number works best.',
        '質問を入力': 'Type your question',
        '質問': 'Question',
        '送る': 'Send',
        '考えています…': 'Thinking…',
        '例(押すと聞けます)': 'Examples (tap to ask)',
        'トイレはどこ？': 'Where is the restroom?',
        '近くのトイレは？': 'Nearest restroom?',
        '休憩できる場所は？': 'Where can I rest?',
        '食べ物の店を教えて': 'Food stalls?',
        'おすすめ・人気の店は？': 'Popular stalls?',
        'いちばん空いている店は？': 'Least crowded stall?',
        '企画番号 8 の場所を教えて': 'Where is project 8?',
        '地図に経路を出す': 'Show route on map',
        '目的地にして出発地を選ぶ': 'Set as destination',
        'この答えはどうでしたか?': 'Was this answer helpful?',
        '👍 よい': '👍 Good',
        '👎 よくない': '👎 Not good',
        'よい答えだった': 'Good answer',
        'よくない答えだった': 'Bad answer',
        'ありがとうございました。': 'Thank you.',
        'ありがとうございました。案内をよくするために使います。': 'Thank you. We will use this to improve the guide.',
        'よくなかった理由を選んでください': 'What was wrong?',
        '理由を 1 つ選んでください。': 'Please choose a reason.',
        '場所や店が違う': 'Wrong place or stall',
        '経路が違う': 'Wrong route',
        '答えの内容がまちがっている': 'The answer is wrong',
        '答えが分かりにくい': 'Hard to understand',
        '答えが遅い': 'Too slow',
        '探しているものが出てこない': "Didn't find what I wanted",
        'ひとこと(なくてもよい)': 'Comment (optional)',
        'よくなかった理由のひとこと': 'Comment',
        '評価を送れませんでした。': "Couldn't send your rating.",
        '送れませんでした。電波を確かめて、もう一度試してください。': "Couldn't send. Check your connection and try again.",
        'いま案内できません。しばらくしてから試してください。': 'The guide is unavailable now. Please try again later.',
        'いまいる場所が分かりません。「◯◯から△△への行き方」のように場所の名前を入れてください': 'Your position is unknown. Ask like "from X to Y".',
        '🐞 不具合を報告': '🐞 Report a bug',
        '関係': 'Relevance',
        'AIは間違えることがあります。大事なことは会場の案内や係の人に確かめてください。質問と答えは、案内をよくするために保存します(誰が聞いたかは残しません)。':
            'AI can make mistakes. Check important things with the information desk or staff. Questions and answers are saved anonymously to improve the guide.',
        // ---- 履歴 ----
        '履歴とブックマーク': 'History & bookmarks',
        'ブックマーク': 'Bookmarks',
        '開いた場所': 'Opened places',
        '経路の履歴': 'Route history',
        '検索した言葉': 'Searches',
        'まだありません。': 'Nothing yet.',
        '履歴を消す': 'Clear history',
        '履歴を全部消す': 'Clear all history',
        '履歴を消しました': 'History cleared',
        '地点の詳細の ☆ でブックマークできます。': 'Bookmark places with ☆ in their details.',
        '前回の経路': 'Last route',
        '表示する': 'Show',
        'しない': 'No',
        '残す': 'Keep',
        '経路は残す': 'Keep routes',
        '経路も消す': 'Delete routes too',
        'この端末から消す': 'Remove from this device',
        'この端末だけ': 'This device only',
        'この端末の中だけに残ります(サインインすると端末どうしで揃えられます)。': 'Saved on this device only (sign in to sync across devices).',
        // ---- 表示調整 ----
        '既定に戻す': 'Reset to default',
        '文字': 'Text',
        '大きさ': 'Size',
        '配色': 'Colors',
        '明るい': 'Light',
        '暗い': 'Dark',
        '端末の設定に合わせる': 'Follow device',
        '軽い表示': 'Lite mode',
        '自動(遅い端末だけ)': 'Auto (slow devices)',
        'いつも軽くする': 'Always',
        '軽くしない': 'Never',
        '場所ごとの色分け': 'Color areas',
        '出すもの': 'Show',
        '名前の出し方': 'Labels',
        '図形と文字': 'Shape and text',
        '図形だけ': 'Shape only',
        '文字だけ': 'Text only',
        '部屋名と建物名': 'Room and building names',
        '暗い配色のとき床の画像も暗く': 'Darken floor plans in dark mode',
        // ---- 読み込み・錠 ----
        '地図を準備しています': 'Preparing the map',
        '読み込みを開始しています…': 'Starting…',
        '準備ができました': 'Ready',
        '見取り図を読み込んでいます…': 'Loading floor plans…',
        '地図データを読み込めませんでした。時間をおいて再度お試しください。': "Couldn't load the map. Please try again later.",
        'この階の見取り図を読み込めませんでした。通信環境を確認して、もう一度お試しください。': "Couldn't load this floor plan. Check your connection and try again.",
        'もう一度試す': 'Try again',
        '現在、地図は公開されていません。': 'The map is not public right now.',
        'パスワードが必要です': 'Password required',
        'パスワード': 'Password',
        '解除': 'Unlock',
        '見つかりませんでした。': 'Nothing found.',
        '見つかりませんでした。もしかして、こちらですか？': 'Nothing found. Did you mean this?',
        '見つかりませんでした。別の言葉で探すか、下の種類のボタンを使ってください。': 'Nothing found. Try other words or the category buttons.',
        'この範囲に選べる地点がありません。地図を動かすか、階を切り替えてください。': 'No places here. Move the map or change floors.',
        'この場所は今は案内していません(イベントが終わったのかもしれません)。': 'This place is not available now (the event may have ended).',
        'この部屋は今は案内していません。地図から探してください。': 'This room is not available now. Find it on the map.',
        'この QR の場所は今は案内していません。地図から探してください。': "This QR code's place is not available now.",
        '共有された経路の場所は、今は案内していません。地図から探してください。': 'The shared route is not available now.',
        'このポスターは今は掲示していません。': 'This poster is not on display now.',
        // ---- 設定(⚙️)・アカウント ----
        '管理者ログイン': 'Admin login',
        '一般ログイン': 'Log in',
        'まだ配布用の APK はありません。': 'No APK available yet.',
        '使い方や、教職員氏名の表示についての質問をまとめています。': 'Answers to common questions about using the map.',
        '不具合の報告やご要望はこちらからお送りください。': 'Send bug reports and requests here.',
        'このサービスの利用条件と、情報の取り扱いについて。': 'Terms of service and how we handle information.',
        'はじめに': 'Introduction',
        'はじめにを読む': 'Read the introduction',
        '初めて開いたときに出した案内(利用規約・メールについて)を、もう一度読めます。': 'Read the first-time notice (terms and email) again.',
        '地図の QR': 'Map QR code',
        'アクセスコードの QR': 'Access code QR',
        'アンケートの QR': 'Survey QR',
        'アンケートにご協力ください': 'Please take our survey',
        '教職員氏名': 'Staff names',
        '地図の表示': 'Map display',
        '教員室・研究室と教職員氏名は、本校の教職員としてサインインしたときだけ表示します。': 'Staff rooms, labs and staff names are shown only to school staff who sign in.',
        '✅ 教職員としてサインインしています。教員室・研究室を表示し、先生が許可した地点では氏名とメモも表示します。': '✅ Signed in as staff. Staff rooms and labs are shown, with names where teachers allowed it.',
        '地図・経路・見取り図を見るにはパスワードが必要です。': 'A password is needed to see the map.',
        '地図・経路・見取り図を見るにはパスワードを入力してください。': 'Enter the password to see the map.',
        'パスワードを入力してください。': 'Please enter the password.',
        'パスワードが違います。': 'Wrong password.',
        '確認中...': 'Checking...',
        '解除しました。再読み込みします...': 'Unlocked. Reloading...',
        '試行回数が多すぎます。しばらく待ってから再試行してください。': 'Too many attempts. Please wait and try again.',
        'サーバーから応答がありません。時間をおいて再試行してください。': 'No response from the server. Please try again later.',
        '通信エラーが発生しました。': 'A network error occurred.',
        '外全体図': 'Campus overview',
        // ---- 表示調整(続き) ----
        '拡大したときの建物名(外)': 'Building names when zoomed (outside)',
        'Wi-Fi ルーターの位置': 'Wi-Fi routers',
        '通路の点と線': 'Path points and lines',
        '壁の線': 'Wall lines',
        '経路の長さ': 'Route length',
        '種類ごと': 'By type',
        '出す': 'Show',
        'そのまま出す': 'Show as is',
        '図形を消して名前を薄く出す': 'Hide shapes, fade names',
        '地点': 'Spots',
        // ---- 使い方(チュートリアル) ----
        'ここに行きたい場所の名前を打つと、近い順に候補が出ます。候補を選ぶと、その場所が開きます。': 'Type the place you want to go. Pick a suggestion to open it.',
        'トイレや階段は、ここのボタンでまとめて探せます。': 'Use these buttons to find restrooms and stairs.',
        '地図の上の点(部屋)を押しても、その場所の詳しい情報が出ます。': 'Tap a spot (room) on the map for details.',
        '「経路」を押すと、ここまでの道順を調べられます。': 'Tap "Directions" to get a route here.',
        '「経路」を押して、道順を調べる欄を開いてください。': 'Tap "Route" to open the route panel.',
        'いまいる場所(出発地)を打って選ぶと、地図に道順が出ます。': 'Type and choose where you are; the route appears on the map.',
        '階はここで切り替えます。「外」は校舎の外の地図です。道順を出したままでも、拡大したまま移れます。': 'Change floors here. "Outside" is the campus map. The route and zoom stay.',
        '場所の詳細の「共有」で、その場所や道順のリンクを送れます。': 'Use "Share" in place details to send a link.',
        '🎉 チュートリアルが終わりました': '🎉 Tutorial complete',
        '↺ 始めから': '↺ Start over',
        '広告': 'Ad',
        '広告を閉じる': 'Close ad',
        // ---- 履歴(続き) ----
        'アカウントで揃えています(経路はこの端末だけ)。': 'Synced with your account (routes stay on this device).',
        'アカウントで同じアカウントの端末と揃えています。': 'Synced across devices on your account.',
        'その経路の地点は、いまの地図にありません': "That route's places are not on the current map",
        '(地図に無い地点)': '(not on the map)',
        '開いた場所と検索した言葉を消します(ブックマークは残ります)。': 'Clears opened places and searches (bookmarks stay).',
        // ---- はじめに(最初に開いたときの案内) ----
        '校内の建物・部屋を探して、経路を案内する地図です。ログインしなくても使えます。': 'A map that finds buildings and rooms on campus and shows you the way. No login needed.',
        '使う前に一度お読みください。使い始めた時点で、利用規約に同意したものとして扱います。': 'Please read before use. By using the map you agree to the terms of service.',
        'メールが届かないときは迷惑メールフォルダを確認してください': "If an email doesn't arrive, check your spam folder",
        'ログインの確認コードやお問い合わせの返信は、迷惑メールとして振り分けられることがあります。 このサイトからのメールを受信できるように設定しておくと確実です。': 'Login codes and replies to inquiries may be marked as spam. Allow mail from this site to be sure.',
        'ログインの確認コードやお問い合わせの返信は、迷惑メールとして振り分けられることがあります。': 'Login codes and replies to inquiries may be marked as spam.',
        'Web 版は端末の位置情報を読みません。案内中に「位置の更新」を押し、今見えている部屋の名前を選ぶと、そこから案内し直します。': 'The web version does not read your location. During directions, tap "Update location" and choose a room you can see to re-route from there.',
        '行きたい場所を検索して、経路を調べてみてください。': 'Search for where you want to go and get directions.',
        'スマホでもマップを見ることができます。QR を読むか、': 'You can also use the map on your phone. Scan the QR code or',
        'を開いてください。': 'open the link.',
        // ---- そのほか ----
        '閉じる': 'Close',
        'やめる': 'Cancel',
        '消す': 'Clear',
        'リセット': 'Reset',
        '戻る': 'Back',
        '広げる': 'Expand',
        '縮める': 'Collapse',
        '次へ': 'Next',
        'スキップ': 'Skip',
        'お問い合わせ': 'Contact',
        'お問い合わせフォーム': 'Contact form',
        '利用規約': 'Terms of use',
        'プライバシーポリシー': 'Privacy policy',
        '利用規約とプライバシーポリシー': 'Terms and privacy',
        '規約とポリシー': 'Terms & policy',
        'よくある質問': 'FAQ',
        'よくある質問を見る': 'View FAQ',
        'ポスター': 'Posters',
        '高専マップ案内': 'Kosen Campus Map',
        '📍 高専マップ案内へようこそ': '📍 Welcome to the Kosen Campus Map',
        'はじめる': 'Get started',
        '操作の案内': 'How to use',
        '操作の案内を見る': 'How to use',
        '操作方法': 'Controls',
        '🧪 テスト中': '🧪 Testing',
        'Android アプリ': 'Android app',
        'APK をダウンロード': 'Download APK',
        'ダウンロード・設定': 'Downloads & settings',
        'ダウンロード・設定 🔧': 'Downloads & settings 🔧',
        '現在地は使いません': "We don't use your location",
        'イベント中です。部屋の名前が変わっていたり、通れない道や出していない場所があります。': 'An event is on. Some rooms are renamed, and some paths and places are closed.',
        'イベント期間中は、案内をよくするために、この画面での操作(検索した語・選んだ場所・開いた部屋・階の切り替え)を記録します。お名前やログインの情報は記録しません。':
            'During the event we record actions on this page (searches, selected places, floors) to improve the guide. We never record names or login details.',
    };

    const WEEKDAYS = { '日': 'Sun', '月': 'Mon', '火': 'Tue', '水': 'Wed', '木': 'Thu', '金': 'Fri', '土': 'Sat' };
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const TURN_EN = { '右へ': 'Turn right', '左へ': 'Turn left', '右斜め前へ': 'Bear right', '左斜め前へ': 'Bear left' };

    // 組み立てた文の型。t は中の名前を訳す(辞書に無ければそのまま)
    const PATTERNS = [
        [/^(\d+)月(\d+)日\((.)\)$/, (m) => (WEEKDAYS[m[3]] || m[3]) + ', ' + MONTHS[Number(m[1]) - 1] + ' ' + Number(m[2])],
        [/^(\d+) 人待ち$/, (m) => m[1] + ' waiting'],
        [/^(.+)に更新$/, (m, t) => 'updated ' + t(m[1])],
        [/^(\d+) 分前$/, (m) => m[1] + ' min ago'],
        [/^(\d+) 時間前$/, (m) => m[1] + ' h ago'],
        [/^(\d+) 件$/, (m) => m[1] + (m[1] === '1' ? ' result' : ' results')],
        [/^見取り図を読み込んでいます… (\d+)\/(\d+)$/, (m) => 'Loading floor plans… ' + m[1] + '/' + m[2]],
        [/^地点を準備しています… (\d+)\/(\d+)$/, (m) => 'Preparing places… ' + m[1] + '/' + m[2]],
        [/^いいね\((\d+)\)$/, (m) => 'Like (' + m[1] + ')'],
        [/^いいねを外す\((\d+)\)$/, (m) => 'Unlike (' + m[1] + ')'],
        [/^企画 (.+) の場所$/, (m) => 'Location of project ' + m[1]],
        [/^企画 (.+)$/, (m) => 'Project ' + m[1]],
        [/^約 ([\d.]+) ?m$/, (m) => 'about ' + m[1] + ' m'],
        [/^地図で見る: (.+)$/, (m, t) => 'Show on map: ' + t(m[1])],
        [/^(.+)を目印に(右へ|左へ|右斜め前へ|左斜め前へ)$/, (m, t) => TURN_EN[m[2]] + ' at ' + t(m[1])],
        [/^(.+)で引き返す$/, (m, t) => 'Turn back at ' + t(m[1])],
        [/^▲ (.+) へ上る$/, (m, t) => '▲ Up to ' + t(m[1])],
        [/^▼ (.+) へ下りる$/, (m, t) => '▼ Down to ' + t(m[1])],
        [/^エレベーターで (.+) へ$/, (m, t) => 'Elevator to ' + t(m[1])],
        [/^(.+) から到着$/, (m, t) => 'Arriving from ' + t(m[1])],
        [/^(.+)に到着しました$/, (m, t) => 'Arrived at ' + t(m[1])],
        [/^(.+)に向かう$/, (m, t) => 'Head to ' + t(m[1])],
        [/^次: (\S+) (.+)$/, (m, t) => 'Next: ' + m[1] + ' ' + t(m[2])],
        [/^着いたら (.+) へ$/, (m, t) => 'then go to ' + t(m[1])],
        [/^(.+)から (.+) へ$/, (m, t) => 'From ' + t(m[1]) + ' to ' + t(m[2])],
        [/^(.+)を見る$/, (m, t) => 'View ' + t(m[1])],
        [/^(.+)の会場と時間$/, (m, t) => 'Venues and times on ' + t(m[1])],
        [/^「(.+)」を消す$/, (m) => 'Remove "' + m[1] + '"'],
        [/^(.+)(\(大きく見る\))$/, (m) => m[1] + ' (enlarge)'],
        [/^地点 (\d+)%$/, (m) => 'Spots ' + m[1] + '%'],
        [/^文字 (\d+)%$/, (m) => 'Text ' + m[1] + '%'],
        [/^(\d+)階$/, (m) => 'Floor ' + m[1]],
        [/^(\d+)号棟$/, (m) => 'Building ' + m[1]],
        // 数を添えた札(「ブックマーク(0)」)
        [/^(.+)\((\d+)\)$/, (m, t) => t(m[1]) + ' (' + m[2] + ')'],
    ];
    // 文の中の言葉(屋台の要約「3 人待ち・焼きそば 400 円 ほか 2 品」など)
    const INLINE = [
        [/(\d+) 人待ち/g, '$1 waiting'],
        [/(\d+) 円/g, '¥$1'],
        [/ほか (\d+) 品/g, '+$1 more'],
        [/待ちなし/g, 'no wait'],
        [/売り切れ/g, 'sold out'],
        [/🚶 約 ([\d.]+) ?m/g, '🚶 about $1 m'],
    ];
    const SEPARATORS = [' · ', '・', '、'];
    const JAPANESE = /[぀-ヿ一-鿿]/;

    /** 1 つの文を英語に。訳せなければ元のまま。**純粋関数** */
    function translate(text) {
        const raw = String(text);
        if (!JAPANESE.test(raw)) return raw;
        const lead = raw.match(/^\s*/)[0];
        const tail = raw.match(/\s*$/)[0];
        // HTML の改行と字下げ(「…です。\n            ログイン…」)は 1 つの空白にして辞書を引く。訳せなければ元のまま返す
        const body = raw.trim().replace(/\s+/g, ' ');
        const out = translateBody(body, 0);
        return out === body ? raw : lead + out + tail;
    }

    function translateBody(body, depth) {
        if (!JAPANESE.test(body) || depth > 4) return body;
        if (Object.prototype.hasOwnProperty.call(DICT, body)) return DICT[body];
        const t = (s) => translateBody(String(s).trim(), depth + 1);
        for (const [re, fn] of PATTERNS) {
            const m = re.exec(body);
            if (m) return fn(m, t);
        }
        // 絵文字や記号の頭(「🍽 たこ焼き屋」)は外して訳す
        const prefix = /^([^\p{L}\p{N}]+?)\s*(\p{L}.*)$/u.exec(body);
        if (prefix && prefix[1].trim() !== '' && Object.prototype.hasOwnProperty.call(DICT, prefix[2])) {
            return prefix[1] + (prefix[1].endsWith(' ') ? '' : ' ') + DICT[prefix[2]];
        }
        for (const sep of SEPARATORS) {
            if (body.includes(sep)) {
                const parts = body.split(sep);
                const out = parts.map(t);
                if (out.some((p, i) => p !== parts[i].trim())) return out.join(sep === '、' ? ', ' : (sep === '・' ? ' · ' : sep));
            }
        }
        let inline = body;
        INLINE.forEach(([re, to]) => { inline = inline.replace(re, to); });
        return inline;
    }

    /** 言語を決める(?lang= → 覚えたもの → ブラウザの言語)。**純粋関数** */
    function pickLanguage(search, saved, browserLanguages) {
        const param = /[?&]lang=(en|ja)\b/.exec(String(search || ''));
        if (param) return param[1];
        if (saved === 'en' || saved === 'ja') return saved;
        const first = (browserLanguages || [])[0] || 'ja';
        return /^ja\b/i.test(first) ? 'ja' : 'en';
    }

    /** 地図データの名前を英語の名前に入れ替える(日本語の名前は読みに回す)。**引数を書き換える** */
    function swapNames(data) {
        if (!data || typeof data !== 'object') return data;
        Object.values(data.nodes || {}).forEach((node) => {
            if (node && node.nameEn) {
                node.readings = (node.readings || []).concat([node.name]);
                node.name = node.nameEn;
            }
        });
        (data.eventPois || []).forEach((poi) => {
            if (poi && poi.nameEn) {
                poi.readings = (poi.readings || []).concat([poi.name]);
                poi.name = poi.nameEn;
            }
        });
        (data.posters || []).forEach((poster) => {
            if (poster && poster.titleEn) {
                poster.readings = (poster.readings || []).concat([poster.title]);
                poster.title = poster.titleEn;
            }
        });
        return data;
    }

    const api = { translate: translate, pickLanguage: pickLanguage, swapNames: swapNames, DICT: DICT };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
        return;
    }
    root.KmI18n = api;

    // ---- ここから下はブラウザだけ ----
    const KEY = 'km-lang';
    let saved = null;
    try { saved = root.localStorage.getItem(KEY); } catch (e) { /* 覚えられない端末 */ }
    const lang = pickLanguage(root.location.search, saved, root.navigator.languages || [root.navigator.language]);
    api.lang = lang;
    document.documentElement.lang = lang;

    function setLanguage(next) {
        try { root.localStorage.setItem(KEY, next); } catch (e) { /* 覚えられなくても、この回は ?lang= で */ }
        const url = new URL(root.location.href);
        url.searchParams.set('lang', next);
        root.location.replace(url.toString());
    }
    document.addEventListener('click', function (event) {
        const button = event.target.closest && event.target.closest('[data-km-lang-switch]');
        if (!button) return;
        event.preventDefault();
        setLanguage(lang === 'en' ? 'ja' : 'en');
    });
    function labelSwitches(scope) {
        const found = Array.from(scope.querySelectorAll ? scope.querySelectorAll('[data-km-lang-switch]') : []);
        if (scope.matches && scope.matches('[data-km-lang-switch]')) found.push(scope);
        found.forEach(function (button) {
            button.textContent = lang === 'en' ? '🌐 日本語' : '🌐 English';
            button.setAttribute('lang', lang === 'en' ? 'ja' : 'en');
        });
    }

    // 日本語のときは何も置き換えない(切り替えのボタンは初めから「🌐 English」)
    if (lang !== 'en') return;

    // 地図のデータの名前(app.js が読む前。この script は app.js より先に読む)
    const inline = document.getElementById('km-map-data');
    if (inline) {
        try {
            inline.textContent = JSON.stringify(swapNames(JSON.parse(inline.textContent)));
        } catch (e) { /* 読めなければ app.js が取り直す。名前は日本語のまま */ }
    }

    const ATTRS = ['placeholder', 'title', 'aria-label', 'alt'];
    const SKIP = 'script,style,textarea,code,pre,[translate="no"],[data-km-no-translate]';

    function translateElement(el) {
        if (el.closest && el.closest(SKIP)) return;
        ATTRS.forEach(function (name) {
            const value = el.getAttribute && el.getAttribute(name);
            if (value && JAPANESE.test(value)) {
                const next = translate(value);
                if (next !== value) el.setAttribute(name, next);
            }
        });
    }
    function translateTree(rootNode) {
        if (rootNode.nodeType === 3) {
            translateText(rootNode);
            return;
        }
        if (rootNode.nodeType !== 1) return;
        // 言語の切り替えは訳さず、「日本語」の札にする(後から足された最初の画面のボタンも)
        labelSwitches(rootNode);
        if (rootNode.closest && rootNode.closest(SKIP)) return;
        translateElement(rootNode);
        rootNode.querySelectorAll('*').forEach(translateElement);
        const walker = document.createTreeWalker(rootNode, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode())) translateText(node);
    }
    function translateText(node) {
        const value = node.nodeValue;
        if (!value || !JAPANESE.test(value)) return;
        const parent = node.parentElement;
        if (parent && parent.closest(SKIP)) return;
        const next = translate(value);
        if (next !== value) node.nodeValue = next;
    }

    function start() {
        document.title = translate(document.title);
        labelSwitches(document);
        translateTree(document.body);
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                if (record.type === 'characterData') translateText(record.target);
                else if (record.type === 'attributes') translateElement(record.target);
                else record.addedNodes.forEach(translateTree);
            });
        }).observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ATTRS });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
}(typeof window !== 'undefined' ? window : globalThis));

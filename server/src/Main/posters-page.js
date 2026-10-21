// posters-page.js
//
// ポスターの一覧のページ(/posters.php)で、画像を押したら**大きく見る幕**を開く(2026-10-22、利用者の指示
// 「押して飛ばすより、押して拡大してから飛ぶほうがいい」)。幕の中の「地図で見る」「くわしく見る」から進む。
//
//   - 幕の見た目は地図と同じ .km-poster-viewer(Main/styles.css。Main/app.js の openPosterViewer)
//   - 中身はそのカードから写す(見出し・場所・説明・画像・ボタン)。**文字は textContent で入れる**(HTML として読まない)
//   - ボタンはカードのものを写すので、「くわしく見る」の数える印(data-km-poster-link)もそのまま付いてくる(Main/poster-stats.js)
//   - Ctrl / ⌘ / Shift / 中ボタンで押したときは邪魔しない(画像のリンク先を新しいタブで開ける)
//   - 閉じる: × ・幕の外・Esc。閉じたら押した画像へ戻す

(function () {
    'use strict';

    const wall = document.querySelector('.km-poster-wall');
    if (!wall) return;

    let viewer = null;
    let opener = null;

    function build() {
        viewer = document.createElement('div');
        viewer.className = 'km-poster-viewer';
        viewer.hidden = true;
        viewer.setAttribute('role', 'dialog');
        viewer.setAttribute('aria-modal', 'true');
        viewer.setAttribute('aria-labelledby', 'km-poster-viewer-title');
        // 決まった骨組みだけ(利用者の入れた文字はここに入れない)
        viewer.innerHTML = '<div class="km-poster-viewer-card">'
            + '<button type="button" class="icon-btn km-poster-viewer-close" aria-label="閉じる">&times;</button>'
            + '<img class="km-poster-viewer-image" alt="">'
            + '<div class="km-poster-viewer-body">'
            + '<b class="km-poster-viewer-title" id="km-poster-viewer-title"></b>'
            + '<p class="km-poster-viewer-place"></p>'
            + '<p class="km-poster-viewer-desc"></p>'
            + '<div class="km-poster-card-actions"></div>'
            + '</div></div>';
        viewer.addEventListener('click', function (event) {
            if (event.target === viewer || event.target.closest('.km-poster-viewer-close')) close();
        });
        document.body.appendChild(viewer);
    }

    function onKeydown(event) {
        if (event.key === 'Escape') close();
    }

    function close() {
        if (!viewer || viewer.hidden) return;
        viewer.hidden = true;
        document.documentElement.classList.remove('km-poster-viewer-open');
        document.removeEventListener('keydown', onKeydown);
        if (opener) opener.focus();
        opener = null;
    }

    function textOf(card, selector) {
        const element = card.querySelector(selector);
        return element ? element.textContent.trim() : '';
    }

    function open(card, from) {
        if (!viewer) build();
        const source = card.querySelector('.km-poster-card-image img');
        const image = viewer.querySelector('.km-poster-viewer-image');
        image.src = source ? (source.currentSrc || source.src) : '';
        image.alt = source ? source.alt : '';

        viewer.querySelector('.km-poster-viewer-title').textContent = textOf(card, '.km-poster-card-title');
        const place = viewer.querySelector('.km-poster-viewer-place');
        place.textContent = textOf(card, '.km-poster-card-place');
        place.hidden = place.textContent === '';
        const desc = viewer.querySelector('.km-poster-viewer-desc');
        desc.textContent = textOf(card, '.km-poster-card-desc');
        desc.hidden = desc.textContent === '';

        const actions = viewer.querySelector('.km-poster-card-actions');
        actions.replaceChildren();
        card.querySelectorAll('.km-poster-card-actions a').forEach(function (link) {
            actions.appendChild(link.cloneNode(true));
        });

        opener = from;
        viewer.hidden = false;
        document.documentElement.classList.add('km-poster-viewer-open');
        document.addEventListener('keydown', onKeydown);
        viewer.querySelector('.km-poster-viewer-close').focus();
    }

    wall.addEventListener('click', function (event) {
        const link = event.target.closest('[data-km-poster-open]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const card = link.closest('.km-poster-card');
        if (!card) return;
        event.preventDefault();
        open(card, link);
    });
}());

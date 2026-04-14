<?php
require_once __DIR__ . '/../config/database.php';

$page_title = 'Browse Creators - TopicLaunch';

$creators = [];
try {
    $stmt = $db->query("SELECT * FROM users WHERE user_type = 'creator' ORDER BY created_at DESC");
    $creators = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch (PDOException $e) {
    $creators = [];
}

$topics = ['fitness', 'health', 'motivation', 'therapy', 'dating', 'business', 'money', 'psychology', 'career', 'cosmetics', 'family', 'technology & ai'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <style>
        :root {
            --tl-bg: #FAF8F6;
            --tl-pink: #E8305A;
            --tl-pink-dark: #B01F3F;
            --text-dark: #111111;
            --tl-muted: #888888;
            --white: #ffffff;
        }
        body { margin: 0; font-family: Arial, sans-serif; background: var(--tl-bg); color: var(--text-dark); }
        .hero-title { font-size: 42px; font-weight: 700; color: var(--text-dark); letter-spacing: -1px; line-height: 1.1; margin-bottom: 14px; text-align: center; }
        .hero-title em { color: var(--tl-pink); font-style: normal; }
        .strip-section { max-width: 1180px; margin: 0 auto; padding: 0 24px 40px; }
        .creator-strip { display: flex; align-items: center; gap: 12px; background: #fff; border: 1px solid #E5E5E5; border-radius: 40px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); padding: 6px; }
        .strip-avatar-wrap { position: relative; }
        .select-creator-hint { position: absolute; left: 0; top: -34px; display: flex; align-items: center; gap: 6px; color: var(--tl-pink); font-size: 11px; font-weight: 500; }
        .hint-arrow { display: block; }
        .strip-avatar { width: 44px; height: 44px; border-radius: 50%; border: none; background: var(--tl-pink); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .strip-input-field { flex: 1; border: none; outline: none; background: transparent; font-size: 13px; color: #111; }
        .strip-send { width: 40px; height: 40px; border-radius: 50%; border: none; background: var(--tl-pink); color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .strip-send:disabled { opacity: 0.35; cursor: not-allowed; }
        .strip-creator-card { display: none; margin: 12px auto 0; max-width: calc(100% - 60px); background: #fff; border: 1px solid #E5E5E5; border-radius: 12px; padding: 12px 16px; align-items: center; gap: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .strip-creator-card.visible { display: flex; }
        .strip-creator-card-avatar { width: 38px; height: 38px; border-radius: 50%; background: var(--tl-pink); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; overflow: hidden; }
        .strip-creator-card-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .strip-creator-card-info { flex: 1; min-width: 0; }
        .strip-creator-card-name { font-weight: 600; font-size: 13px; }
        .strip-creator-card-topics { font-size: 11px; color: #888; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .strip-creator-card-price { background: #FFF0F3; color: var(--tl-pink); font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
        .strip-creator-card-x { background: none; border: none; color: #ccc; font-size: 18px; line-height: 1; cursor: pointer; padding: 0 0 0 4px; }
        .strip-creator-card-x:hover { color: var(--tl-pink); }
        .strip-hint-row { display: flex; flex-direction: column; align-items: center; gap: 10px; margin-top: 20px; padding: 0 30px; }
        .strip-hint-step { display: flex; align-items: center; gap: 10px; font-size: 12px; color: var(--tl-muted); width: 220px; }
        .strip-hint-step strong { width: 18px; height: 18px; border-radius: 50%; background: var(--tl-pink); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; flex-shrink: 0; }
    </style>
</head>
<body>
    <div class="strip-section">
        <h1 class="hero-title">Browse <em>Creators</em></h1>
        <div class="creator-strip">
            <div class="strip-avatar-wrap">
                <div class="select-creator-hint" id="selectCreatorHint"><span>Click to Select Creator</span><svg class="hint-arrow" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M11 3V18" stroke="#E8305A" stroke-width="1.8" stroke-linecap="round"/><path d="M7 14L11 18L15 14" stroke="#E8305A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                <button class="strip-avatar" id="stripAvatar" title="Choose a creator"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg></button>
            </div>
            <input type="text" class="strip-input-field" id="topicInput" placeholder="Type your topic idea…" maxlength="100">
            <button class="strip-send" id="stripSend" disabled><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M14 8L2 2l2 6-2 6 12-6z" fill="#fff"/></svg></button>
        </div>
        <div style="text-align:right; padding: 4px 30px 0; font-size:11px; color:#bbb;" id="topicInputCount"></div>
        <div class="strip-creator-card" id="stripCreatorCard">
            <div class="strip-creator-card-avatar" id="stripCreatorCardAvatar"></div>
            <div class="strip-creator-card-info">
                <div class="strip-creator-card-name" id="stripCreatorCardName"></div>
                <div class="strip-creator-card-topics" id="stripCreatorCardTopics"></div>
            </div>
            <div class="strip-creator-card-price" id="stripCreatorCardPrice"></div>
            <button class="strip-creator-card-x" id="stripCreatorCardX" title="Remove creator">&times;</button>
        </div>
        <div class="strip-hint-row">
            <div class="strip-hint-step" id="stripStep1"><strong>1</strong> Click the avatar to pick a creator</div>
            <div class="strip-hint-step" id="stripStep2"><strong>2</strong> Type your topic idea</div>
            <div class="strip-hint-step" id="stripStep3"><strong>3</strong> Add details &amp; fund the video</div>
        </div>
    </div>
<script>
const stripAvatar = document.getElementById('stripAvatar');
const selectHint = document.getElementById('selectCreatorHint');
const topicInput = document.getElementById('topicInput');
const stripSend = document.getElementById('stripSend');
const topicInputCount = document.getElementById('topicInputCount');
const stripCreatorCard = document.getElementById('stripCreatorCard');
const stripCreatorCardAvatar = document.getElementById('stripCreatorCardAvatar');
const stripCreatorCardName = document.getElementById('stripCreatorCardName');
const stripCreatorCardTopics = document.getElementById('stripCreatorCardTopics');
const stripCreatorCardPrice = document.getElementById('stripCreatorCardPrice');
const stripCreatorCardX = document.getElementById('stripCreatorCardX');
const stripStep1 = document.getElementById('stripStep1');
let selectedCreator = null;
const defaultAvatarSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>`;
function resetSelectedCreator() {
    selectedCreator = null;
    stripCreatorCard.classList.remove('visible');
    stripStep1.style.display = '';
    stripAvatar.innerHTML = defaultAvatarSvg;
    selectHint.classList.remove('hidden');
    topicInput.placeholder = 'Type your topic idea…';
    stripSend.disabled = true;
}
function setSelectedCreator(item) {
    const topics = JSON.parse(item.dataset.topics || '[]');
    selectedCreator = { name: item.dataset.name, price: parseInt(item.dataset.price, 10) || 0, image: item.dataset.image, topics };
    stripAvatar.innerHTML = selectedCreator.image ? `<img src="/uploads/creators/${selectedCreator.image}" alt="">` : `<span class="strip-avatar-initials">${selectedCreator.name.charAt(0).toUpperCase()}</span>`;
    selectHint.classList.add('hidden');
    topicInput.placeholder = `Commission a video from ${selectedCreator.name}…`;
    stripCreatorCardAvatar.innerHTML = selectedCreator.image ? `<img src="/uploads/creators/${selectedCreator.image}" alt="">` : selectedCreator.name.charAt(0).toUpperCase();
    stripCreatorCardName.textContent = selectedCreator.name;
    stripCreatorCardTopics.textContent = topics.length ? topics.map(t => t.charAt(0).toUpperCase() + t.slice(1)).join(' · ') : '';
    stripCreatorCardPrice.textContent = selectedCreator.price ? `from $${selectedCreator.price}` : 'Free';
    stripCreatorCard.classList.add('visible');
    stripStep1.style.display = 'none';
    stripSend.disabled = !topicInput.value.trim();
}
stripCreatorCardX.addEventListener('click', resetSelectedCreator);
stripCreatorCard.addEventListener('click', e => {
    if (e.target === stripCreatorCardX || stripCreatorCardX.contains(e.target)) return;
});
document.addEventListener('click', e => {
    const item = e.target.closest('.creator-picker-item');
    if (item) setSelectedCreator(item);
});
topicInput.addEventListener('input', () => {
    stripSend.disabled = !topicInput.value.trim() || !selectedCreator;
    topicInputCount.textContent = topicInput.value.length > 0 ? `${topicInput.value.length}/100` : '';
});
</script>
</body>
</html>

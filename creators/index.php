<?php
// creators/index.php - Browse Creators page
session_start();

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../config/database.php';
    try {
        $db = new Database();
        $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
        $db->bind(':user_id', $_SESSION['user_id']);
        $is_creator = $db->single();
        if ($is_creator) { header('Location: /creators/dashboard.php'); exit; }
    } catch (Exception $e) { error_log("Creator redirect check error: " . $e->getMessage()); }
}

require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $db->query('SELECT * FROM creators WHERE is_active = 1 ORDER BY created_at DESC');
    $creators = $db->resultSet();
} catch (Exception $e) {
    $creators = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Creators - TopicLaunch</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --tl-pink: #E8305A;
            --tl-pink-dark: #B01F3F;
            --tl-bg: #FAF8F6;
            --tl-card: #FFFFFF;
            --tl-off: #F0F0F0;
            --tl-border: #E5E5E5;
            --tl-muted: #888888;
            --text-dark: #111010;
            --white: #FFFFFF;
        }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--tl-bg); color: var(--text-dark); }

        /* Nav */
        .topiclaunch-nav { background: var(--white); padding: 16px 0; border-bottom: 1px solid var(--tl-border); position: sticky; top: 0; z-index: 1000; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
        .nav-container { max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 30px; }
        .nav-logo { font-size: 20px; font-weight: 500; color: var(--text-dark); text-decoration: none; letter-spacing: -0.3px; }
        .nav-logo span { color: var(--tl-pink); }
        .nav-center { display: flex; gap: 30px; align-items: center; }
        .nav-link { color: var(--text-dark); text-decoration: none; font-size: 13px; font-weight: 500; transition: color 0.2s; }
        .nav-link:hover, .nav-link.active { color: var(--tl-pink); }
        .nav-buttons { display: flex; gap: 15px; align-items: center; }
        .nav-login-btn { color: var(--text-dark); text-decoration: none; font-size: 13px; font-weight: 500; transition: color 0.2s; }
        .nav-login-btn:hover { color: var(--tl-pink); }
        .nav-getstarted-btn { background: var(--tl-pink); color: var(--white); text-decoration: none; font-size: 13px; font-weight: 500; padding: 9px 20px; border-radius: 8px; transition: background 0.2s; }
        .nav-getstarted-btn:hover { background: var(--tl-pink-dark); }

        /* Hero section */
        .hero-section {
            text-align: center;
            padding: 80px 30px 56px;
        }
        .hero-eyebrow {
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: var(--tl-muted);
            margin-bottom: 14px;
        }
        .hero-title {
            font-size: 42px;
            font-weight: 700;
            color: var(--text-dark);
            letter-spacing: -1px;
            line-height: 1.1;
            margin-bottom: 14px;
        }
        .hero-title em {
            color: var(--tl-pink);
            font-style: normal;
        }
        .hero-title span { color: var(--tl-pink); }
        .hero-subtitle {
            font-size: 16px;
            color: var(--tl-muted);
            font-weight: 400;
            line-height: 1.6;
            max-width: 440px;
            margin: 0 auto;
        }

        /* Topic filter chips */
        .topic-filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
            margin-top: 28px;
        }
        .topic-filter-btn {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 0.4px;
            border: 1px solid var(--tl-border);
            background: var(--white);
            color: var(--tl-muted);
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
            font-family: inherit;
        }
        .topic-filter-btn:hover { border-color: var(--tl-pink); color: var(--tl-pink); }
        .topic-filter-btn.active { background: var(--tl-pink); border-color: var(--tl-pink); color: var(--white); }
        .active-filter-note {
            text-align: center;
            font-size: 11px;
            color: var(--tl-pink);
            font-weight: 400;
            margin-top: 10px;
            min-height: 16px;
        }

        /* Strip section */
        .strip-section {
            background: var(--tl-off);
            border-top: 1px solid var(--tl-border);
            border-bottom: 1px solid var(--tl-border);
            padding: 36px 0 40px;
            overflow: visible;
        }

        .creator-strip {
            display: flex;
            align-items: center;
            gap: 14px;
            width: 100%;
        }
        .creator-strip .strip-avatar-wrap { margin-left: 30px; }
        .creator-strip .strip-send { margin-right: 30px; }

        /* hint label above avatar */
        .strip-avatar-wrap {
            position: relative;
            flex-shrink: 0;
            display: flex;
            align-items: center;
        }
        .select-creator-hint {
            position: absolute;
            bottom: calc(100% + 10px);
            left: 0;
            transform: none;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 2px;
            pointer-events: none;
            white-space: nowrap;
        }
        .select-creator-hint span {
            font-size: 11px;
            font-weight: 500;
            color: var(--tl-pink);
            letter-spacing: 0.3px;
        }
        .hint-arrow { display: block; }
        .select-creator-hint.hidden { display: none; }

        .strip-avatar {
            width: 44px; height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--tl-pink), var(--tl-pink-dark));
            display: flex; align-items: center; justify-content: center;
            color: var(--white);
            flex-shrink: 0;
            border: none;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
            overflow: hidden;
            padding: 0;
        }
        .strip-avatar:hover { opacity: 0.85; transform: scale(1.06); }
        .strip-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .strip-avatar-initials { font-size: 16px; font-weight: 600; color: var(--white); }

        .strip-input-field {
            flex: 1;
            background: var(--white);
            border-radius: 10px;
            padding: 12px 16px;
            border: 1px solid var(--tl-border);
            font-size: 14px;
            color: var(--text-dark);
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s, opacity 0.2s;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .strip-input-field::placeholder { color: #bbb; }
        .strip-input-field:focus { border-color: rgba(232,48,90,0.4); box-shadow: 0 0 0 3px rgba(232,48,90,0.08); }
        .strip-input-field:disabled { opacity: 0.5; cursor: not-allowed; }

        .strip-send {
            width: 44px; height: 44px;
            background: var(--tl-pink);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            border: none;
            cursor: pointer;
            transition: background 0.2s, opacity 0.2s;
        }
        .strip-send:hover:not(:disabled) { background: var(--tl-pink-dark); }
        .strip-send:disabled { opacity: 0.35; cursor: not-allowed; }

        /* Selected creator card */
        .strip-creator-card {
            display: none;
            margin: 12px auto 0;
            max-width: calc(100% - 60px);
            background: #fff;
            border: 1px solid #E5E5E5;
            border-radius: 12px;
            padding: 12px 16px;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            animation: fadeSlideIn 0.2s ease;
        }
        .strip-creator-card.visible { display: flex; }
        @keyframes fadeSlideIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
        .strip-creator-card-avatar {
            width: 38px; height: 38px; border-radius: 50%;
            background: var(--tl-pink); flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 15px; color: #fff; overflow: hidden;
        }
        .strip-creator-card-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .strip-creator-card-info { flex: 1; min-width: 0; }
        .strip-creator-card-name { font-weight: 600; font-size: 13px; color: #111; }
        .strip-creator-card-topics { font-size: 11px; color: #888; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .strip-creator-card-price { background: #FFF0F3; color: var(--tl-pink); font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px; flex-shrink: 0; }
        .strip-creator-card-change { font-size: 11px; color: #aaa; cursor: pointer; flex-shrink: 0; text-decoration: underline; text-underline-offset: 2px; }
        .strip-creator-card-change:hover { color: var(--tl-pink); }

        /* Step hint below strip */
        .strip-hint-row {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding: 0 30px;
        }
        .strip-hint-step {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
            color: var(--tl-muted);
            font-weight: 400;
            justify-content: flex-start;
            width: 220px;
        }
        .strip-hint-step strong {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px; height: 18px;
            background: var(--tl-pink);
            color: var(--white);
            border-radius: 50%;
            font-size: 10px;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* Modals */
        .tl-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(4px);
        }
        .tl-overlay.open { display: flex; }

        .tl-modal {
            background: var(--white);
            border: 1px solid var(--tl-border);
            border-radius: 16px;
            width: 100%;
            max-width: 560px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 8px 40px rgba(0,0,0,0.12);
            animation: modalIn 0.18s ease-out both;
        }
        .tl-modal-sm { max-width: 440px; }

        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.96) translateY(8px); }
            to   { opacity: 1; transform: none; }
        }

        .tl-modal-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 20px 20px 16px;
            border-bottom: 1px solid var(--tl-border);
            flex-shrink: 0;
        }
        .tl-modal-title { font-size: 15px; font-weight: 600; color: var(--text-dark); }
        .tl-modal-sub { font-size: 12px; color: var(--tl-muted); margin-top: 2px; }
        .tl-modal-close {
            background: none; border: none; color: #aaa; font-size: 20px;
            cursor: pointer; line-height: 1; padding: 0 2px; transition: color 0.15s;
        }
        .tl-modal-close:hover { color: var(--text-dark); }

        .tl-modal-search {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            border-bottom: 1px solid var(--tl-border);
            flex-shrink: 0;
            color: #bbb;
        }
        .tl-modal-search input {
            flex: 1; background: none; border: none; outline: none;
            font-size: 13px; color: var(--text-dark); font-family: inherit;
        }
        .tl-modal-search input::placeholder { color: #bbb; }

        .creator-picker-grid {
            overflow-y: auto;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            padding: 16px;
        }
        .creator-picker-item {
            background: var(--tl-off);
            border: 1px solid var(--tl-border);
            border-radius: 12px;
            padding: 16px 12px 12px;
            display: flex; flex-direction: column; align-items: center; gap: 8px;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
            text-align: center;
        }
        .creator-picker-item:hover { border-color: var(--tl-pink); background: rgba(232,48,90,0.04); }
        .creator-picker-item.selected { border-color: var(--tl-pink); background: rgba(232,48,90,0.08); }
        .creator-picker-item.hidden { display: none; }

        .picker-avatar {
            width: 52px; height: 52px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--tl-pink), var(--tl-pink-dark));
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        .picker-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .picker-avatar span { font-size: 20px; font-weight: 600; color: var(--white); }
        .picker-name { font-size: 12px; font-weight: 500; color: var(--text-dark); line-height: 1.3; }
        .picker-price { font-size: 10px; color: var(--tl-muted); }

        /* Topic details modal body */
        .tl-modal-body { padding: 20px; display: flex; flex-direction: column; gap: 16px; overflow-y: auto; }
        .tl-field { display: flex; flex-direction: column; gap: 6px; }
        .tl-label { font-size: 11px; font-weight: 500; color: var(--tl-muted); letter-spacing: 0.4px; text-transform: uppercase; }
        .tl-optional { color: var(--tl-muted); font-weight: 400; text-transform: none; letter-spacing: 0; }

        .tl-topic-preview {
            background: var(--tl-off);
            border: 1px solid var(--tl-border);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            color: var(--text-dark);
            min-height: 38px;
        }

        .tl-input-prefix-wrap { display: flex; align-items: center; background: var(--white); border: 1px solid var(--tl-border); border-radius: 8px; overflow: hidden; transition: border-color 0.2s; }
        .tl-input-prefix-wrap:focus-within { border-color: rgba(232,48,90,0.4); }
        .tl-prefix { padding: 0 10px; font-size: 14px; color: var(--tl-muted); font-weight: 500; border-right: 1px solid var(--tl-border); height: 38px; display: flex; align-items: center; }
        .tl-input { flex: 1; background: none; border: none; outline: none; padding: 0 12px; font-size: 14px; color: var(--text-dark); font-family: inherit; height: 38px; }
        .tl-input::placeholder { color: #bbb; }

        .tl-hint { font-size: 11px; color: var(--tl-muted); }
        .tl-char-count {
            font-size: 11px;
            color: var(--tl-muted);
            text-align: right;
            margin-top: -6px;
        }

        .tl-textarea {
            background: var(--white);
            border: 1px solid var(--tl-border);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            color: var(--text-dark);
            font-family: inherit;
            resize: vertical;
            outline: none;
            transition: border-color 0.2s;
        }
        .tl-textarea:focus { border-color: rgba(232,48,90,0.4); }
        .tl-textarea::placeholder { color: #bbb; }

        .tl-submit-btn {
            background: var(--tl-pink);
            color: var(--white);
            border: none;
            border-radius: 10px;
            padding: 13px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            font-family: inherit;
        }
        .tl-submit-btn:hover { background: var(--tl-pink-dark); }

        @media (max-width: 768px) {
            .nav-center { display: none; }
            .hero-section { padding: 56px 20px 40px; }
            .hero-title { font-size: 30px; }
            .strip-section { padding: 28px 0 32px; }
            .creator-strip .strip-avatar-wrap { margin-left: 20px; }
            .creator-strip .strip-send { margin-right: 20px; }
            .strip-hint-row { gap: 16px; flex-wrap: wrap; }
        }
    </style>
</head>
<body>
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">Topic<span>Launch</span></a>
            <div class="nav-center">
                <a href="/creators/" class="nav-link active">Browse Creators</a>
                <a href="/creators/signup.php" class="nav-link">For Creators</a>
            </div>
            <div class="nav-buttons">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="/auth/logout.php" class="nav-login-btn">Log Out</a>
                <?php else: ?>
                    <a href="/auth/login.php" class="nav-login-btn">Log In</a>
                    <a href="/creators/signup.php" class="nav-getstarted-btn">Get Started</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <div class="hero-section">
        <h1 class="hero-title">Browse <em>Creators</em></h1>
        <div class="topic-filter-row" id="topicFilterRow">
            <?php
            $all_topics = ['Fitness','Health','Motivation','Therapy','Dating','Business','Money','Psychology','Career','Cosmetics','Family','Technology & AI'];
            foreach ($all_topics as $t): ?>
            <button class="topic-filter-btn" data-topic="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></button>
            <?php endforeach; ?>
        </div>
        <div class="active-filter-note" id="activeFilterNote"></div>
    </div>

    <!-- Creator strip -->
    <div class="strip-section">
        <div class="creator-strip">
            <div class="strip-avatar-wrap">
                <div class="select-creator-hint" id="selectCreatorHint">
                    <span>Click to Select Creator</span>
                    <svg class="hint-arrow" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M11 3V18" stroke="#E8305A" stroke-width="1.8" stroke-linecap="round"/><path d="M7 14L11 18L15 14" stroke="#E8305A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <button class="strip-avatar" id="stripAvatar" title="Choose a creator">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                </button>
            </div>
            <input type="text" class="strip-input-field" id="topicInput" placeholder="Type your topic idea…" maxlength="100">
            <button class="strip-send" id="stripSend" disabled>
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M14 8L2 2l2 6-2 6 12-6z" fill="#fff"/></svg>
            </button>
        </div>
        <div style="text-align:right; padding: 4px 30px 0; font-size:11px; color:#bbb;" id="topicInputCount"></div>

        <!-- Creator card (shown after selection) -->
        <div class="strip-creator-card" id="stripCreatorCard">
            <div class="strip-creator-card-avatar" id="stripCreatorCardAvatar"></div>
            <div class="strip-creator-card-info">
                <div class="strip-creator-card-name" id="stripCreatorCardName"></div>
                <div class="strip-creator-card-topics" id="stripCreatorCardTopics"></div>
            </div>
            <div class="strip-creator-card-price" id="stripCreatorCardPrice"></div>
            <span class="strip-creator-card-change" id="stripCreatorCardChange">Change</span>
        </div>

        <div class="strip-hint-row">
            <div class="strip-hint-step" id="stripStep1"><strong>1</strong> Click the avatar to pick a creator</div>
            <div class="strip-hint-step" id="stripStep2"><strong>2</strong> Type your topic idea</div>
            <div class="strip-hint-step" id="stripStep3"><strong>3</strong> Add details &amp; fund the video</div>
        </div>
    </div>

    <!-- Creator Picker Modal -->
    <div class="tl-overlay" id="creatorPickerOverlay">
        <div class="tl-modal" id="creatorPickerModal">
            <div class="tl-modal-header">
                <div class="tl-modal-title">Choose a creator</div>
                <button class="tl-modal-close" id="closeCreatorPicker">&times;</button>
            </div>
            <div class="tl-modal-search">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" id="creatorSearch" placeholder="Search creators…">
            </div>
            <div class="creator-picker-grid" id="creatorPickerGrid">
                <?php foreach ($creators as $c):
                    $c_topics = [];
                    if (!empty($c->video_topics)) {
                        $decoded = json_decode($c->video_topics, true);
                        if (is_array($decoded)) $c_topics = array_map('strtolower', $decoded);
                    }
                    $c_topics_json = htmlspecialchars(json_encode($c_topics), ENT_QUOTES);
                ?>
                <button class="creator-picker-item"
                    data-name="<?php echo htmlspecialchars($c->display_name); ?>"
                    data-price="<?php echo (int)($c->minimum_topic_price ?? 100); ?>"
                    data-image="<?php echo htmlspecialchars($c->profile_image ?? ''); ?>"
                    data-topics="<?php echo $c_topics_json; ?>">
                    <div class="picker-avatar">
                        <?php if ($c->profile_image): ?>
                            <img src="/uploads/creators/<?php echo htmlspecialchars($c->profile_image); ?>" alt="">
                        <?php else: ?>
                            <span><?php echo strtoupper(substr($c->display_name, 0, 1)); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="picker-name"><?php echo htmlspecialchars($c->display_name); ?></div>
                    <div class="picker-price">from $<?php echo (int)($c->minimum_topic_price ?? 100); ?></div>
                </button>
                <?php endforeach; ?>
                <?php if (empty($creators)): ?>
                <div style="grid-column:1/-1;text-align:center;color:#555;padding:40px 20px;font-size:13px;">No creators yet — <a href="/creators/signup.php" style="color:#E8305A;">be the first</a></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Topic Details Modal -->
    <div class="tl-overlay" id="topicModalOverlay">
        <div class="tl-modal tl-modal-sm" id="topicModal">
            <div class="tl-modal-header">
                <div>
                    <div class="tl-modal-title">Send your request</div>
                    <div class="tl-modal-sub" id="topicModalCreator"></div>
                </div>
                <button class="tl-modal-close" id="closeTopicModal">&times;</button>
            </div>
            <div class="tl-modal-body">
                <div class="tl-field">
                    <label class="tl-label">Your topic idea</label>
                    <div class="tl-topic-preview" id="topicPreview"></div>
                </div>
                <div class="tl-field">
                    <label class="tl-label">Additional details <span class="tl-optional">(optional)</span></label>
                    <textarea id="topicDesc" class="tl-textarea" placeholder="Any context or specifics for the creator…" rows="3" maxlength="350"></textarea>
                    <div class="tl-char-count" id="topicDescCount">0/350</div>
                </div>
                <div class="tl-field">
                    <label class="tl-label">Your offer amount</label>
                    <div class="tl-input-prefix-wrap">
                        <span class="tl-prefix">$</span>
                        <input type="number" id="topicAmount" class="tl-input" placeholder="0" min="1">
                    </div>
                    <div class="tl-hint" id="minPriceHint"></div>
                </div>
                <button class="tl-submit-btn" id="topicSubmit">Continue to payment →</button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const stripAvatar   = document.getElementById('stripAvatar');
        const topicInput    = document.getElementById('topicInput');
        const stripSend     = document.getElementById('stripSend');
        const selectHint    = document.getElementById('selectCreatorHint');

        const pickerOverlay = document.getElementById('creatorPickerOverlay');
        const closePickerBtn= document.getElementById('closeCreatorPicker');
        const creatorSearch = document.getElementById('creatorSearch');
        const pickerGrid    = document.getElementById('creatorPickerGrid');

        const topicOverlay  = document.getElementById('topicModalOverlay');
        const closeTopicBtn = document.getElementById('closeTopicModal');
        const topicPreview  = document.getElementById('topicPreview');
        const topicModalSub = document.getElementById('topicModalCreator');
        const topicAmount   = document.getElementById('topicAmount');
        const minPriceHint  = document.getElementById('minPriceHint');
        const topicDesc     = document.getElementById('topicDesc');
        const topicSubmit   = document.getElementById('topicSubmit');
        const activeFilterNote = document.getElementById('activeFilterNote');
        const topicDescCount  = document.getElementById('topicDescCount');
        const topicInputCount = document.getElementById('topicInputCount');
        const stripCreatorCard       = document.getElementById('stripCreatorCard');
        const stripCreatorCardAvatar = document.getElementById('stripCreatorCardAvatar');
        const stripCreatorCardName   = document.getElementById('stripCreatorCardName');
        const stripCreatorCardTopics = document.getElementById('stripCreatorCardTopics');
        const stripCreatorCardPrice  = document.getElementById('stripCreatorCardPrice');
        const stripCreatorCardChange = document.getElementById('stripCreatorCardChange');
        const stripStep1 = document.getElementById('stripStep1');

        let selectedCreator = null;
        let activeTopics    = new Set(); // selected filter chips

        /* ── Topic filter chips ── */
        document.querySelectorAll('.topic-filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const t = btn.dataset.topic.toLowerCase();
                if (activeTopics.has(t)) {
                    activeTopics.delete(t);
                    btn.classList.remove('active');
                } else {
                    activeTopics.add(t);
                    btn.classList.add('active');
                }
                updateFilterNote();
            });
        });

        function updateFilterNote() {
            if (activeTopics.size === 0) {
                activeFilterNote.textContent = '';
            } else {
                activeFilterNote.textContent = `Showing creators for: ${[...activeTopics].join(', ')} — click the avatar to pick one`;
            }
        }

        /* Open / close creator picker */
        stripAvatar.addEventListener('click', () => {
            pickerOverlay.classList.add('open');
            applyPickerFilter('');
            creatorSearch.value = '';
            creatorSearch.focus();
        });
        closePickerBtn.addEventListener('click', closePicker);
        pickerOverlay.addEventListener('click', e => { if (e.target === pickerOverlay) closePicker(); });
        function closePicker() { pickerOverlay.classList.remove('open'); creatorSearch.value = ''; applyPickerFilter(''); }

        /* Creator search inside picker */
        creatorSearch.addEventListener('input', () => applyPickerFilter(creatorSearch.value.trim().toLowerCase()));

        function applyPickerFilter(q) {
            pickerGrid.querySelectorAll('.creator-picker-item').forEach(btn => {
                const name   = btn.dataset.name.toLowerCase();
                let   topics = [];
                try { topics = JSON.parse(btn.dataset.topics || '[]'); } catch(e) {}

                const matchesSearch = !q || name.includes(q);
                const matchesTopic  = activeTopics.size === 0 ||
                    [...activeTopics].some(t => topics.includes(t));

                btn.classList.toggle('hidden', !matchesSearch || !matchesTopic);
            });
        }

        /* Select a creator */
        pickerGrid.addEventListener('click', e => {
            const item = e.target.closest('.creator-picker-item');
            if (!item) return;
            let topics = [];
            try { topics = JSON.parse(item.dataset.topics || '[]'); } catch(e) {}
            selectedCreator = {
                name:   item.dataset.name,
                price:  parseInt(item.dataset.price, 10) || 0,
                image:  item.dataset.image,
                topics: topics
            };

            if (selectedCreator.image) {
                stripAvatar.innerHTML = `<img src="/uploads/creators/${selectedCreator.image}" alt="">`;
            } else {
                const initial = selectedCreator.name.charAt(0).toUpperCase();
                stripAvatar.innerHTML = `<span class="strip-avatar-initials">${initial}</span>`;
            }

            selectHint.classList.add('hidden');
            topicInput.placeholder = `Commission a video from ${selectedCreator.name}…`;
            topicInput.focus();
            stripSend.disabled = !topicInput.value.trim();

            /* Populate + show creator card */
            if (selectedCreator.image) {
                stripCreatorCardAvatar.innerHTML = `<img src="/uploads/creators/${selectedCreator.image}" alt="">`;
            } else {
                stripCreatorCardAvatar.innerHTML = selectedCreator.name.charAt(0).toUpperCase();
            }
            stripCreatorCardName.textContent  = selectedCreator.name;
            stripCreatorCardTopics.textContent = topics.length ? topics.map(t => t.charAt(0).toUpperCase() + t.slice(1)).join(' · ') : '';
            stripCreatorCardPrice.textContent  = selectedCreator.price ? `from $${selectedCreator.price}` : 'Free';
            stripCreatorCard.classList.add('visible');

            /* Hide step 1 — creator is chosen */
            stripStep1.style.display = 'none';

            pickerGrid.querySelectorAll('.creator-picker-item').forEach(b => b.classList.remove('selected'));
            item.classList.add('selected');
            closePicker();
        });

        /* "Change" link reopens picker and resets state */
        stripCreatorCardChange.addEventListener('click', () => {
            stripCreatorCard.classList.remove('visible');
            stripStep1.style.display = '';
            selectedCreator = null;
            stripAvatar.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>`;
            selectHint.classList.remove('hidden');
            topicInput.placeholder = 'Type your topic idea…';
            stripSend.disabled = true;
            pickerOverlay.classList.add('open');
            applyPickerFilter('');
            creatorSearch.value = '';
            creatorSearch.focus();
        });

        /* Enable send only when input has text + live counter */
        topicInput.addEventListener('input', () => {
            stripSend.disabled = !topicInput.value.trim() || !selectedCreator;
            const len = topicInput.value.length;
            topicInputCount.textContent = len > 0 ? `${len}/100` : '';
        });

        topicDesc.addEventListener('input', () => {
            topicDescCount.textContent = `${topicDesc.value.length}/350`;
        });

        /* Open topic details modal */
        stripSend.addEventListener('click', () => {
            if (!selectedCreator || !topicInput.value.trim()) return;
            topicPreview.textContent = topicInput.value.trim();
            topicModalSub.textContent = `To: ${selectedCreator.name}`;
            if (selectedCreator.price > 0) {
                minPriceHint.textContent = `Minimum price: $${selectedCreator.price}`;
                topicAmount.min = selectedCreator.price;
                topicAmount.placeholder = selectedCreator.price;
            } else {
                minPriceHint.textContent = '';
            }
            topicAmount.value = '';
            topicDesc.value = '';
            topicDescCount.textContent = '0/350';
            topicOverlay.classList.add('open');
            topicDesc.focus();
        });

        closeTopicBtn.addEventListener('click', closeTopic);
        topicOverlay.addEventListener('click', e => { if (e.target === topicOverlay) closeTopic(); });
        function closeTopic() { topicOverlay.classList.remove('open'); }

        /* Submit: redirect to creator profile with params */
        topicSubmit.addEventListener('click', () => {
            const amount = parseInt(topicAmount.value, 10);
            const topic  = topicInput.value.trim();
            const desc   = topicDesc.value.trim();

            if (!amount || amount < 1) {
                topicAmount.style.borderColor = '#E8305A';
                topicAmount.focus();
                setTimeout(() => topicAmount.style.borderColor = '', 1500);
                return;
            }
            if (selectedCreator.price > 0 && amount < selectedCreator.price) {
                minPriceHint.style.color = '#E8305A';
                topicAmount.focus();
                setTimeout(() => minPriceHint.style.color = '', 1500);
                return;
            }

            const params = new URLSearchParams({ topic, amount });
            if (desc) params.set('desc', desc);
            window.location.href = `/${encodeURIComponent(selectedCreator.name)}?${params.toString()}`;
        });
    })();
    </script>
</body>
</html>

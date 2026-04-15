<?php
// index.php - FOR THE NEXT GENERATION
session_start();

// Try to load database config
$db_available = false;
if (file_exists('config/database.php')) {
    require_once 'config/database.php';
    $db_available = true;
} elseif (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
    $db_available = true;
}

// Redirect logged-in creators to dashboard
if (isset($_SESSION['user_id']) && $db_available) {
    try {
        $db = new Database();
        $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
        $db->bind(':user_id', $_SESSION['user_id']);
        $is_creator = $db->single();
        
        if ($is_creator) {
            header('Location: creators/dashboard.php');
            exit;
        }
    } catch (Exception $e) {
        error_log("Database error in index.php: " . $e->getMessage());
    }
}

// Fetch all active creators for display
$creators = [];
$total_creators = 0;
$total_creators_in_db = 0;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($db_available) {
    try {
        $db = new Database();
        
        // Get total count first
        $db->query('SELECT COUNT(*) as total FROM creators WHERE is_active = 1');
        $count_result = $db->single();
        $total_creators_in_db = $count_result->total ?? 0;
        
        if ($search_query) {
            $search_no_spaces = str_replace(' ', '', $search_query);
            
            $db->query('
                SELECT * FROM creators 
                WHERE is_active = 1 
                AND (
                    REPLACE(LOWER(display_name), " ", "") LIKE :search 
                    OR REPLACE(LOWER(username), " ", "") LIKE :search
                    OR LOWER(display_name) LIKE :search_with_spaces
                    OR LOWER(username) LIKE :search_with_spaces
                )
                ORDER BY display_name ASC
            ');
            $db->bind(':search', '%' . strtolower($search_no_spaces) . '%');
            $db->bind(':search_with_spaces', '%' . strtolower($search_query) . '%');
        } else {
            $db->query('SELECT * FROM creators WHERE is_active = 1 ORDER BY display_name ASC LIMIT 24');
        }
        
        $creators = $db->resultSet();
        $total_creators = count($creators);
    } catch (Exception $e) {
        error_log("Database error fetching creators: " . $e->getMessage());
        $creators = [];
    }
}

// Fetch active topics for all creators
$creator_topics_map = [];
if ($db_available) {
    try {
        $db2 = new Database();
        $db2->query("SELECT id, creator_id, title, description, funding_threshold, current_funding FROM topics WHERE status = 'active' ORDER BY created_at DESC");
        $all_active_topics = $db2->resultSet();
        foreach ($all_active_topics as $t) {
            $creator_topics_map[$t->creator_id][] = [
                'id'                => $t->id,
                'title'             => $t->title,
                'description'       => $t->description,
                'funding_threshold' => (float)$t->funding_threshold,
                'current_funding'   => (float)$t->current_funding,
            ];
        }
    } catch (Exception $e) {}
}

// Fetch funded (waiting for upload) topics for all creators
$creator_funded_map = [];
if ($db_available) {
    try {
        $db3 = new Database();
        $db3->query("SELECT id, creator_id, title, current_funding, funding_threshold, status, hold_reason FROM topics WHERE status IN ('funded','on_hold','queued') ORDER BY COALESCE(funded_at, held_at) DESC NULLS LAST");
        $all_funded_topics = $db3->resultSet();
        foreach ($all_funded_topics as $t) {
            $creator_funded_map[$t->creator_id][] = [
                'id'                => $t->id,
                'title'             => $t->title,
                'current_funding'   => (float)$t->current_funding,
                'funding_threshold' => (float)$t->funding_threshold,
                'status'            => $t->status,
                'hold_reason'       => $t->hold_reason ?? '',
            ];
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>TopicLaunch - Get Paid to Create Videos Requested by Fans</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <meta name="description" content="Set your price. Fans request custom content and pay upfront. You create it within 48 hours and keep 90%.">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://topiclaunch.com/">
    <meta property="og:title" content="TopicLaunch - Get Paid to Create Videos Requested by Fans">
    <meta property="og:description" content="Set your price. Fans request custom content and pay upfront. You create it and keep 90%.">
    <meta property="og:image" content="https://topiclaunch.com/og-image.png">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --tl-pink: #E8305A;
            --tl-pink-light: #F7C0D0;
            --tl-pink-dark: #B01F3F;
            --tl-black: #FAF8F6;
            --tl-off: #F0F0F0;
            --tl-card: #FFFFFF;
            --tl-border: #E5E5E5;
            --tl-muted: #888888;
            --tl-dimmed: #555555;
            --white: #ffffff;
            --text-dark: #111010;
            --hot-pink: #E8305A;
            --deep-pink: #B01F3F;
            --gray-light: #E5E5E5;
            --gray-med: #888888;
            --gray-dark: #1A1A1A;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--tl-black);
            color: var(--text-dark);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }

        /* ── Navigation ── */
        .topiclaunch-nav {
            background: var(--white);
            border-bottom: 1px solid var(--tl-border);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 30px;
        }

        .nav-logo {
            font-size: 20px;
            font-weight: 500;
            color: var(--text-dark);
            text-decoration: none;
            letter-spacing: -0.3px;
        }
        .nav-logo span { color: var(--tl-pink); }

        .nav-center { display: flex; gap: 24px; align-items: center; }

        .nav-link {
            color: var(--tl-muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: color 0.2s;
        }
        .nav-link:hover { color: var(--text-dark); }

        .nav-buttons { display: flex; gap: 12px; align-items: center; }

        .nav-login-btn {
            color: var(--tl-muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: color 0.2s;
        }
        .nav-login-btn:hover { color: var(--text-dark); }

        .nav-getstarted-btn {
            background: var(--tl-pink);
            color: var(--white);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 8px 18px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .nav-getstarted-btn:hover { background: var(--tl-pink-dark); }

        /* ── Hero ── */
        .hero {
            background: var(--tl-black);
            padding: 72px 30px 56px;
            text-align: center;
            position: relative;
            overflow: hidden;
            border-bottom: 1px solid var(--tl-border);
        }

        .hero-container {
            max-width: 680px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
            animation: fadeInUp 0.7s ease-out 0.1s both;
        }

        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 11px;
            font-weight: 500;
            color: var(--tl-pink);
            background: rgba(232,48,90,0.12);
            padding: 5px 13px;
            border-radius: 20px;
            margin-bottom: 22px;
            letter-spacing: 0.5px;
        }
        .hero-eyebrow-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--tl-pink);
            display: inline-block;
            flex-shrink: 0;
        }

        .hero h1 {
            font-size: 40px;
            font-weight: 600;
            color: var(--text-dark);
            line-height: 1.15;
            letter-spacing: -0.8px;
            margin: 0 0 18px;
        }
        .hero h1 .pink { color: var(--tl-pink); }

        .hero-subhead {
            font-size: 15px;
            color: var(--tl-muted);
            max-width: 420px;
            margin: 0 auto 34px;
            line-height: 1.65;
        }

        .hero-cta-row {
            display: flex;
            justify-content: center;
            gap: 12px;
            align-items: center;
            margin-bottom: 0;
        }

        .hero-cta {
            background: var(--tl-pink);
            color: var(--white);
            padding: 13px 26px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: background 0.2s;
            display: inline-block;
        }
        .hero-cta:hover { background: var(--tl-pink-dark); }

        .hero-cta-ghost {
            background: #ffffff;
            color: var(--text-dark);
            padding: 12px 22px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            border: 1.5px solid #CCCCCC;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            transition: border-color 0.2s, box-shadow 0.2s;
            display: inline-block;
        }
        .hero-cta-ghost:hover { border-color: var(--tl-pink); box-shadow: 0 2px 8px rgba(232,48,90,0.12); color: var(--tl-pink); }

        /* stat row */
        .hero-stat-row {
            display: flex;
            justify-content: center;
            align-items: stretch;
            margin-top: 50px;
            border-top: 1px solid var(--tl-border);
            padding-top: 32px;
        }
        .hero-stat { flex: 1; text-align: center; max-width: 160px; display: flex; flex-direction: column; align-items: center; }
        .hero-stat-step { width: 24px; height: 24px; border-radius: 50%; background: var(--tl-pink); color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; margin-bottom: 8px; }
        .hero-stat-n { font-size: 15px; font-weight: 600; color: var(--text-dark); display: flex; align-items: center; justify-content: center; text-align: center; line-height: 1.3; white-space: nowrap; }
        .hero-stat-divider { color: var(--tl-muted); font-size: 18px; display: flex; align-items: center; align-self: center; margin: 0 12px; padding-bottom: 4px; }

        /* ── Why section (cards) ── */
        .why-section {
            background: var(--tl-off);
            padding: 36px 30px 40px;
            border-top: 1px solid var(--tl-border);
        }
        .why-container { max-width: 900px; margin: 0 auto; }
        .why-label {
            font-size: 11px;
            color: var(--tl-muted);
            letter-spacing: 0.8px;
            font-weight: 500;
            margin-bottom: 18px;
            text-transform: uppercase;
        }
        .why-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        .why-card {
            background: var(--white);
            border-radius: 12px;
            padding: 18px 16px;
            border: 1px solid var(--tl-border);
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .why-card-icon {
            width: 34px; height: 34px;
            border-radius: 8px;
            background: rgba(232,48,90,0.1);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 12px;
        }
        .why-card-icon svg { width: 16px; height: 16px; }
        .why-card-title { font-size: 13px; font-weight: 500; color: var(--text-dark); margin-bottom: 5px; }
        .why-card-desc { font-size: 11px; color: var(--tl-muted); line-height: 1.55; }

        /* ── Creator input strip ── */
        .creator-strip {
            background: var(--tl-black);
            padding: 20px 0;
            display: flex;
            align-items: center;
            gap: 14px;
            width: 100%;
            max-width: none;
            margin: 0;
        }
        .creator-strip .strip-avatar-wrap { margin-left: 30px; }
        .creator-strip .strip-input-field { max-width: none; }
        .creator-strip .strip-send { margin-right: 30px; }

        /* ── Creators browse section ── */
        .creators-section {
            background: var(--tl-black);
            padding: 56px 30px 80px;
        }
        .creators-container { max-width: 1400px; margin: 0 auto; }

        .section-header { margin-bottom: 36px; text-align: center; }
        .section-eyebrow {
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: var(--tl-muted);
            margin-bottom: 10px;
        }
        .section-title {
            font-size: 26px;
            font-weight: 600;
            color: var(--text-dark);
            letter-spacing: -0.4px;
        }
        .section-subtitle {
            font-size: 14px;
            color: var(--tl-muted);
            margin-top: 8px;
        }

        /* Search */
        .search-section { margin: 0 auto 36px; max-width: 700px; }
        .search-bar {
            background: var(--white);
            border-radius: 10px;
            padding: 6px;
            border: 1px solid var(--tl-border);
            margin-bottom: 14px;
            transition: border-color 0.2s;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .search-bar:focus-within { border-color: rgba(232,48,90,0.4); }
        .search-input-wrapper { position: relative; }
        .search-input {
            width: 100%;
            padding: 11px 18px 11px 46px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 400;
            outline: none;
            background: transparent;
            color: var(--text-dark);
        }
        .search-input::placeholder { color: #bbb; }
        .search-icon { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #bbb; width: 16px; height: 16px; }
        .topic-filters { display: flex; flex-wrap: wrap; gap: 7px; justify-content: center; }
        .topic-filter-btn {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid var(--tl-border);
            background: var(--white);
            color: var(--tl-muted);
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .topic-filter-btn:hover { border-color: var(--tl-pink); color: var(--tl-pink); }
        .topic-filter-btn.active { background: var(--tl-pink); border-color: var(--tl-pink); color: var(--white); }

        /* Creator Cards */
        .creators-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 14px;
        }

        .creator-card {
            background: var(--tl-card);
            border-radius: 14px;
            border: 1px solid var(--tl-border);
            padding: 22px;
            transition: all 0.22s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
        }
        .creator-card:hover {
            transform: translateY(-3px);
            border-color: rgba(232,48,90,0.35);
            box-shadow: 0 8px 24px rgba(232,48,90,0.08);
        }

        .creator-card-top {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 12px;
        }

        .creator-card-image {
            width: 56px; height: 56px;
            flex-shrink: 0;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--tl-pink), var(--tl-pink-dark));
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .creator-card-image img { width: 100%; height: 100%; object-fit: cover; }

        .creator-initial { font-size: 22px; color: var(--white); font-weight: 600; }

        .creator-card-identity { flex: 1; min-width: 0; }

        .creator-name {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .creator-handle { font-size: 12px; color: var(--tl-muted); font-weight: 400; }

        .creator-bio {
            font-size: 12px;
            line-height: 1.55;
            color: var(--tl-muted);
            margin-bottom: 12px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .creator-topics { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 16px; }

        .creator-topic-tag {
            font-size: 11px;
            font-weight: 500;
            padding: 4px 11px;
            border-radius: 6px;
            background: transparent;
            color: var(--tl-muted);
            border: 1px solid var(--tl-border);
        }

        .creator-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 14px;
            border-top: 1px solid var(--tl-border);
            margin-top: auto;
        }

        .creator-price { font-size: 18px; color: var(--text-dark); font-weight: 600; display: inline; }

        .price-label {
            font-size: 11px;
            color: var(--tl-muted);
            font-weight: 500;
            margin-left: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .fund-btn {
            background: var(--tl-pink);
            color: var(--white);
            border: none;
            padding: 9px 18px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            border-radius: 8px;
        }
        .fund-btn:hover { background: var(--tl-pink-dark); }

        /* ── Footer ── */
        .footer {
            background: var(--tl-off);
            border-top: 1px solid var(--tl-border);
            color: var(--tl-muted);
            text-align: center;
            padding: 36px 30px;
            font-size: 13px;
        }
        .footer a {
            color: var(--tl-pink);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        .footer a:hover { color: var(--text-dark); }
        .footer-links {
            margin-top: 16px;
            display: flex;
            justify-content: center;
            gap: 28px;
        }

        /* ── Strip updated styles ── */
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
        .hint-arrow {
            display: block;
        }
        .select-creator-hint.hidden { display: none; }

        @media (max-width: 768px) {
            .select-creator-hint {
                bottom: calc(100% + 4px);
            }
            .select-creator-hint span {
                font-size: 10px;
            }
            .hint-arrow {
                width: 18px;
                height: 18px;
            }
        }

        .strip-avatar {
            width: 34px; height: 34px;
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
        .strip-avatar-initials { font-size: 13px; font-weight: 600; color: var(--white); }

        .strip-input-field {
            flex: 1;
            background: var(--white);
            border-radius: 8px;
            padding: 9px 14px;
            border: 1px solid var(--tl-border);
            font-size: 12px;
            color: var(--text-dark);
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s, opacity 0.2s;
        }
        .strip-input-field::placeholder { color: #bbb; }
        .strip-input-field:focus { border-color: rgba(232,48,90,0.4); }
        .strip-input-field:disabled { opacity: 0.5; cursor: not-allowed; }

        .strip-send {
            width: 34px; height: 34px;
            background: var(--tl-pink);
            border-radius: 8px;
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
        .strip-creator-card-bio { font-size: 11px; color: #777; margin-top: 1px; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .strip-creator-card-price { background: #FFF0F3; color: var(--tl-pink); font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px; flex-shrink: 0; }
        .strip-creator-card-x { background: none; border: none; color: #ccc; font-size: 18px; line-height: 1; cursor: pointer; padding: 0 0 0 4px; flex-shrink: 0; transition: color 0.15s; }
        .strip-creator-card-x:hover { color: var(--tl-pink); }

        /* Active topics section */
        .strip-active-topics { display: none; margin: 10px auto 0; max-width: calc(100% - 60px); }
        .strip-active-topics.visible { display: block; }
        .strip-topics-label { font-size: 10px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; color: var(--tl-muted); margin-bottom: 8px; }
        .strip-topic-item { background: var(--white); border: 1px solid var(--tl-border); border-radius: 10px; padding: 10px 14px; margin-bottom: 6px; cursor: pointer; transition: border-color 0.15s; }
        .strip-topic-item:last-child { margin-bottom: 0; }
        .strip-topic-item:hover { border-color: var(--tl-pink); }
        .strip-topic-item-title { font-size: 12px; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; }
        .strip-topic-progress-bar { background: #F0F0F0; border-radius: 4px; height: 5px; overflow: hidden; margin-bottom: 4px; }
        .strip-topic-progress-fill { height: 100%; background: var(--tl-pink); border-radius: 4px; transition: width 0.3s; }
        .strip-topic-meta { display: flex; justify-content: space-between; align-items: center; font-size: 10px; color: var(--tl-muted); margin-top: 6px; }
        .strip-topic-pct { color: var(--tl-pink); font-weight: 600; }
        .strip-topic-fund-btn { font-size: 10px; font-weight: 600; color: var(--white); background: var(--tl-pink); border: none; border-radius: 20px; padding: 3px 10px; cursor: pointer; pointer-events: none; }

        /* Funded / waiting for upload section */
        .strip-funded-topics { display: none; margin: 10px auto 0; max-width: calc(100% - 60px); }
        .strip-funded-topics.visible { display: block; }
        .strip-funded-label { font-size: 10px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; color: #B45309; margin-bottom: 8px; }
        .strip-funded-item { background: #FFFBF0; border: 1px solid #FDE68A; border-radius: 10px; padding: 10px 14px; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
        .strip-funded-item:last-child { margin-bottom: 0; }
        .strip-funded-dot { width: 8px; height: 8px; border-radius: 50%; background: #F59E0B; flex-shrink: 0; }
        .strip-funded-title { font-size: 12px; font-weight: 600; color: var(--text-dark); flex: 1; }
        .strip-funded-badge { font-size: 10px; font-weight: 600; color: #B45309; background: #FEF3C7; padding: 2px 8px; border-radius: 20px; flex-shrink: 0; }
        .strip-onhold-badge { color: #6B7280; background: #F3F4F6; }
        .strip-queued-badge { color: #1D4ED8; background: #DBEAFE; }

        /* ── Modals ── */
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
            to { opacity: 1; transform: none; }
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
            cursor: pointer; line-height: 1; padding: 0 2px;
            transition: color 0.15s;
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

        .picker-avatar {
            width: 52px; height: 52px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        .picker-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .picker-avatar span { font-size: 20px; font-weight: 600; color: var(--white); }
        .picker-name { font-size: 12px; font-weight: 500; color: var(--text-dark); line-height: 1.3; }
        .picker-price { font-size: 10px; color: var(--tl-muted); }
        .picker-chips-row { display: flex; flex-wrap: wrap; gap: 6px; padding: 10px 16px; border-bottom: 1px solid var(--tl-border); flex-shrink: 0; }
        .picker-chip { font-size: 11px; padding: 4px 11px; border-radius: 20px; border: 1px solid #E5E5E5; background: #fff; color: #555; cursor: pointer; transition: all 0.15s; font-family: inherit; }
        .picker-chip:hover { border-color: var(--tl-pink); color: var(--tl-pink); }
        .picker-chip.active { background: var(--tl-pink); border-color: var(--tl-pink); color: #fff; }

        .creator-picker-item.hidden { display: none; }

        /* topic details modal body */
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

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .creators-grid { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
            .why-cards { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .nav-center { display: none; }
            .hero { padding: 56px 20px 48px; }
            .hero h1 { font-size: 30px; }
            .hero-stat-row { gap: 0; }
            .creators-grid { grid-template-columns: 1fr; }
            .why-cards { grid-template-columns: 1fr; }
            .creator-strip { padding: 16px 0; }
            .creator-strip .strip-avatar-wrap { margin-left: 20px; }
            .creator-strip .strip-input-field { margin-right: 20px; }
            .creator-strip .strip-send { margin-right: 20px; }
        }
        @media (max-width: 480px) {
            .hero-cta-row { flex-direction: column; align-items: stretch; }
            .hero-cta, .hero-cta-ghost { text-align: center; }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">Topic<span>Launch</span></a>
            <div class="nav-center">
                <a href="/creators/" class="nav-link">Browse Creators</a>
                <a href="/creators/signup.php" class="nav-link">For Creators</a>
            </div>
            <div class="nav-buttons">
                <a href="/auth/login.php" class="nav-login-btn">Log In</a>
                <a href="/creators/signup.php" class="nav-getstarted-btn">Get Started</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero">
        <div class="hero-container">
            <div class="hero-eyebrow">
                <span class="hero-eyebrow-dot"></span>
                CREATORS KEEP 90%
            </div>
            <h1 style="font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; font-weight: 500;">
                Your fans <span class="pink">commission</span><br>your best work.
            </h1>
            <p class="hero-subhead">
                TopicLaunch connects creators with fans who want custom content — on their terms, at your price.
            </p>
            <div class="hero-cta-row">
                <a href="creators/signup.php" class="hero-cta">Launch your page</a>
                <a href="/creators/index.php" class="hero-cta-ghost">Browse Creators</a>
            </div>

            <div class="hero-stat-row">
                <div class="hero-stat">
                    <div class="hero-stat-step">1</div>
                    <div class="hero-stat-n">Fans Fund Topic</div>
                </div>
                <div class="hero-stat-divider">→</div>
                <div class="hero-stat">
                    <div class="hero-stat-step">2</div>
                    <div class="hero-stat-n">You Deliver</div>
                </div>
                <div class="hero-stat-divider">→</div>
                <div class="hero-stat">
                    <div class="hero-stat-step">3</div>
                    <div class="hero-stat-n">Receive $</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Creator strip -->
    <div style="background:#F0F0F0; border-top:1px solid #E5E5E5; border-bottom:1px solid #E5E5E5; padding-top:18px; overflow:visible;">
        <div class="creator-strip">
            <div class="strip-avatar-wrap">
                <div class="select-creator-hint" id="selectCreatorHint">
                    <span>Click to Select Creator</span>
                    <svg class="hint-arrow" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M11 3V18" stroke="#E8305A" stroke-width="1.8" stroke-linecap="round"/><path d="M7 14L11 18L15 14" stroke="#E8305A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
            <button class="strip-avatar" id="stripAvatar" title="Choose a creator">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            </button>
            </div>
            <input type="text" class="strip-input-field" id="topicInput" placeholder="Type your topic idea…" maxlength="100">
            <button class="strip-send" id="stripSend" disabled>
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M14 8L2 2l2 6-2 6 12-6z" fill="#fff"/></svg>
            </button>
        </div>
        <div style="text-align:right; padding: 4px 30px 0; font-size:11px; color:#bbb;" id="topicInputCount"></div>

        <!-- Creator card (shown after selection) -->
        <div class="strip-creator-card" id="stripCreatorCard">
            <div class="strip-creator-card-avatar" id="stripCreatorCardAvatar"></div>
            <div class="strip-creator-card-info">
                <div class="strip-creator-card-name" id="stripCreatorCardName"></div>
                <div class="strip-creator-card-bio" id="stripCreatorCardBio"></div>
            </div>
            <div class="strip-creator-card-price" id="stripCreatorCardPrice"></div>
            <button class="strip-creator-card-x" id="stripCreatorCardX" title="Remove creator">&times;</button>
        </div>

        <!-- Active topics for selected creator -->
        <div class="strip-active-topics" id="stripActiveTopics">
            <div class="strip-topics-label">Active Topics</div>
            <div id="stripTopicsList"></div>
        </div>

        <!-- Funded (waiting for upload) topics -->
        <div class="strip-funded-topics" id="stripFundedTopics">
            <div class="strip-funded-label">Waiting for Upload</div>
            <div id="stripFundedList"></div>
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
            <?php $all_topics = ['Fitness','Health','Motivation','Therapy','Dating','Business','Money','Psychology','Career','Cosmetics','Family','Technology & AI']; ?>
            <div class="picker-chips-row" id="pickerChipsRow">
                <?php foreach ($all_topics as $t): ?>
                <button class="picker-chip" data-topic="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></button>
                <?php endforeach; ?>
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
                    data-id="<?php echo (int)$c->id; ?>"
                    data-name="<?php echo htmlspecialchars($c->display_name); ?>"
                    data-price="<?php echo (int)($c->minimum_topic_price ?? 100); ?>"
                    data-image="<?php echo htmlspecialchars($c->profile_image ?? ''); ?>"
                    data-topics="<?php echo $c_topics_json; ?>"
                    data-bio="<?php echo htmlspecialchars($c->bio ?? ''); ?>">
                    <div class="picker-avatar" style="background:linear-gradient(135deg,#E8305A,#B01F3F)">
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
                    <div class="tl-modal-title" id="topicModalTitle">Send your request</div>
                    <div class="tl-modal-sub" id="topicModalCreator"></div>
                </div>
                <button class="tl-modal-close" id="closeTopicModal">&times;</button>
            </div>
            <div class="tl-modal-body">
                <div class="tl-field">
                    <label class="tl-label">Your topic idea</label>
                    <div class="tl-topic-preview" id="topicPreview"></div>
                </div>
                <div class="tl-field" id="topicDescField">
                    <label class="tl-label">Additional details <span class="tl-optional">(optional)</span></label>
                    <textarea id="topicDesc" class="tl-textarea" placeholder="Any context or specifics for the creator…" rows="3" maxlength="350"></textarea>
                    <div class="tl-char-count" id="topicDescCount">0/350</div>
                </div>
                <div class="tl-field">
                    <label class="tl-label" id="topicAmountLabel">Your offer amount</label>
                    <div class="tl-input-prefix-wrap">
                        <span class="tl-prefix">$</span>
                        <input type="number" id="topicAmount" class="tl-input" placeholder="0" min="1">
                    </div>
                    <div id="topicFundingInfo" style="display:none; margin-top:6px; background:#FFF0F3; border-radius:8px; padding:8px 12px; font-size:12px; color:#555; line-height:1.5;"></div>
                    <div class="tl-hint" id="minPriceHint"></div>
                </div>
                <button class="tl-submit-btn" id="topicSubmit">Continue to payment →</button>
            </div>
        </div>
    </div>


    <!-- Footer -->
    <footer class="footer">
        <p>&copy; 2025 TopicLaunch. Built for creators who know their worth.</p>
        <div class="footer-links">
            <a href="/terms.php">Terms</a>
            <a href="mailto:report@topiclaunch.com">Report</a>
            <a href="/creators/signup.php">Start Earning</a>
        </div>
    </footer>

    <script>
    const CREATOR_TOPICS = <?php echo json_encode($creator_topics_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const CREATOR_FUNDED = <?php echo json_encode($creator_funded_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    </script>
    <script>
    (function () {
        const stripAvatar        = document.getElementById('stripAvatar');
        const topicInput         = document.getElementById('topicInput');
        const stripSend          = document.getElementById('stripSend');
        const selectHint         = document.getElementById('selectCreatorHint');
        const stripActiveTopics  = document.getElementById('stripActiveTopics');
        const stripTopicsList    = document.getElementById('stripTopicsList');
        const stripFundedTopics  = document.getElementById('stripFundedTopics');
        const stripFundedList    = document.getElementById('stripFundedList');

        const pickerOverlay  = document.getElementById('creatorPickerOverlay');
        const closePickerBtn = document.getElementById('closeCreatorPicker');
        const creatorSearch  = document.getElementById('creatorSearch');
        const pickerGrid     = document.getElementById('creatorPickerGrid');

        const topicOverlay   = document.getElementById('topicModalOverlay');
        const closeTopicBtn  = document.getElementById('closeTopicModal');
        const topicPreview   = document.getElementById('topicPreview');
        const topicModalSub  = document.getElementById('topicModalCreator');
        const topicAmount    = document.getElementById('topicAmount');
        const minPriceHint   = document.getElementById('minPriceHint');
        const topicDesc      = document.getElementById('topicDesc');
        const topicSubmit    = document.getElementById('topicSubmit');
        const topicDescCount   = document.getElementById('topicDescCount');
        const topicInputCount  = document.getElementById('topicInputCount');

        const stripCreatorCard        = document.getElementById('stripCreatorCard');
        const stripCreatorCardAvatar  = document.getElementById('stripCreatorCardAvatar');
        const stripCreatorCardName    = document.getElementById('stripCreatorCardName');
        const stripCreatorCardBio     = document.getElementById('stripCreatorCardBio');
        const stripCreatorCardPrice   = document.getElementById('stripCreatorCardPrice');
        const stripCreatorCardX       = document.getElementById('stripCreatorCardX');

        const topicDescField   = document.getElementById('topicDescField');
        const topicFundingInfo = document.getElementById('topicFundingInfo');
        const topicModalTitle  = document.getElementById('topicModalTitle');
        const topicAmountLabel = document.getElementById('topicAmountLabel');

        let selectedCreator = null;
        let activeTopic     = null;
        let pickerTopics    = new Set();

        /* ── Picker topic chips ── */
        document.querySelectorAll('.picker-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                const t = chip.dataset.topic.toLowerCase();
                if (pickerTopics.has(t)) {
                    pickerTopics.delete(t);
                    chip.classList.remove('active');
                } else {
                    pickerTopics.add(t);
                    chip.classList.add('active');
                }
                applyPickerFilter(creatorSearch.value.trim().toLowerCase());
            });
        });

        /* ── Open / close creator picker ── */
        stripAvatar.addEventListener('click', () => {
            pickerOverlay.classList.add('open');
            applyPickerFilter('');
            creatorSearch.value = '';
            creatorSearch.focus();
        });
        closePickerBtn.addEventListener('click', closePicker);
        pickerOverlay.addEventListener('click', e => { if (e.target === pickerOverlay) closePicker(); });
        function closePicker() {
            pickerOverlay.classList.remove('open');
            creatorSearch.value = '';
            pickerTopics.clear();
            document.querySelectorAll('.picker-chip').forEach(c => c.classList.remove('active'));
            applyPickerFilter('');
        }

        /* ── Creator search inside picker ── */
        creatorSearch.addEventListener('input', () => applyPickerFilter(creatorSearch.value.trim().toLowerCase()));
        function applyPickerFilter(q) {
            pickerGrid.querySelectorAll('.creator-picker-item').forEach(btn => {
                const name = btn.dataset.name.toLowerCase();
                let topics = [];
                try { topics = JSON.parse(btn.dataset.topics || '[]'); } catch(e) {}
                const matchesSearch = !q || name.includes(q);
                const matchesPicker = pickerTopics.size === 0 || [...pickerTopics].some(t => topics.includes(t));
                btn.classList.toggle('hidden', !matchesSearch || !matchesPicker);
            });
        }

        /* ── Render active topics ── */
        function renderCreatorTopics(creatorId) {
            const topics = CREATOR_TOPICS[creatorId] || [];
            if (topics.length === 0) { stripActiveTopics.classList.remove('visible'); return; }
            stripTopicsList.innerHTML = topics.map(t => {
                const pct      = Math.min(100, Math.round((t.current_funding / t.funding_threshold) * 100));
                const descAttr = t.description ? t.description.replace(/"/g, '&quot;') : '';
                const titleAttr = t.title.replace(/"/g, '&quot;');
                return `<div class="strip-topic-item" data-topic-id="${t.id}" data-topic-title="${titleAttr}" data-topic-desc="${descAttr}" data-current-funding="${t.current_funding}" data-funding-threshold="${t.funding_threshold}">
                    <div class="strip-topic-item-title">${t.title}</div>
                    <div class="strip-topic-progress-bar"><div class="strip-topic-progress-fill" style="width:${pct}%"></div></div>
                    <div class="strip-topic-meta">
                        <span>$${Math.round(t.current_funding)} raised of $${Math.round(t.funding_threshold)} &bull; <span class="strip-topic-pct">${pct}%</span></span>
                        <button class="strip-topic-fund-btn">Contribute →</button>
                    </div>
                </div>`;
            }).join('');
            stripActiveTopics.classList.add('visible');
        }

        /* ── Render funded/waiting topics ── */
        function renderFundedTopics(creatorId) {
            const topics = CREATOR_FUNDED[creatorId] || [];
            if (topics.length === 0) { stripFundedTopics.classList.remove('visible'); return; }
            stripFundedList.innerHTML = topics.map(t => {
                const isOnHold = t.status === 'on_hold';
                const dotColor = isOnHold ? 'background:#9CA3AF;' : '';
                const badge    = isOnHold
                    ? `<div class="strip-funded-badge strip-onhold-badge">On Hold</div>`
                    : `<div class="strip-funded-badge">Waiting Upload</div>`;
                return `<div class="strip-funded-item">
                    <div class="strip-funded-dot" style="${dotColor}"></div>
                    <div class="strip-funded-title">${t.title}</div>
                    ${badge}
                </div>`;
            }).join('');
            stripFundedTopics.classList.add('visible');
        }

        /* ── Select a creator ── */
        pickerGrid.addEventListener('click', e => {
            const item = e.target.closest('.creator-picker-item');
            if (!item) return;
            selectedCreator = {
                id:    parseInt(item.dataset.id, 10) || 0,
                name:  item.dataset.name,
                price: parseInt(item.dataset.price, 10) || 0,
                image: item.dataset.image,
                bio:   item.dataset.bio || ''
            };

            if (selectedCreator.image) {
                stripAvatar.innerHTML = `<img src="/uploads/creators/${selectedCreator.image}" alt="">`;
            } else {
                stripAvatar.innerHTML = `<span class="strip-avatar-initials">${selectedCreator.name.charAt(0).toUpperCase()}</span>`;
            }

            selectHint.classList.add('hidden');
            topicInput.placeholder = `Commission a video from ${selectedCreator.name}…`;
            topicInput.focus();
            stripSend.disabled = !topicInput.value.trim();

            if (selectedCreator.image) {
                stripCreatorCardAvatar.innerHTML = `<img src="/uploads/creators/${selectedCreator.image}" alt="">`;
            } else {
                stripCreatorCardAvatar.innerHTML = selectedCreator.name.charAt(0).toUpperCase();
            }
            stripCreatorCardName.textContent  = selectedCreator.name;
            stripCreatorCardBio.textContent   = selectedCreator.bio;
            stripCreatorCardPrice.textContent = selectedCreator.price ? `from $${selectedCreator.price}` : 'Free';
            stripCreatorCard.classList.add('visible');
            renderCreatorTopics(selectedCreator.id);
            renderFundedTopics(selectedCreator.id);

            pickerGrid.querySelectorAll('.creator-picker-item').forEach(b => b.classList.remove('selected'));
            item.classList.add('selected');
            closePicker();
        });

        /* ── X button — remove selected creator ── */
        stripCreatorCardX.addEventListener('click', () => {
            stripCreatorCard.classList.remove('visible');
            stripActiveTopics.classList.remove('visible');
            stripFundedTopics.classList.remove('visible');
            selectedCreator = null;
            pickerGrid.querySelectorAll('.creator-picker-item').forEach(b => b.classList.remove('selected'));
            stripAvatar.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>`;
            selectHint.classList.remove('hidden');
            topicInput.placeholder = 'Type your topic idea…';
            stripSend.disabled = true;
        });

        /* ── Enable send + live counter ── */
        topicInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); if (selectedCreator && topicInput.value.trim()) stripSend.click(); }
        });
        topicInput.addEventListener('input', () => {
            topicInput.value = topicInput.value.replace(/\n/g, '');
            stripSend.disabled = !topicInput.value.trim() || !selectedCreator;
            const len = topicInput.value.length;
            topicInputCount.textContent = `${len}/100`;
        });

        topicDesc.addEventListener('input', () => {
            topicDescCount.textContent = `${topicDesc.value.length}/350`;
        });

        /* ── Click on an active topic card ── */
        stripTopicsList.addEventListener('click', e => {
            const item = e.target.closest('.strip-topic-item');
            if (!item || !selectedCreator) return;
            const topicId          = item.dataset.topicId;
            const topicTitle       = item.dataset.topicTitle;
            const topicDescription = item.dataset.topicDesc || '';
            const currentFunding   = parseFloat(item.dataset.currentFunding) || 0;
            const fundingThreshold = parseFloat(item.dataset.fundingThreshold) || 0;
            const pct       = fundingThreshold > 0 ? Math.min(100, Math.round((currentFunding / fundingThreshold) * 100)) : 0;
            const remaining = Math.max(0, fundingThreshold - currentFunding);
            activeTopic = { id: topicId, title: topicTitle, description: topicDescription };

            topicModalTitle.textContent    = 'Fund this topic';
            topicAmountLabel.textContent   = 'Your contribution';
            topicPreview.textContent       = topicTitle;
            topicModalSub.textContent      = `For: ${selectedCreator.name}`;
            minPriceHint.textContent       = '';
            topicAmount.min                = 1;
            topicAmount.max                = remaining > 0 ? remaining : '';
            topicAmount.placeholder        = remaining > 0 ? Math.round(remaining) : '0';
            topicAmount.value              = '';
            topicDesc.value                = topicDescription;
            topicDescCount.textContent     = `${topicDescription.length}/350`;
            topicDesc.setAttribute('readonly', true);
            topicDesc.style.background     = '#F5F5F5';
            topicDesc.style.color          = '#888';
            topicDescField.style.display   = '';
            topicFundingInfo.style.display = 'block';
            topicFundingInfo.innerHTML     = `<strong style="color:#E8305A;">$${Math.round(currentFunding)}</strong> raised &mdash; <strong style="color:#E8305A;">${pct}%</strong> of the $${Math.round(fundingThreshold)} goal &bull; <strong style="color:#333;">$${Math.round(remaining)}</strong> still needed`;
            topicOverlay.classList.add('open');
            topicAmount.focus();
        });

        /* ── Open topic details modal (new request) ── */
        stripSend.addEventListener('click', () => {
            if (!selectedCreator || !topicInput.value.trim()) return;
            activeTopic = null;
            topicModalTitle.textContent  = 'Send your request';
            topicAmountLabel.textContent = 'Your offer amount';
            topicAmount.removeAttribute('max');
            topicPreview.textContent     = topicInput.value.trim();
            topicModalSub.textContent    = `To: ${selectedCreator.name}`;
            if (selectedCreator.price > 0) {
                minPriceHint.textContent = `Minimum price: $${selectedCreator.price}`;
                topicAmount.min          = selectedCreator.price;
                topicAmount.placeholder  = selectedCreator.price;
            } else {
                minPriceHint.textContent = '';
            }
            topicAmount.value          = '';
            topicDesc.value            = '';
            topicDescCount.textContent = '0/350';
            topicDesc.removeAttribute('readonly');
            topicDesc.style.background = '';
            topicDesc.style.color      = '';
            topicDescField.style.display   = '';
            topicFundingInfo.style.display = 'none';
            topicOverlay.classList.add('open');
            topicDesc.focus();
        });

        closeTopicBtn.addEventListener('click', closeTopic);
        topicOverlay.addEventListener('click', e => { if (e.target === topicOverlay) closeTopic(); });
        function closeTopic() { topicOverlay.classList.remove('open'); }

        /* ── Submit: call Stripe API directly ── */
        topicSubmit.addEventListener('click', () => {
            const amount = parseFloat(topicAmount.value);
            const topic  = activeTopic ? activeTopic.title : topicInput.value.trim();
            const desc   = topicDesc.value.trim();

            if (!amount || amount < 1) {
                topicAmount.style.borderColor = '#E8305A';
                topicAmount.focus();
                setTimeout(() => topicAmount.style.borderColor = '', 1500);
                return;
            }
            if (activeTopic && topicAmount.max && amount > parseFloat(topicAmount.max)) {
                minPriceHint.textContent = `Maximum contribution is $${Math.round(topicAmount.max)} (remaining needed)`;
                minPriceHint.style.color = '#E8305A';
                topicAmount.focus();
                setTimeout(() => { minPriceHint.textContent = ''; minPriceHint.style.color = ''; }, 4000);
                return;
            }
            if (!activeTopic && selectedCreator.price > 0 && amount < selectedCreator.price) {
                minPriceHint.style.color = '#E8305A';
                topicAmount.focus();
                setTimeout(() => minPriceHint.style.color = '', 1500);
                return;
            }

            topicSubmit.disabled     = true;
            topicSubmit.textContent  = 'Processing…';

            let endpoint, payload;
            if (activeTopic) {
                endpoint = '/api/get-topic.php';
                payload  = { topic_id: parseInt(activeTopic.id, 10), amount };
            } else {
                endpoint = '/api/create-topic.php';
                payload  = {
                    creator_id:     selectedCreator.id,
                    title:          topic,
                    description:    desc || topic,
                    funding_goal:   amount,
                    initial_amount: amount
                };
            }

            fetch(endpoint, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (data.checkout_url) {
                    window.location.href = data.checkout_url;
                } else {
                    topicSubmit.disabled    = false;
                    topicSubmit.textContent = 'Continue to payment →';
                    const errMsg = data.error || 'Something went wrong. Please try again.';
                    minPriceHint.textContent = errMsg;
                    minPriceHint.style.color = '#E8305A';
                    setTimeout(() => { minPriceHint.textContent = ''; minPriceHint.style.color = ''; }, 4000);
                }
            })
            .catch(() => {
                topicSubmit.disabled    = false;
                topicSubmit.textContent = 'Continue to payment →';
                minPriceHint.textContent = 'Network error. Please try again.';
                minPriceHint.style.color = '#E8305A';
                setTimeout(() => { minPriceHint.textContent = ''; minPriceHint.style.color = ''; }, 4000);
            });
        });

        topicInputCount.textContent = '0/100';
    })();
    </script>
</body>
</html>

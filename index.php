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
        .hero-stat { flex: 1; text-align: center; max-width: 150px; display: flex; flex-direction: column; align-items: center; }
        .hero-stat-n { font-size: 26px; font-weight: 600; color: var(--text-dark); min-height: 38px; display: flex; align-items: center; justify-content: center; }
        .hero-stat-n span { color: var(--tl-pink); }
        .hero-stat-n--sm { font-size: 17px; }
        .hero-stat-label { font-size: 11px; color: var(--tl-muted); margin-top: 4px; }
        .hero-stat-divider { width: 1px; background: var(--tl-border); align-self: stretch; margin: 0 10px; }

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
            border-top: 1px solid #1e1e1e;
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
            <h1>
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
                    <div class="hero-stat-n">90<span>%</span></div>
                    <div class="hero-stat-label">Creator Keeps</div>
                </div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat">
                    <div class="hero-stat-n">0<span>$</span></div>
                    <div class="hero-stat-label">Cost to sign up</div>
                </div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat">
                    <div class="hero-stat-n hero-stat-n--sm">Your<span> Price</span></div>
                    <div class="hero-stat-label">Your Terms</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Creator strip -->
    <div style="background:#F0F0F0; border-top:1px solid #E5E5E5; border-bottom:1px solid #E5E5E5; padding-top:38px; overflow:visible;">
        <div class="creator-strip">
            <div class="strip-avatar-wrap">
                <div class="select-creator-hint" id="selectCreatorHint">
                    <span>Click to Select Creator</span>
                    <svg class="hint-arrow" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M4 4 C4 14, 12 18, 11 20" stroke="#E8305A" stroke-width="1.8" stroke-linecap="round" fill="none"/><path d="M8 17 L11 21 L14 17" stroke="#E8305A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>
                </div>
            <button class="strip-avatar" id="stripAvatar" title="Choose a creator">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            </button>
            </div>
            <input type="text" class="strip-input-field" id="topicInput" placeholder="Choose a creator, then type your topic idea…" disabled maxlength="100">
            <button class="strip-send" id="stripSend" disabled>
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M14 8L2 2l2 6-2 6 12-6z" fill="#fff"/></svg>
            </button>
        </div>
        <div style="text-align:right; padding: 4px 30px 0; font-size:11px; color:#bbb;" id="topicInputCount"></div>
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
                <?php foreach ($creators as $c): ?>
                <button class="creator-picker-item" data-name="<?php echo htmlspecialchars($c->display_name); ?>" data-price="<?php echo (int)($c->minimum_topic_price ?? 100); ?>" data-image="<?php echo htmlspecialchars($c->profile_image ?? ''); ?>">
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
                    <label class="tl-label">Your offer amount</label>
                    <div class="tl-input-prefix-wrap">
                        <span class="tl-prefix">$</span>
                        <input type="number" id="topicAmount" class="tl-input" placeholder="0" min="1">
                    </div>
                    <div class="tl-hint" id="minPriceHint"></div>
                </div>
                <div class="tl-field">
                    <label class="tl-label">Additional details <span class="tl-optional">(optional)</span></label>
                    <textarea id="topicDesc" class="tl-textarea" placeholder="Any context or specifics for the creator…" rows="3"></textarea>
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

        let selectedCreator = null; // { name, price, image }

        /* ── Open / close creator picker ── */
        stripAvatar.addEventListener('click', () => {
            pickerOverlay.classList.add('open');
            creatorSearch.focus();
        });
        closePickerBtn.addEventListener('click', closePicker);
        pickerOverlay.addEventListener('click', e => { if (e.target === pickerOverlay) closePicker(); });
        function closePicker() { pickerOverlay.classList.remove('open'); creatorSearch.value = ''; filterPicker(''); }

        /* ── Creator search inside picker ── */
        creatorSearch.addEventListener('input', () => filterPicker(creatorSearch.value.trim().toLowerCase()));
        function filterPicker(q) {
            pickerGrid.querySelectorAll('.creator-picker-item').forEach(btn => {
                const n = btn.dataset.name.toLowerCase();
                btn.classList.toggle('hidden', q.length > 0 && !n.includes(q));
            });
        }

        /* ── Select a creator ── */
        pickerGrid.addEventListener('click', e => {
            const item = e.target.closest('.creator-picker-item');
            if (!item) return;
            selectedCreator = {
                name:  item.dataset.name,
                price: parseInt(item.dataset.price, 10) || 0,
                image: item.dataset.image
            };

            // Update strip avatar
            if (selectedCreator.image) {
                stripAvatar.innerHTML = `<img src="/uploads/creators/${selectedCreator.image}" alt="">`;
            } else {
                const initial = selectedCreator.name.charAt(0).toUpperCase();
                stripAvatar.innerHTML = `<span class="strip-avatar-initials">${initial}</span>`;
            }

            // Hide hint once creator picked
            selectHint.classList.add('hidden');

            // Enable input
            topicInput.disabled = false;
            topicInput.placeholder = `Commission a video from ${selectedCreator.name}…`;
            topicInput.focus();
            stripSend.disabled = false;

            // Highlight selected
            pickerGrid.querySelectorAll('.creator-picker-item').forEach(b => b.classList.remove('selected'));
            item.classList.add('selected');

            closePicker();
        });

        /* ── Enable/disable send based on input ── */
        const topicInputCount = document.getElementById('topicInputCount');

        topicInput.addEventListener('input', () => {
            stripSend.disabled = !topicInput.value.trim() || !selectedCreator;
            const len = topicInput.value.length;
            topicInputCount.textContent = len > 0 ? `${len}/100` : '';
        });

        /* ── Open topic details modal on send ── */
        stripSend.addEventListener('click', () => {
            if (!selectedCreator || !topicInput.value.trim()) return;
            topicPreview.textContent = topicInput.value.trim();
            topicModalSub.textContent = `To: ${selectedCreator.name}`;
            if (selectedCreator.price > 0) {
                minPriceHint.textContent = `Minimum price: $${selectedCreator.price}`;
                topicAmount.min = selectedCreator.price;
                topicAmount.placeholder = selectedCreator.price;
            }
            topicAmount.value = '';
            topicDesc.value = '';
            topicOverlay.classList.add('open');
            topicAmount.focus();
        });

        closeTopicBtn.addEventListener('click', closeTopic);
        topicOverlay.addEventListener('click', e => { if (e.target === topicOverlay) closeTopic(); });
        function closeTopic() { topicOverlay.classList.remove('open'); }

        /* ── Submit: redirect to creator profile with params ── */
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

            const params = new URLSearchParams({
                topic:  topic,
                amount: amount,
            });
            if (desc) params.set('desc', desc);

            window.location.href = `/${encodeURIComponent(selectedCreator.name)}?${params.toString()}`;
        });
    })();
    </script>
</body>
</html>

<?php
// RCMP NIMS - UniKL RCMP NextCheck Inventory Management System
require_once __DIR__ . '/config/database.php';

$totalAssets = 0;
$nexcheckRequestTotal = 0;
$recentActivities = [];
$dbOk = false;

function index_time_ago(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }
    $t = strtotime($datetime);
    if ($t === false) {
        return '—';
    }
    $diff = time() - $t;
    if ($diff < 45) {
        return 'just now';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . 'h ago';
    }
    if ($diff < 604800) {
        return (int) floor($diff / 86400) . 'd ago';
    }
    return date('M j, Y', $t);
}

function index_kind_color(string $kind): string
{
    switch ($kind) {
        case 'laptop_reg':
        case 'network_reg':
        case 'av_reg':
            return '#2563eb';
        case 'handover':
            return '#10b981';
        case 'warranty':
            return '#f59e0b';
        case 'repair':
            return '#f43f5e';
        default:
            return '#64748b';
    }
}

try {
    $pdo = db();
    $laptops  = (int) $pdo->query('SELECT COUNT(*) FROM laptop')->fetchColumn();
    $networks = (int) $pdo->query('SELECT COUNT(*) FROM network')->fetchColumn();
    $avs      = (int) $pdo->query('SELECT COUNT(*) FROM av')->fetchColumn();
    $totalAssets = $laptops + $networks + $avs;

    $nexcheckRequestTotal = (int) $pdo->query('SELECT COUNT(*) FROM nexcheck_request WHERE rejected_at IS NULL')->fetchColumn();

    $recentSql = '
        SELECT kind, title, ts, asset_id, asset_type FROM (
            SELECT
                \'laptop_reg\' AS kind,
                CONCAT(\'Laptop: \', TRIM(CONCAT(IFNULL(l.brand, \'\'), \' \', IFNULL(l.model, \'\')))) AS title,
                l.created_at AS ts,
                l.asset_id,
                \'laptop\' AS asset_type
            FROM laptop l
            UNION ALL
            SELECT
                \'network_reg\',
                CONCAT(\'Network: \', TRIM(CONCAT(IFNULL(n.brand, \'\'), \' \', IFNULL(n.model, \'\')))),
                n.created_at,
                n.asset_id,
                \'network\'
            FROM network n
            UNION ALL
            SELECT
                \'av_reg\',
                CONCAT(\'AV: \', TRIM(CONCAT(IFNULL(a.category, \'\'), \' \', IFNULL(a.brand, \'\'), \' \', IFNULL(a.model, \'\')))),
                a.created_at,
                a.asset_id,
                \'av\'
            FROM av a
            UNION ALL
            SELECT
                \'handover\',
                CONCAT(\'Handover #\', h.handover_id, \' · laptop #\', h.asset_id),
                h.created_at,
                h.asset_id,
                \'laptop\'
            FROM handover h
            UNION ALL
            SELECT
                \'warranty\',
                CONCAT(\'Warranty (\', w.asset_type, \') #\', w.asset_id),
                w.created_at,
                w.asset_id,
                w.asset_type
            FROM warranty w
            UNION ALL
            SELECT
                \'repair\',
                CONCAT(\'Repair (\', r.asset_type, \'): \', LEFT(r.issue_summary, 80)),
                r.created_at,
                r.asset_id,
                r.asset_type
            FROM repair r
        ) u
        ORDER BY ts DESC
        LIMIT 3
    ';
    $recentActivities = $pdo->query($recentSql)->fetchAll(PDO::FETCH_ASSOC);
    $dbOk = true;
} catch (Throwable $e) {
    $dbOk = false;
}

$lastActivityAgo = '';
if (!empty($recentActivities[0]['ts'])) {
    $lastActivityAgo = index_time_ago($recentActivities[0]['ts']);
} elseif ($dbOk) {
    $lastActivityAgo = 'No events yet';
} else {
    $lastActivityAgo = '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RCMP NIMS - UniKL NextCheck Inventory Management</title>
    <link rel="icon" type="image/png" href="public/rcmp.png">
    <meta name="description" content="RCMP NIMS is a modern asset management system for the UniKL RCMP IT Department.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary:       #2563eb;
            --primary-hover: #1d4ed8;
            --primary-glow:  rgba(37,99,235,0.18);
            --secondary:     #0ea5e9;
            --accent:        #f59e0b;
            --green:         #10b981;
            --purple:        #8b5cf6;
            /* surfaces */
            --bg:            #f0f5ff;
            --surface:       #ffffff;
            --surface-alt:   #f6f9ff;
            --card-border:   rgba(37,99,235,0.1);
            /* text */
            --text-main:     #0f172a;
            --text-muted:    #64748b;
            --text-subtle:   #94a3b8;
            --mono: 'JetBrains Mono', monospace;
        }

        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        html { scroll-behavior:smooth; }

        body {
            font-family:'Outfit',sans-serif;
            background:var(--bg);
            color:var(--text-main);
            min-height:100vh;
            overflow-x:hidden;
            cursor:none;
        }

        /* Custom cursor */
        .cursor-dot {
            width:6px; height:6px; background:var(--primary);
            border-radius:50%; position:fixed; top:0; left:0;
            pointer-events:none; z-index:9999; transform:translate(-50%,-50%);
        }
        .cursor-ring {
            width:32px; height:32px; border:1.5px solid rgba(37,99,235,0.4);
            border-radius:50%; position:fixed; top:0; left:0;
            pointer-events:none; z-index:9998; transform:translate(-50%,-50%);
            transition:all 0.15s ease;
        }
        .cursor-ring.hovering { width:48px; height:48px; border-color:var(--primary); background:rgba(37,99,235,0.06); }

        /* Background */
        .page-bg {
            position:fixed; inset:0; z-index:-3;
            background-image:url('public/bgm.png');
            background-size:cover; background-position:center;
            background-attachment:fixed; opacity:0.035;
        }
        .bg-overlay {
            position:fixed; inset:0; z-index:-2;
            background:
                radial-gradient(ellipse 80% 60% at 10% 5%,  rgba(37,99,235,0.08) 0%, transparent 65%),
                radial-gradient(ellipse 70% 55% at 90% 90%, rgba(14,165,233,0.07) 0%, transparent 65%),
                radial-gradient(ellipse 50% 40% at 50% 50%, rgba(245,158,11,0.03) 0%, transparent 60%),
                linear-gradient(170deg, #eef4ff 0%, #f0f5ff 60%, #e9f4fd 100%);
        }
        .grid-bg {
            position:fixed; inset:0; z-index:-1;
            background-image:
                linear-gradient(rgba(37,99,235,0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(37,99,235,0.05) 1px, transparent 1px);
            background-size:60px 60px;
            animation:gridDrift 20s linear infinite;
        }
        @keyframes gridDrift { to { background-position:60px 60px; } }

        /* Navbar */
        .navbar {
            display:flex; justify-content:space-between; align-items:center;
            padding:0 5%; height:72px;
            background:rgba(255,255,255,0.75);
            backdrop-filter:blur(20px);
            border-bottom:1px solid rgba(37,99,235,0.08);
            position:sticky; top:0; z-index:200;
            animation:navSlideDown 0.6s cubic-bezier(0.16,1,0.3,1) forwards;
            box-shadow:0 1px 0 rgba(37,99,235,0.06);
        }
        @keyframes navSlideDown { from{opacity:0;transform:translateY(-20px)} to{opacity:1;transform:translateY(0)} }

        .nav-brand { display:flex; align-items:center; gap:1rem; }
        .nav-logo {
            height:42px; object-fit:contain;
            filter:drop-shadow(0 2px 6px rgba(37,99,235,0.15));
            transition:transform 0.4s cubic-bezier(0.34,1.56,0.64,1);
        }
        .nav-logo:hover { transform:scale(1.08) rotate(-2deg); }

        .nav-live {
            display:flex; align-items:center; gap:6px;
            font-family:var(--mono); font-size:0.7rem; color:var(--green);
            letter-spacing:1px; text-transform:uppercase;
            padding:4px 10px; border:1px solid rgba(16,185,129,0.25);
            border-radius:4px; background:rgba(16,185,129,0.07);
        }
        .live-dot {
            width:6px; height:6px; border-radius:50%;
            background:var(--green); box-shadow:0 0 8px var(--green);
            animation:blink 1.5s ease-in-out infinite;
        }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.3} }

        .nav-links { display:flex; gap:2rem; align-items:center; }
        .nav-links a {
            color:var(--text-muted); text-decoration:none;
            font-weight:500; font-size:0.9rem; transition:color 0.2s; position:relative;
        }
        .nav-links a::after {
            content:''; position:absolute; bottom:-4px; left:0;
            width:0; height:1.5px; background:var(--primary);
            transition:width 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        .nav-links a:hover { color:var(--primary); }
        .nav-links a:hover::after { width:100%; }

        .btn-login-nav {
            padding:0.55rem 1.5rem; border-radius:8px;
            background:linear-gradient(135deg,var(--primary),var(--secondary));
            border:none; color:white;
            font-family:'Outfit',sans-serif; font-weight:600; font-size:0.9rem;
            cursor:none; transition:all 0.3s;
            box-shadow:0 4px 16px rgba(37,99,235,0.3);
            position:relative; overflow:hidden;
        }
        .btn-login-nav::before {
            content:''; position:absolute; top:0; left:-100%;
            width:100%; height:100%;
            background:linear-gradient(90deg,transparent,rgba(255,255,255,0.25),transparent);
            transition:left 0.5s;
        }
        .btn-login-nav:hover::before { left:100%; }
        .btn-login-nav:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(37,99,235,0.4); }

        /* Hero */
        .hero {
            display:flex; align-items:center; padding:5rem 5% 4rem;
            gap:5rem; min-height:calc(100vh - 72px); position:relative;
        }
        .hero-content {
            flex:1; max-width:600px;
            opacity:0; transform:translateY(40px);
            animation:slideUpFade 0.9s cubic-bezier(0.16,1,0.3,1) 0.1s forwards;
        }
        @keyframes slideUpFade { to{opacity:1;transform:translateY(0)} }

        .hero-logo {
            display:block; height:140px; object-fit:contain; margin-bottom:1.75rem;
            filter:drop-shadow(0 4px 16px rgba(37,99,235,0.15));
            transition:transform 0.5s cubic-bezier(0.34,1.56,0.64,1);
        }
        .hero-logo:hover { transform:scale(1.05); }

        .system-tag {
            display:inline-flex; align-items:center; gap:8px;
            font-family:var(--mono); font-size:0.72rem; color:var(--primary);
            letter-spacing:2px; text-transform:uppercase; margin-bottom:1.5rem;
            padding:6px 14px;
            border:1px solid rgba(37,99,235,0.2); border-radius:4px;
            background:rgba(37,99,235,0.06);
        }

        .hero-headline {
            font-size:5.5rem; font-weight:900; line-height:0.95;
            letter-spacing:-3px; margin-bottom:0.5rem;
            background:linear-gradient(135deg,#0f172a 30%,#2563eb 100%);
            -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
        }
        .headline-accent {
            display:block;
            background:linear-gradient(90deg,var(--primary),var(--secondary),var(--accent),var(--primary));
            background-size:300%;
            -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
            animation:gradientShift 4s linear infinite;
            font-size:2.6rem; font-weight:600; letter-spacing:-1px; margin-top:0.5rem; line-height:1.2;
        }
        @keyframes gradientShift {
            0%{background-position:0% 50%} 50%{background-position:100% 50%} 100%{background-position:0% 50%}
        }

        .hero-desc {
            font-size:1.05rem; color:var(--text-muted); line-height:1.8;
            margin:1.75rem 0 2.5rem; max-width:480px;
        }
        .cta-group { display:flex; gap:1rem; flex-wrap:wrap; }

        .btn {
            padding:0.9rem 2rem; border-radius:10px;
            font-family:'Outfit',sans-serif; font-weight:600; font-size:0.95rem;
            cursor:none; transition:all 0.3s cubic-bezier(0.4,0,0.2,1);
            text-decoration:none; display:inline-flex; align-items:center; gap:8px;
            position:relative; overflow:hidden;
        }
        .btn::before {
            content:''; position:absolute; top:0; left:-100%;
            width:100%; height:100%;
            background:linear-gradient(120deg,transparent,rgba(255,255,255,0.3),transparent);
            transition:left 0.5s;
        }
        .btn:hover::before { left:100%; }
        .btn-primary {
            background:linear-gradient(135deg,var(--primary),var(--secondary));
            color:white; border:none;
            box-shadow:0 6px 20px rgba(37,99,235,0.28);
        }
        .btn-primary:hover { transform:translateY(-3px); box-shadow:0 14px 32px rgba(37,99,235,0.36); }
        .btn-ghost {
            background:white; border:1.5px solid rgba(37,99,235,0.18); color:var(--primary);
        }
        .btn-ghost:hover { border-color:var(--primary); background:rgba(37,99,235,0.04); transform:translateY(-3px); box-shadow:0 8px 20px rgba(37,99,235,0.1); }

        .hero-stats { display:flex; gap:1.5rem; margin-top:2.5rem; flex-wrap:wrap; }
        .hero-stat { display:flex; align-items:center; gap:8px; font-size:0.85rem; color:var(--text-muted); }
        .hero-stat span { font-family:var(--mono); font-weight:600; color:var(--text-main); font-size:1rem; }
        .hero-stat i { color:var(--primary); font-size:1rem; }

        /* Hero visual */
        .hero-visual {
            flex:1; display:flex; justify-content:center; align-items:center;
            position:relative; opacity:0; animation:fadeIn 1.2s ease-out 0.4s forwards;
        }
        @keyframes fadeIn { to{opacity:1} }

        .orb { position:absolute; border-radius:50%; filter:blur(70px); pointer-events:none; }
        .orb-1 { width:360px; height:360px; background:rgba(37,99,235,0.14); top:-60px; right:-80px; animation:orbFloat 14s ease-in-out infinite alternate; }
        .orb-2 { width:280px; height:280px; background:rgba(14,165,233,0.11); bottom:-40px; left:-60px; animation:orbFloat 18s ease-in-out infinite alternate-reverse; }
        .orb-3 { width:200px; height:200px; background:rgba(245,158,11,0.09); top:50%; left:50%; transform:translate(-50%,-50%); animation:orbPulse 10s ease-in-out infinite; }
        @keyframes orbFloat { 0%{transform:translate(0,0) scale(1)} 100%{transform:translate(28px,-28px) scale(1.08)} }
        @keyframes orbPulse { 0%,100%{transform:translate(-50%,-50%) scale(0.85)} 50%{transform:translate(-50%,-50%) scale(1.15)} }

        .glass-panel {
            width:100%; max-width:500px;
            background:rgba(255,255,255,0.82);
            border:1px solid rgba(37,99,235,0.1);
            border-radius:24px; padding:2rem;
            backdrop-filter:blur(30px);
            box-shadow:0 24px 60px rgba(37,99,235,0.1), 0 4px 16px rgba(37,99,235,0.06), inset 0 1px 0 rgba(255,255,255,1);
            position:relative; z-index:2;
            animation:panelFloat 9s ease-in-out infinite; transition:transform 0.3s ease;
        }
        .glass-panel::before {
            content:''; position:absolute; top:0; left:15%; right:15%; height:1px;
            background:linear-gradient(90deg,transparent,var(--primary),transparent); opacity:0.35;
        }
        @keyframes panelFloat { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-14px)} }

        .panel-topbar {
            display:flex; align-items:center; justify-content:space-between;
            margin-bottom:1.5rem; padding-bottom:1.25rem;
            border-bottom:1px solid rgba(37,99,235,0.08);
        }
        .panel-title { display:flex; align-items:center; gap:8px; font-size:0.95rem; font-weight:600; color:var(--text-main); }
        .panel-title i { color:var(--primary); }

        .status-pill {
            display:flex; align-items:center; gap:6px; padding:5px 12px;
            border-radius:6px; font-size:0.75rem; font-weight:600;
            font-family:var(--mono); letter-spacing:0.5px; color:var(--green);
            background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.18);
        }
        .pulse-dot {
            width:7px; height:7px; border-radius:50%; background:var(--green);
            box-shadow:0 0 8px var(--green); animation:pulse 2s ease-in-out infinite;
        }
        @keyframes pulse { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.4);opacity:0.6} }

        .stats-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem; }
        .stat-card {
            background:rgba(37,99,235,0.03); border:1px solid rgba(37,99,235,0.08);
            border-radius:14px; padding:1.25rem;
            transition:all 0.35s cubic-bezier(0.4,0,0.2,1); position:relative; overflow:hidden;
        }
        .stat-card:hover { border-color:rgba(37,99,235,0.2); transform:translateY(-4px); box-shadow:0 10px 28px rgba(37,99,235,0.1); background:rgba(37,99,235,0.05); }
        .stat-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; margin-bottom:1rem; }
        .stat-value { font-size:2.2rem; font-weight:800; line-height:1; color:var(--text-main); margin-bottom:4px; font-family:var(--mono); font-variant-numeric:tabular-nums; }
        .stat-label { font-size:0.78rem; color:var(--text-muted); font-weight:500; }

        .stat-card-wide {
            grid-column:1/-1; background:rgba(37,99,235,0.03);
            border:1px solid rgba(37,99,235,0.08); border-radius:14px; padding:1.25rem;
            transition:all 0.35s ease;
        }
        .stat-card-wide:hover { border-color:rgba(16,185,129,0.22); box-shadow:0 8px 24px rgba(16,185,129,0.07); }
        .wide-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem; }
        .wide-label { font-size:0.78rem; color:var(--text-muted); font-family:var(--mono); letter-spacing:1px; text-transform:uppercase; }
        .wide-title { font-size:1rem; font-weight:600; color:var(--text-main); margin-top:2px; }
        .wide-icon { font-size:2rem; color:var(--green); line-height:1; }
        .health-bar-track { width:100%; height:6px; background:rgba(37,99,235,0.08); border-radius:3px; overflow:hidden; margin-top:0.75rem; }
        .health-bar-fill { width:0%; height:100%; background:linear-gradient(90deg,var(--green),#34d399,var(--secondary)); border-radius:3px; box-shadow:0 0 8px rgba(16,185,129,0.35); transition:width 2s cubic-bezier(0.22,1,0.36,1) 0.8s; }
        .health-footer { display:flex; justify-content:space-between; margin-top:6px; font-size:0.75rem; font-family:var(--mono); }
        .health-footer span:first-child { color:var(--text-muted); }
        .health-footer span:last-child { color:var(--green); font-weight:600; }

        .activity-feed { margin-top:1rem; border-top:1px solid rgba(37,99,235,0.07); padding-top:1rem; }
        .feed-title { font-size:0.72rem; color:var(--text-muted); font-family:var(--mono); letter-spacing:1.5px; text-transform:uppercase; margin-bottom:0.75rem; }
        .feed-item { display:flex; align-items:center; gap:10px; padding:7px 0; font-size:0.8rem; border-bottom:1px solid rgba(37,99,235,0.05); opacity:0; animation:feedItemIn 0.4s ease forwards; }
        .feed-item:last-child { border-bottom:none; }
        .feed-item:nth-child(1){animation-delay:1.2s} .feed-item:nth-child(2){animation-delay:1.5s} .feed-item:nth-child(3){animation-delay:1.8s}
        @keyframes feedItemIn { from{opacity:0;transform:translateX(-8px)} to{opacity:1;transform:translateX(0)} }
        .feed-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
        .feed-text { color:var(--text-muted); flex:1; line-height:1.3; }
        .feed-time { font-family:var(--mono); font-size:0.7rem; color:var(--text-subtle); white-space:nowrap; }

        /* Features */
        .features-section { padding:6rem 5%; background:var(--surface); }
        .section-eyebrow { display:flex; align-items:center; gap:10px; font-family:var(--mono); font-size:0.72rem; color:var(--primary); letter-spacing:2px; text-transform:uppercase; margin-bottom:1rem; }
        .eyebrow-line { height:1px; width:40px; background:var(--primary); opacity:0.35; }
        .section-title { font-size:2.8rem; font-weight:800; line-height:1.1; letter-spacing:-1.5px; margin-bottom:1rem; background:linear-gradient(135deg,#0f172a 40%,#2563eb 100%); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
        .section-desc { font-size:1rem; color:var(--text-muted); max-width:500px; line-height:1.7; }
        .features-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1.25rem; margin-top:3.5rem; }
        .feature-card {
            background:var(--surface-alt); border:1px solid rgba(37,99,235,0.09);
            border-radius:20px; padding:2rem;
            transition:opacity 0.6s ease,transform 0.6s cubic-bezier(0.16,1,0.3,1),border-color 0.4s,box-shadow 0.4s;
            position:relative; overflow:hidden; opacity:0; transform:translateY(30px);
        }
        .feature-card.visible { opacity:1; transform:translateY(0); }
        .feature-card::after { content:''; position:absolute; inset:0; background:var(--card-hover-bg,transparent); opacity:0; transition:opacity 0.4s; border-radius:20px; }
        .feature-card:hover { transform:translateY(-6px); border-color:var(--card-hover-border,rgba(37,99,235,0.18)); box-shadow:var(--card-hover-shadow,0 16px 36px rgba(37,99,235,0.08)); }
        .feature-card:hover::after { opacity:1; }
        .feature-icon-wrap { width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.5rem; margin-bottom:1.25rem; position:relative; z-index:1; transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1); }
        .feature-card:hover .feature-icon-wrap { transform:scale(1.15) rotate(-5deg); }
        .feature-name { font-size:1.1rem; font-weight:700; color:var(--text-main); margin-bottom:0.6rem; position:relative; z-index:1; }
        .feature-desc { font-size:0.88rem; color:var(--text-muted); line-height:1.65; position:relative; z-index:1; }
        .feature-card-wide { grid-column:span 2; }

        /* CTA */
        .cta-section {
            padding:5rem 5%; display:flex; align-items:center;
            justify-content:space-between; gap:3rem; flex-wrap:wrap;
            border-top:1px solid rgba(37,99,235,0.08); position:relative;
            background:linear-gradient(135deg,#eef4ff,#f0f9ff);
        }
        .cta-section::before { content:''; position:absolute; top:0; left:50%; transform:translateX(-50%); width:500px; height:1px; background:linear-gradient(90deg,transparent,var(--primary),var(--secondary),transparent); opacity:0.35; }
        .cta-text h3 { font-size:2rem; font-weight:800; letter-spacing:-1px; margin-bottom:0.5rem; color:var(--text-main); }
        .cta-text p { font-size:0.95rem; color:var(--text-muted); max-width:380px; }
        .cta-actions { display:flex; gap:1rem; flex-wrap:wrap; align-items:center; }

        /* Footer */
        .footer-bar { padding:1.5rem 5%; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; border-top:1px solid rgba(37,99,235,0.08); font-size:0.8rem; color:var(--text-muted); background:var(--surface); }
        .footer-brand { display:flex; align-items:center; gap:8px; }
        .footer-brand img { height:24px; opacity:0.5; }
        .footer-links { display:flex; gap:1.5rem; }
        .footer-links a { color:var(--text-muted); text-decoration:none; transition:color 0.2s; font-family:var(--mono); font-size:0.72rem; }
        .footer-links a:hover { color:var(--primary); }

        /* Reveal */
        .reveal { opacity:0; transform:translateY(24px); transition:opacity 0.7s ease,transform 0.7s cubic-bezier(0.16,1,0.3,1); }
        .reveal.visible { opacity:1; transform:none; }

        ::-webkit-scrollbar { width:5px; }
        ::-webkit-scrollbar-track { background:var(--bg); }
        ::-webkit-scrollbar-thumb { background:rgba(37,99,235,0.22); border-radius:3px; }
        ::-webkit-scrollbar-thumb:hover { background:var(--primary); }

        @media(max-width:1024px){ .hero-headline{font-size:4rem} .features-grid{grid-template-columns:1fr 1fr} .feature-card-wide{grid-column:span 1} }
        @media(max-width:768px){ .hero{flex-direction:column;text-align:center;padding:3rem 5%;gap:3rem} .hero-content{max-width:100%;display:flex;flex-direction:column;align-items:center} .hero-desc{margin:1.75rem auto 2.5rem} .hero-headline{font-size:3.5rem} .nav-links{display:none} .hero-visual{width:100%} .features-grid{grid-template-columns:1fr} .cta-section{flex-direction:column} .section-title{font-size:2rem} }
        @media(max-width:480px){ .hero-headline{font-size:2.8rem} .headline-accent{font-size:1.8rem} .cta-group,.cta-actions{flex-direction:column;width:100%} .btn{width:100%;justify-content:center} body{cursor:auto} .cursor-dot,.cursor-ring{display:none} }
    </style>
</head>
<body>

<div class="cursor-dot" id="cursorDot"></div>
<div class="cursor-ring" id="cursorRing"></div>
<div class="page-bg"></div>
<div class="bg-overlay"></div>
<div class="grid-bg"></div>

<nav class="navbar">
    <div class="nav-brand">
        <img src="public/logo-nims.png" alt="NextCheck NIMS" class="nav-logo">
        <div class="nav-live"><span class="live-dot"></span> SYSTEM ONLINE</div>
    </div>
    <div class="nav-links">
        <a href="index.php">Overview</a>
        <a href="#features">Capabilities</a>
        <a href="#resources">IT Guidelines</a>
        <button class="btn-login-nav" onclick="window.location.href='auth/login.php'">
            <i class="ri-login-circle-line"></i> Staff Portal
        </button>
    </div>
</nav>

<main class="hero">
    <div class="hero-content">
        <img src="public/unikl-official.png" alt="UniKL RCMP Logo" class="hero-logo">
        <div class="system-tag"><i class="ri-shield-keyhole-line"></i> IT Dept · Asset Management Platform</div>
        <h1 class="hero-headline">RCMP<br>NIMS</h1>
        <span class="headline-accent">NextCheck Inventory Management</span>
        <p class="hero-desc">A secure, centralized infrastructure tracking platform built for the UniKL RCMP IT Department — giving full visibility and control over every technical asset in real time.</p>
        <div class="cta-group">
            <a href="auth/login.php" class="btn btn-primary"><i class="ri-login-circle-line"></i> System Access</a>
            <a href="#features" class="btn btn-ghost"><i class="ri-article-line"></i> Learn More</a>
        </div>
        <div class="hero-stats">
            <div class="hero-stat"><i class="ri-computer-line"></i><span id="stat-endpoints">0</span> Assets Tracked</div>
            <div class="hero-stat"><i class="ri-shield-check-line"></i><span>100%</span> Secure</div>
            <div class="hero-stat"><i class="ri-time-line"></i><span>24/7</span> Monitored</div>
        </div>
    </div>

    <div class="hero-visual">
        <div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
        <div class="glass-panel" id="tiltPanel">
            <div class="panel-topbar">
                <div class="panel-title"><i class="ri-dashboard-3-line"></i> Infrastructure Overview</div>
                <div class="status-pill"><span class="pulse-dot"></span> Optimal</div>
            </div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="color:#2563eb;background:rgba(37,99,235,0.1);"><i class="ri-computer-line"></i></div>
                    <div class="stat-value" data-target="<?php echo (int) $totalAssets; ?>">0</div>
                    <div class="stat-label">Total Assets</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="color:#0ea5e9;background:rgba(14,165,233,0.1);"><i class="ri-calendar-check-line"></i></div>
                    <div class="stat-value" data-target="<?php echo (int) $nexcheckRequestTotal; ?>">0</div>
                    <div class="stat-label">NexCheck Requests</div>
                </div>
                <div class="stat-card-wide">
                    <div class="wide-header">
                        <div><div class="wide-label">Live from NIMS</div><div class="wide-title">NextCheck activity</div></div>
                        <div class="wide-icon"><i class="ri-shield-check-fill"></i></div>
                    </div>
                    <div class="health-bar-track"><div class="health-bar-fill" id="healthBar" data-pct="<?php echo $dbOk ? '100' : '15'; ?>"></div></div>
                    <div class="health-footer"><span>Last activity · <?php echo htmlspecialchars($lastActivityAgo, ENT_QUOTES, 'UTF-8'); ?></span><span><?php echo $dbOk ? 'Data live' : 'Database offline'; ?></span></div>
                </div>
            </div>
            <div class="activity-feed">
                <div class="feed-title">Recent Activity</div>
                <?php if (empty($recentActivities)) : ?>
                <div class="feed-item"><span class="feed-dot" style="background:#94a3b8;"></span><span class="feed-text"><?php echo $dbOk ? 'No recorded activity yet.' : 'Connect the database to show live data.'; ?></span><span class="feed-time">—</span></div>
                <?php else : ?>
                <?php foreach ($recentActivities as $row) :
                    $title = (string) ($row['title'] ?? 'Activity');
                    $ts   = (string) ($row['ts'] ?? '');
                    $kind = (string) ($row['kind'] ?? '');
                    ?>
                <div class="feed-item"><span class="feed-dot" style="background:<?php echo htmlspecialchars(index_kind_color($kind), ENT_QUOTES, 'UTF-8'); ?>;"></span><span class="feed-text"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></span><span class="feed-time"><?php echo htmlspecialchars(index_time_ago($ts), ENT_QUOTES, 'UTF-8'); ?></span></div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<section class="features-section" id="features">
    <div class="reveal">
        <div class="section-eyebrow"><span class="eyebrow-line"></span> Platform Capabilities</div>
        <h2 class="section-title">Everything you need to<br>manage IT assets</h2>
        <p class="section-desc">Built specifically for UniKL RCMP's IT environment, NIMS provides complete visibility from device onboarding to decommissioning.</p>
    </div>
    <div class="features-grid">
        <div class="feature-card feature-card-wide reveal" style="--card-hover-border:rgba(37,99,235,0.2);--card-hover-shadow:0 20px 40px rgba(37,99,235,0.08);--card-hover-bg:linear-gradient(135deg,rgba(37,99,235,0.03),transparent);">
            <div class="feature-icon-wrap" style="background:rgba(37,99,235,0.1);color:#2563eb;"><i class="ri-radar-line"></i></div>
            <div class="feature-name">Real-Time Asset Tracking</div>
            <div class="feature-desc">Monitor all IT hardware and software assets across every department — desktops, servers, network gear, and peripherals — with live status updates and location tagging.</div>
        </div>
        <div class="feature-card reveal" style="--card-hover-border:rgba(16,185,129,0.2);--card-hover-shadow:0 16px 32px rgba(16,185,129,0.08);--card-hover-bg:linear-gradient(135deg,rgba(16,185,129,0.03),transparent);">
            <div class="feature-icon-wrap" style="background:rgba(16,185,129,0.1);color:#10b981;"><i class="ri-shield-check-line"></i></div>
            <div class="feature-name">Security Compliance</div>
            <div class="feature-desc">Automated compliance checks with full audit trails. Detect unauthorized devices before they become a risk.</div>
        </div>
        <div class="feature-card reveal" style="--card-hover-border:rgba(245,158,11,0.2);--card-hover-shadow:0 16px 32px rgba(245,158,11,0.07);--card-hover-bg:linear-gradient(135deg,rgba(245,158,11,0.03),transparent);">
            <div class="feature-icon-wrap" style="background:rgba(245,158,11,0.1);color:#f59e0b;"><i class="ri-bar-chart-2-line"></i></div>
            <div class="feature-name">Lifecycle Analytics</div>
            <div class="feature-desc">Track asset age, maintenance history, and warranty expiry. Plan upgrades before failures happen.</div>
        </div>
        <div class="feature-card reveal" style="--card-hover-border:rgba(14,165,233,0.2);--card-hover-shadow:0 16px 32px rgba(14,165,233,0.07);--card-hover-bg:linear-gradient(135deg,rgba(14,165,233,0.03),transparent);">
            <div class="feature-icon-wrap" style="background:rgba(14,165,233,0.1);color:#0ea5e9;"><i class="ri-notification-3-line"></i></div>
            <div class="feature-name">Smart Alerts</div>
            <div class="feature-desc">Configurable alerts for maintenance due dates, unauthorized access attempts, and asset status changes.</div>
        </div>
        <div class="feature-card reveal" style="--card-hover-border:rgba(244,63,94,0.2);--card-hover-shadow:0 16px 32px rgba(244,63,94,0.07);--card-hover-bg:linear-gradient(135deg,rgba(244,63,94,0.03),transparent);">
            <div class="feature-icon-wrap" style="background:rgba(244,63,94,0.1);color:#f43f5e;"><i class="ri-file-chart-line"></i></div>
            <div class="feature-name">Automated Reports</div>
            <div class="feature-desc">Generate department-level asset reports for audits, procurement, and management reviews in one click.</div>
        </div>
    </div>
</section>

<section class="cta-section reveal">
    <div class="cta-text">
        <h3>Ready to take control of<br>your IT infrastructure?</h3>
        <p>Log in with your UniKL RCMP staff credentials to access the full management dashboard.</p>
    </div>
    <div class="cta-actions">
        <a href="auth/login.php" class="btn btn-primary"><i class="ri-login-circle-line"></i> IT Staff Login</a>
        <a href="#resources" class="btn btn-ghost"><i class="ri-book-open-line"></i> IT Guidelines</a>
    </div>
</section>

<footer class="footer-bar">
    <div class="footer-brand">
        <img src="public/logo-nims.png" alt="NIMS">
        <span>RCMP NIMS &copy; <?php echo date('Y'); ?> · UniKL RCMP IT Department</span>
    </div>
    <div class="footer-links">
        <a href="#">Privacy</a><a href="#">Support</a><a href="auth/login.php">Login</a>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const dot = document.getElementById('cursorDot');
    const ring = document.getElementById('cursorRing');
    let mouseX=0,mouseY=0,ringX=0,ringY=0;
    document.addEventListener('mousemove',e=>{ mouseX=e.clientX;mouseY=e.clientY; dot.style.left=mouseX+'px';dot.style.top=mouseY+'px'; });
    (function animRing(){ ringX+=(mouseX-ringX)*0.12;ringY+=(mouseY-ringY)*0.12; ring.style.left=ringX+'px';ring.style.top=ringY+'px'; requestAnimationFrame(animRing); })();
    document.querySelectorAll('a,button,.stat-card,.feature-card,.nav-logo').forEach(el=>{
        el.addEventListener('mouseenter',()=>ring.classList.add('hovering'));
        el.addEventListener('mouseleave',()=>ring.classList.remove('hovering'));
    });

    function animateCounter(el,target,duration=1800){
        let start=null;
        const step=ts=>{ if(!start)start=ts; const p=Math.min((ts-start)/duration,1); const e=1-Math.pow(1-p,3); el.textContent=Math.floor(e*target).toLocaleString(); if(p<1)requestAnimationFrame(step); else el.textContent=target.toLocaleString(); };
        requestAnimationFrame(step);
    }
    document.querySelectorAll('.stat-value[data-target]').forEach(el=>animateCounter(el,+el.dataset.target));
    const heroEp=document.getElementById('stat-endpoints'); if(heroEp)animateCounter(heroEp,<?php echo (int) $totalAssets; ?>,2200);
    setTimeout(()=>{ const b=document.getElementById('healthBar'); if(b) b.style.width=(b.dataset.pct==='100'?'100%':b.dataset.pct+'%'); },500);

    const panel=document.getElementById('tiltPanel'),visual=document.querySelector('.hero-visual');
    if(panel&&visual&&window.matchMedia('(hover:hover) and (pointer:fine)').matches){
        visual.addEventListener('mousemove',e=>{ const r=panel.getBoundingClientRect(); const rx=((e.clientY-r.top-r.height/2)/(r.height/2))*-6; const ry=((e.clientX-r.left-r.width/2)/(r.width/2))*6; panel.style.transform=`perspective(1000px) rotateX(${rx}deg) rotateY(${ry}deg) scale3d(1.02,1.02,1.02)`; panel.style.animation='none'; });
        visual.addEventListener('mouseleave',()=>{ panel.style.transform='';panel.style.animation=''; });
    }

    const obs=new IntersectionObserver(entries=>{ entries.forEach(e=>{ if(e.isIntersecting){e.target.classList.add('visible');obs.unobserve(e.target);} }); },{threshold:0.12});
    document.querySelectorAll('.reveal').forEach(el=>obs.observe(el));
    document.querySelectorAll('.feature-card').forEach((c,i)=>{ c.style.transitionDelay=`${i*80}ms`;obs.observe(c); });

    const navbar=document.querySelector('.navbar');
    window.addEventListener('scroll',()=>{ if(window.scrollY>20){navbar.style.background='rgba(255,255,255,0.95)';navbar.style.boxShadow='0 4px 24px rgba(37,99,235,0.1)';}else{navbar.style.background='rgba(255,255,255,0.75)';navbar.style.boxShadow='none';} },{passive:true});
});
</script>
</body>
</html>
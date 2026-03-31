<?php
// RCMP NIMS - UniKL RCMP NextCheck Inventory Management System
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RCMP NIMS - UniKL NextCheck Inventory Management</title>
    <link rel="icon" type="image/png" href="public/rcmp.png">
    <meta name="description" content="RCMP NIMS is a modern asset management system for the UniKL RCMP IT Department. Monitor, track, and optimize technical resources securely.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        /* ===================== CSS VARIABLES ===================== */
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-glow: rgba(37, 99, 235, 0.35);
            --secondary: #0ea5e9;
            --secondary-glow: rgba(14, 165, 233, 0.3);
            --accent: #f59e0b;
            --accent-glow: rgba(245, 158, 11, 0.3);
            --green: #10b981;
            --purple: #8b5cf6;
            --dark: #0f172a;
            --darker: #020617;
            --card-bg: rgba(15, 23, 42, 0.6);
            --card-border: rgba(255, 255, 255, 0.07);
            --card-border-hover: rgba(37, 99, 235, 0.4);
            --text-main: #f1f5f9;
            --text-muted: #64748b;
            --text-subtle: #94a3b8;
            --mono: 'JetBrains Mono', monospace;
        }

        /* ===================== RESET & BASE ===================== */
        *, *::before, *::after {
            margin: 0; padding: 0; box-sizing: border-box;
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--darker);
            color: var(--text-main);
            min-height: 100vh;
            overflow-x: hidden;
            cursor: none; /* custom cursor */
        }

        /* ===================== CUSTOM CURSOR ===================== */
        .cursor-dot {
            width: 6px; height: 6px;
            background: var(--secondary);
            border-radius: 50%;
            position: fixed; top: 0; left: 0;
            pointer-events: none; z-index: 9999;
            transform: translate(-50%, -50%);
            transition: transform 0.05s;
        }
        .cursor-ring {
            width: 32px; height: 32px;
            border: 1.5px solid rgba(14, 165, 233, 0.5);
            border-radius: 50%;
            position: fixed; top: 0; left: 0;
            pointer-events: none; z-index: 9998;
            transform: translate(-50%, -50%);
            transition: all 0.15s ease;
        }
        .cursor-ring.hovering {
            width: 48px; height: 48px;
            border-color: var(--secondary);
            background: rgba(14, 165, 233, 0.08);
        }

        /* ===================== BACKGROUND ===================== */
        .page-bg {
            position: fixed; inset: 0;
            z-index: -3;
            background-image: url('public/bgm.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }

        .bg-overlay {
            position: fixed; inset: 0;
            z-index: -2;
            background: 
                radial-gradient(ellipse 80% 60% at 20% 10%, rgba(37, 99, 235, 0.12) 0%, transparent 60%),
                radial-gradient(ellipse 70% 50% at 80% 80%, rgba(14, 165, 233, 0.08) 0%, transparent 60%),
                linear-gradient(170deg, rgba(2, 6, 23, 0.97) 0%, rgba(15, 23, 42, 0.92) 100%);
            backdrop-filter: blur(12px);
        }

        /* Animated grid lines */
        .grid-bg {
            position: fixed; inset: 0;
            z-index: -1;
            background-image: 
                linear-gradient(rgba(37, 99, 235, 0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(37, 99, 235, 0.04) 1px, transparent 1px);
            background-size: 60px 60px;
            animation: gridDrift 20s linear infinite;
        }

        @keyframes gridDrift {
            0% { background-position: 0 0; }
            100% { background-position: 60px 60px; }
        }

        /* ===================== NAVBAR ===================== */
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 5%;
            height: 72px;
            background: rgba(2, 6, 23, 0.5);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            position: sticky; top: 0; z-index: 200;
            animation: navSlideDown 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes navSlideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .nav-brand { display: flex; align-items: center; gap: 1rem; }

        .nav-logo {
            height: 42px; object-fit: contain;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.4));
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .nav-logo:hover { transform: scale(1.08) rotate(-2deg); }

        /* Live indicator in navbar */
        .nav-live {
            display: flex; align-items: center; gap: 6px;
            font-family: var(--mono); font-size: 0.7rem;
            color: var(--green); letter-spacing: 1px;
            text-transform: uppercase; padding: 4px 10px;
            border: 1px solid rgba(16, 185, 129, 0.2);
            border-radius: 4px; background: rgba(16, 185, 129, 0.05);
        }
        .live-dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 8px var(--green);
            animation: blink 1.5s ease-in-out infinite;
        }
        @keyframes blink {
            0%, 100% { opacity: 1; } 50% { opacity: 0.3; }
        }

        .nav-links {
            display: flex; gap: 2rem; align-items: center;
        }

        .nav-links a {
            color: var(--text-subtle);
            text-decoration: none;
            font-weight: 500; font-size: 0.9rem;
            transition: color 0.2s;
            position: relative;
        }
        .nav-links a::after {
            content: '';
            position: absolute; bottom: -4px; left: 0;
            width: 0; height: 1px;
            background: var(--secondary);
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .nav-links a:hover { color: var(--text-main); }
        .nav-links a:hover::after { width: 100%; }

        .btn-login-nav {
            padding: 0.55rem 1.5rem;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none; color: white;
            font-family: 'Outfit', sans-serif;
            font-weight: 600; font-size: 0.9rem;
            cursor: none; transition: all 0.3s;
            box-shadow: 0 0 20px rgba(37, 99, 235, 0.3);
            position: relative; overflow: hidden;
        }
        .btn-login-nav::before {
            content: '';
            position: absolute; top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        .btn-login-nav:hover::before { left: 100%; }
        .btn-login-nav:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.5);
        }

        /* ===================== HERO SECTION ===================== */
        .hero {
            display: flex;
            align-items: center;
            padding: 5rem 5% 4rem;
            gap: 5rem;
            min-height: calc(100vh - 72px);
            position: relative;
        }

        .hero-content {
            flex: 1; max-width: 600px;
            opacity: 0; transform: translateY(40px);
            animation: slideUpFade 0.9s cubic-bezier(0.16, 1, 0.3, 1) 0.1s forwards;
        }

        @keyframes slideUpFade {
            to { opacity: 1; transform: translateY(0); }
        }

        .hero-logo {
            display: block; height: 140px;
            object-fit: contain; margin-bottom: 1.75rem;
            filter: drop-shadow(0 8px 24px rgba(0,0,0,0.5));
            transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .hero-logo:hover { transform: scale(1.05); }

        /* System tag */
        .system-tag {
            display: inline-flex; align-items: center; gap: 8px;
            font-family: var(--mono); font-size: 0.72rem;
            color: var(--secondary); letter-spacing: 2px;
            text-transform: uppercase; margin-bottom: 1.5rem;
            padding: 6px 14px;
            border: 1px solid rgba(14, 165, 233, 0.2);
            border-radius: 4px;
            background: rgba(14, 165, 233, 0.05);
        }
        .system-tag i { font-size: 0.85rem; }

        .hero-headline {
            font-size: 5.5rem;
            font-weight: 900;
            line-height: 0.95;
            letter-spacing: -3px;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #ffffff 30%, #94a3b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Animated gradient text accent */
        .headline-accent {
            display: block;
            background: linear-gradient(90deg, var(--primary), var(--secondary), var(--accent), var(--primary));
            background-size: 300%;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: gradientShift 4s linear infinite;
            font-size: 2.6rem;
            font-weight: 600;
            letter-spacing: -1px;
            margin-top: 0.5rem;
            line-height: 1.2;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .hero-desc {
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem; font-weight: 400;
            color: var(--text-muted); line-height: 1.8;
            margin: 1.75rem 0 2.5rem;
            max-width: 480px;
        }

        .cta-group { display: flex; gap: 1rem; flex-wrap: wrap; }

        .btn {
            padding: 0.9rem 2rem;
            border-radius: 10px;
            font-family: 'Outfit', sans-serif;
            font-weight: 600; font-size: 0.95rem;
            cursor: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px;
            position: relative; overflow: hidden;
        }
        .btn::before {
            content: '';
            position: absolute; top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(120deg, transparent, rgba(255,255,255,0.15), transparent);
            transition: left 0.5s;
        }
        .btn:hover::before { left: 100%; }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white; border: none;
            box-shadow: 0 8px 24px var(--primary-glow), 0 0 0 1px rgba(37,99,235,0.3);
        }
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 36px var(--primary-glow), 0 0 0 1px rgba(37,99,235,0.5);
        }

        .btn-ghost {
            background: transparent;
            border: 1px solid var(--card-border);
            color: var(--text-subtle);
            backdrop-filter: blur(8px);
        }
        .btn-ghost:hover {
            border-color: rgba(255,255,255,0.2);
            color: var(--text-main);
            transform: translateY(-3px);
            background: rgba(255,255,255,0.04);
        }

        /* Mini stat pills below CTA */
        .hero-stats {
            display: flex; gap: 1.5rem;
            margin-top: 2.5rem; flex-wrap: wrap;
        }
        .hero-stat {
            display: flex; align-items: center; gap: 8px;
            font-size: 0.85rem; color: var(--text-muted);
        }
        .hero-stat span {
            font-family: var(--mono); font-weight: 600;
            color: var(--text-main); font-size: 1rem;
        }
        .hero-stat i { color: var(--secondary); font-size: 1rem; }

        /* ===================== HERO VISUAL PANEL ===================== */
        .hero-visual {
            flex: 1; display: flex;
            justify-content: center; align-items: center;
            position: relative;
            opacity: 0;
            animation: fadeIn 1.2s ease-out 0.4s forwards;
        }

        @keyframes fadeIn {
            to { opacity: 1; }
        }

        /* Background glow orbs */
        .orb {
            position: absolute; border-radius: 50%;
            filter: blur(80px); pointer-events: none;
        }
        .orb-1 {
            width: 380px; height: 380px;
            background: var(--primary); opacity: 0.18;
            top: -60px; right: -80px;
            animation: orbFloat 14s ease-in-out infinite alternate;
        }
        .orb-2 {
            width: 300px; height: 300px;
            background: var(--purple); opacity: 0.14;
            bottom: -40px; left: -60px;
            animation: orbFloat 18s ease-in-out infinite alternate-reverse;
        }
        .orb-3 {
            width: 200px; height: 200px;
            background: var(--secondary); opacity: 0.12;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            animation: orbPulse 10s ease-in-out infinite;
        }

        @keyframes orbFloat {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(30px, -30px) scale(1.1); }
        }
        @keyframes orbPulse {
            0%, 100% { transform: translate(-50%, -50%) scale(0.85); }
            50% { transform: translate(-50%, -50%) scale(1.15); }
        }

        /* Main glass panel */
        .glass-panel {
            width: 100%; max-width: 500px;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid var(--card-border);
            border-radius: 24px; padding: 2rem;
            backdrop-filter: blur(30px);
            box-shadow: 
                0 40px 80px -20px rgba(0,0,0,0.7),
                inset 0 1px 0 rgba(255,255,255,0.08);
            position: relative; z-index: 2;
            animation: panelFloat 9s ease-in-out infinite;
            transition: transform 0.3s ease;
        }

        @keyframes panelFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-14px); }
        }

        /* Top accent line on panel */
        .glass-panel::before {
            content: '';
            position: absolute; top: 0; left: 15%; right: 15%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--secondary), transparent);
            border-radius: 1px;
        }

        .panel-topbar {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 1.5rem; padding-bottom: 1.25rem;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .panel-title {
            display: flex; align-items: center; gap: 8px;
            font-size: 0.95rem; font-weight: 600; color: white;
        }
        .panel-title i { color: var(--secondary); }

        .status-pill {
            display: flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 6px;
            font-size: 0.75rem; font-weight: 600;
            font-family: var(--mono); letter-spacing: 0.5px;
            color: var(--green);
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.18);
        }
        .pulse-dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 10px var(--green);
            animation: pulse 2s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.4); opacity: 0.6; }
        }

        /* Stats grid */
        .stats-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
            margin-bottom: 1rem;
        }

        .stat-card {
            background: rgba(255,255,255,0.025);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 14px; padding: 1.25rem;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative; overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.04), transparent);
            opacity: 0; transition: opacity 0.3s;
        }
        .stat-card:hover { 
            border-color: var(--card-border-hover);
            transform: translateY(-4px);
            box-shadow: 0 12px 30px -8px rgba(37, 99, 235, 0.2);
        }
        .stat-card:hover::before { opacity: 1; }

        .stat-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 2.2rem; font-weight: 800;
            line-height: 1; color: white; margin-bottom: 4px;
            font-family: var(--mono);
            font-variant-numeric: tabular-nums;
        }

        .stat-label {
            font-size: 0.78rem; color: var(--text-muted);
            font-weight: 500; letter-spacing: 0.3px;
        }

        /* Wide stat card */
        .stat-card-wide {
            grid-column: 1 / -1;
            background: rgba(255,255,255,0.025);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 14px; padding: 1.25rem;
            transition: all 0.35s ease;
        }
        .stat-card-wide:hover {
            border-color: rgba(16, 185, 129, 0.3);
            box-shadow: 0 8px 24px -8px rgba(16, 185, 129, 0.15);
        }

        .wide-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 0.75rem;
        }
        .wide-label {
            font-size: 0.78rem; color: var(--text-muted);
            font-family: var(--mono); letter-spacing: 1px; text-transform: uppercase;
        }
        .wide-title {
            font-size: 1rem; font-weight: 600; color: white; margin-top: 2px;
        }
        .wide-icon { font-size: 2rem; color: var(--green); line-height: 1; }

        .health-bar-track {
            width: 100%; height: 6px;
            background: rgba(0,0,0,0.4);
            border-radius: 3px; overflow: hidden;
            box-shadow: inset 0 1px 4px rgba(0,0,0,0.5);
            margin-top: 0.75rem;
        }
        .health-bar-fill {
            width: 0%; height: 100%;
            background: linear-gradient(90deg, var(--green), #34d399, var(--secondary));
            border-radius: 3px;
            box-shadow: 0 0 12px rgba(16, 185, 129, 0.5);
            transition: width 2s cubic-bezier(0.22, 1, 0.36, 1) 0.8s;
        }
        .health-footer {
            display: flex; justify-content: space-between;
            margin-top: 6px; font-size: 0.75rem;
            font-family: var(--mono);
        }
        .health-footer span:first-child { color: var(--text-muted); }
        .health-footer span:last-child { color: var(--green); font-weight: 600; }

        /* Activity feed inside panel */
        .activity-feed {
            margin-top: 1rem;
            border-top: 1px solid rgba(255,255,255,0.05);
            padding-top: 1rem;
        }
        .feed-title {
            font-size: 0.72rem; color: var(--text-muted);
            font-family: var(--mono); letter-spacing: 1.5px;
            text-transform: uppercase; margin-bottom: 0.75rem;
        }
        .feed-item {
            display: flex; align-items: center; gap: 10px;
            padding: 7px 0; font-size: 0.8rem;
            border-bottom: 1px solid rgba(255,255,255,0.03);
            opacity: 0;
            animation: feedItemIn 0.4s ease forwards;
        }
        .feed-item:last-child { border-bottom: none; }
        .feed-item:nth-child(1) { animation-delay: 1.2s; }
        .feed-item:nth-child(2) { animation-delay: 1.5s; }
        .feed-item:nth-child(3) { animation-delay: 1.8s; }
        @keyframes feedItemIn {
            from { opacity: 0; transform: translateX(-8px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .feed-dot {
            width: 6px; height: 6px; border-radius: 50%;
            flex-shrink: 0;
        }
        .feed-text { color: var(--text-subtle); flex: 1; line-height: 1.3; }
        .feed-time {
            font-family: var(--mono); font-size: 0.7rem;
            color: var(--text-muted); white-space: nowrap;
        }

        /* ===================== FEATURES SECTION ===================== */
        .features-section {
            padding: 6rem 5%; position: relative;
        }

        .section-eyebrow {
            display: flex; align-items: center; gap: 10px;
            font-family: var(--mono); font-size: 0.72rem;
            color: var(--secondary); letter-spacing: 2px;
            text-transform: uppercase; margin-bottom: 1rem;
        }
        .eyebrow-line {
            height: 1px; width: 40px;
            background: var(--secondary); opacity: 0.5;
        }

        .section-title {
            font-size: 2.8rem; font-weight: 800;
            line-height: 1.1; letter-spacing: -1.5px;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, white 40%, #64748b 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .section-desc {
            font-size: 1rem; color: var(--text-muted);
            max-width: 500px; line-height: 1.7;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.25rem; margin-top: 3.5rem;
        }

        .feature-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px; padding: 2rem;
            backdrop-filter: blur(20px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative; overflow: hidden;
            opacity: 0; transform: translateY(30px);
        }

        /* Glow on hover — color injected via inline style */
        .feature-card::after {
            content: '';
            position: absolute; inset: 0;
            background: var(--card-hover-bg, transparent);
            opacity: 0; transition: opacity 0.4s;
            border-radius: 20px;
        }

        .feature-card:hover {
            transform: translateY(-6px);
            border-color: var(--card-hover-border, rgba(255,255,255,0.12));
            box-shadow: var(--card-hover-shadow, 0 20px 40px -15px rgba(0,0,0,0.4));
        }
        .feature-card:hover::after { opacity: 1; }

        .feature-icon-wrap {
            width: 52px; height: 52px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; margin-bottom: 1.25rem;
            position: relative; z-index: 1;
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .feature-card:hover .feature-icon-wrap {
            transform: scale(1.15) rotate(-5deg);
        }

        .feature-name {
            font-size: 1.1rem; font-weight: 700;
            color: white; margin-bottom: 0.6rem;
            position: relative; z-index: 1;
        }

        .feature-desc {
            font-size: 0.88rem; color: var(--text-muted);
            line-height: 1.65; position: relative; z-index: 1;
        }

        /* Feature card spanning 2 cols */
        .feature-card-wide {
            grid-column: span 2;
        }

        /* ===================== FOOTER / CTA SECTION ===================== */
        .cta-section {
            padding: 5rem 5%;
            display: flex; align-items: center;
            justify-content: space-between; gap: 3rem;
            flex-wrap: wrap;
            border-top: 1px solid rgba(255,255,255,0.05);
            position: relative;
        }

        .cta-section::before {
            content: '';
            position: absolute; top: 0; left: 50%; transform: translateX(-50%);
            width: 600px; height: 1px;
            background: linear-gradient(90deg, transparent, var(--primary), var(--secondary), transparent);
        }

        .cta-text h3 {
            font-size: 2rem; font-weight: 800;
            letter-spacing: -1px; margin-bottom: 0.5rem;
        }
        .cta-text p {
            font-size: 0.95rem; color: var(--text-muted); max-width: 380px;
        }

        .cta-actions { display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; }

        /* Footer bar */
        .footer-bar {
            padding: 1.5rem 5%;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 1rem;
            border-top: 1px solid rgba(255,255,255,0.04);
            font-size: 0.8rem; color: var(--text-muted);
        }

        .footer-brand { display: flex; align-items: center; gap: 8px; }
        .footer-brand img { height: 24px; opacity: 0.6; filter: grayscale(1); }

        .footer-links { display: flex; gap: 1.5rem; }
        .footer-links a {
            color: var(--text-muted); text-decoration: none;
            transition: color 0.2s; font-family: var(--mono); font-size: 0.72rem;
        }
        .footer-links a:hover { color: var(--text-main); }

        /* ===================== SCROLL ANIMATIONS ===================== */
        .reveal {
            opacity: 0; transform: translateY(24px);
            transition: opacity 0.7s ease, transform 0.7s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .reveal.visible { opacity: 1; transform: none; }

        /* Staggered feature cards */
        .feature-card.visible { opacity: 1; transform: translateY(0); }
        .feature-card { transition: opacity 0.6s ease, transform 0.6s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.4s, box-shadow 0.4s; }

        /* ===================== RESPONSIVE ===================== */
        @media (max-width: 1024px) {
            .hero-headline { font-size: 4rem; }
            .features-grid { grid-template-columns: 1fr 1fr; }
            .feature-card-wide { grid-column: span 1; }
        }

        @media (max-width: 768px) {
            .hero { flex-direction: column; text-align: center; padding: 3rem 5%; gap: 3rem; }
            .hero-content { max-width: 100%; display: flex; flex-direction: column; align-items: center; }
            .hero-desc { margin-left: auto; margin-right: auto; }
            .hero-headline { font-size: 3.5rem; }
            .nav-links { display: none; }
            .hero-visual { width: 100%; }
            .features-grid { grid-template-columns: 1fr; }
            .cta-section { flex-direction: column; }
            .section-title { font-size: 2rem; }
        }

        @media (max-width: 480px) {
            .hero-headline { font-size: 2.8rem; }
            .headline-accent { font-size: 1.8rem; }
            .cta-group, .cta-actions { flex-direction: column; width: 100%; }
            .btn { width: 100%; justify-content: center; }
            body { cursor: auto; }
            .cursor-dot, .cursor-ring { display: none; }
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: var(--darker); }
        ::-webkit-scrollbar-thumb { background: rgba(37, 99, 235, 0.4); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--primary); }
    </style>
</head>
<body>

<!-- Custom Cursor -->
<div class="cursor-dot" id="cursorDot"></div>
<div class="cursor-ring" id="cursorRing"></div>

<!-- Background -->
<div class="page-bg"></div>
<div class="bg-overlay"></div>
<div class="grid-bg"></div>

<!-- ===================== NAVBAR ===================== -->
<nav class="navbar">
    <div class="nav-brand">
        <img src="public/logo-nims.png" alt="NextCheck NIMS" class="nav-logo">
        <div class="nav-live">
            <span class="live-dot"></span> SYSTEM ONLINE
        </div>
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

<!-- ===================== HERO ===================== -->
<main class="hero">
    <div class="hero-content">
        <img src="public/unikl-official.png" alt="UniKL RCMP Logo" class="hero-logo">

        <div class="system-tag">
            <i class="ri-shield-keyhole-line"></i>
            IT Dept · Asset Management Platform
        </div>

        <h1 class="hero-headline">
            RCMP<br>NIMS
        </h1>
        <span class="headline-accent">NextCheck Inventory Management</span>

        <p class="hero-desc">
            A secure, centralized infrastructure tracking platform built for the UniKL RCMP IT Department — giving full visibility and control over every technical asset in real time.
        </p>

        <div class="cta-group">
            <a href="auth/login.php" class="btn btn-primary">
                <i class="ri-login-circle-line"></i> System Access
            </a>
            <a href="#features" class="btn btn-ghost">
                <i class="ri-article-line"></i> Learn More
            </a>
        </div>

        <div class="hero-stats">
            <div class="hero-stat">
                <i class="ri-computer-line"></i>
                <span id="stat-endpoints">0</span> Assets Tracked
            </div>
            <div class="hero-stat">
                <i class="ri-shield-check-line"></i>
                <span>100%</span> Secure
            </div>
            <div class="hero-stat">
                <i class="ri-time-line"></i>
                <span>24/7</span> Monitored
            </div>
        </div>
    </div>

    <!-- Right: Glass Panel -->
    <div class="hero-visual">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>

        <div class="glass-panel" id="tiltPanel">
            <div class="panel-topbar">
                <div class="panel-title">
                    <i class="ri-dashboard-3-line"></i> Infrastructure Overview
                </div>
                <div class="status-pill">
                    <span class="pulse-dot"></span> Optimal
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="color:#3b82f6; background:rgba(59,130,246,0.12);">
                        <i class="ri-computer-line"></i>
                    </div>
                    <div class="stat-value" data-target="1542">0</div>
                    <div class="stat-label">Total Endpoints</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="color:#f59e0b; background:rgba(245,158,11,0.12);">
                        <i class="ri-tools-fill"></i>
                    </div>
                    <div class="stat-value" data-target="28">0</div>
                    <div class="stat-label">Action Required</div>
                </div>

                <div class="stat-card-wide">
                    <div class="wide-header">
                        <div>
                            <div class="wide-label">Network Integrity Protocol</div>
                            <div class="wide-title">NextCheck Scanning Engine</div>
                        </div>
                        <div class="wide-icon"><i class="ri-shield-check-fill"></i></div>
                    </div>
                    <div class="health-bar-track">
                        <div class="health-bar-fill" id="healthBar"></div>
                    </div>
                    <div class="health-footer">
                        <span>Scan complete · 3 mins ago</span>
                        <span>100% Secure</span>
                    </div>
                </div>
            </div>

            <!-- Activity Feed -->
            <div class="activity-feed">
                <div class="feed-title">Recent Activity</div>
                <div class="feed-item">
                    <span class="feed-dot" style="background:#3b82f6;"></span>
                    <span class="feed-text">Asset #1193 checked in — Lab PC Unit</span>
                    <span class="feed-time">2m ago</span>
                </div>
                <div class="feed-item">
                    <span class="feed-dot" style="background:#f59e0b;"></span>
                    <span class="feed-text">Maintenance flag raised — Switch B4</span>
                    <span class="feed-time">18m ago</span>
                </div>
                <div class="feed-item">
                    <span class="feed-dot" style="background:#10b981;"></span>
                    <span class="feed-text">Full network scan completed</span>
                    <span class="feed-time">1h ago</span>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- ===================== FEATURES SECTION ===================== -->
<section class="features-section" id="features">
    <div class="reveal">
        <div class="section-eyebrow">
            <span class="eyebrow-line"></span>
            Platform Capabilities
        </div>
        <h2 class="section-title">Everything you need to<br>manage IT assets</h2>
        <p class="section-desc">Built specifically for UniKL RCMP's IT environment, NIMS provides complete visibility from device onboarding to decommissioning.</p>
    </div>

    <div class="features-grid">

        <div class="feature-card feature-card-wide reveal"
            style="
                --card-hover-border: rgba(37,99,235,0.35);
                --card-hover-shadow: 0 24px 48px -16px rgba(37,99,235,0.25);
                --card-hover-bg: linear-gradient(135deg, rgba(37,99,235,0.05), transparent);
            ">
            <div class="feature-icon-wrap" style="background:rgba(37,99,235,0.12); color:#3b82f6;">
                <i class="ri-radar-line"></i>
            </div>
            <div class="feature-name">Real-Time Asset Tracking</div>
            <div class="feature-desc">Monitor all IT hardware and software assets across every department — desktops, servers, network gear, and peripherals — with live status updates and location tagging.</div>
        </div>

        <div class="feature-card reveal"
            style="
                --card-hover-border: rgba(16,185,129,0.3);
                --card-hover-shadow: 0 20px 40px -15px rgba(16,185,129,0.2);
                --card-hover-bg: linear-gradient(135deg, rgba(16,185,129,0.05), transparent);
            ">
            <div class="feature-icon-wrap" style="background:rgba(16,185,129,0.12); color:#10b981;">
                <i class="ri-shield-check-line"></i>
            </div>
            <div class="feature-name">Security Compliance</div>
            <div class="feature-desc">Automated compliance checks with full audit trails. Detect unauthorized devices before they become a risk.</div>
        </div>

        <div class="feature-card reveal"
            style="
                --card-hover-border: rgba(245,158,11,0.3);
                --card-hover-shadow: 0 20px 40px -15px rgba(245,158,11,0.15);
                --card-hover-bg: linear-gradient(135deg, rgba(245,158,11,0.05), transparent);
            ">
            <div class="feature-icon-wrap" style="background:rgba(245,158,11,0.12); color:#f59e0b;">
                <i class="ri-bar-chart-2-line"></i>
            </div>
            <div class="feature-name">Lifecycle Analytics</div>
            <div class="feature-desc">Track asset age, maintenance history, and warranty expiry. Plan upgrades before failures happen.</div>
        </div>

        <div class="feature-card reveal"
            style="
                --card-hover-border: rgba(139,92,246,0.3);
                --card-hover-shadow: 0 20px 40px -15px rgba(139,92,246,0.2);
                --card-hover-bg: linear-gradient(135deg, rgba(139,92,246,0.05), transparent);
            ">
            <div class="feature-icon-wrap" style="background:rgba(139,92,246,0.12); color:#8b5cf6;">
                <i class="ri-qr-code-line"></i>
            </div>
            <div class="feature-name">QR Code Check-In</div>
            <div class="feature-desc">Scan assets in and out with QR tags. Instantly log movement, assignments, and custody transfers.</div>
        </div>

        <div class="feature-card reveal"
            style="
                --card-hover-border: rgba(14,165,233,0.3);
                --card-hover-shadow: 0 20px 40px -15px rgba(14,165,233,0.2);
                --card-hover-bg: linear-gradient(135deg, rgba(14,165,233,0.05), transparent);
            ">
            <div class="feature-icon-wrap" style="background:rgba(14,165,233,0.12); color:#0ea5e9;">
                <i class="ri-notification-3-line"></i>
            </div>
            <div class="feature-name">Smart Alerts</div>
            <div class="feature-desc">Configurable alerts for maintenance due dates, unauthorized access attempts, and asset status changes.</div>
        </div>

        <div class="feature-card reveal"
            style="
                --card-hover-border: rgba(244,63,94,0.3);
                --card-hover-shadow: 0 20px 40px -15px rgba(244,63,94,0.15);
                --card-hover-bg: linear-gradient(135deg, rgba(244,63,94,0.05), transparent);
            ">
            <div class="feature-icon-wrap" style="background:rgba(244,63,94,0.12); color:#f43f5e;">
                <i class="ri-file-chart-line"></i>
            </div>
            <div class="feature-name">Automated Reports</div>
            <div class="feature-desc">Generate department-level asset reports for audits, procurement, and management reviews in one click.</div>
        </div>

    </div>
</section>

<!-- ===================== BOTTOM CTA ===================== -->
<section class="cta-section reveal">
    <div class="cta-text">
        <h3>Ready to take control of<br>your IT infrastructure?</h3>
        <p>Log in with your UniKL RCMP staff credentials to access the full management dashboard.</p>
    </div>
    <div class="cta-actions">
        <a href="auth/login.php" class="btn btn-primary">
            <i class="ri-login-circle-line"></i> IT Staff Login
        </a>
        <a href="#resources" class="btn btn-ghost">
            <i class="ri-book-open-line"></i> IT Guidelines
        </a>
    </div>
</section>

<!-- ===================== FOOTER ===================== -->
<footer class="footer-bar">
    <div class="footer-brand">
        <img src="public/logo-nims.png" alt="NIMS">
        <span>RCMP NIMS &copy; <?php echo date('Y'); ?> · UniKL RCMP IT Department</span>
    </div>
    <div class="footer-links">
        <a href="#">Privacy</a>
        <a href="#">Support</a>
        <a href="auth/login.php">Login</a>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {

    // ── Custom Cursor ──────────────────────────────────────────────
    const dot = document.getElementById('cursorDot');
    const ring = document.getElementById('cursorRing');
    let mouseX = 0, mouseY = 0, ringX = 0, ringY = 0;

    document.addEventListener('mousemove', e => {
        mouseX = e.clientX; mouseY = e.clientY;
        dot.style.left = mouseX + 'px';
        dot.style.top  = mouseY + 'px';
    });

    // Smooth ring follow
    function animRing() {
        ringX += (mouseX - ringX) * 0.12;
        ringY += (mouseY - ringY) *.12;
        ring.style.left = ringX + 'px';
        ring.style.top  = ringY + 'px';
        requestAnimationFrame(animRing);
    }
    animRing();

    // Hover effect on interactive elements
    document.querySelectorAll('a, button, .stat-card, .feature-card, .nav-logo').forEach(el => {
        el.addEventListener('mouseenter', () => ring.classList.add('hovering'));
        el.addEventListener('mouseleave', () => ring.classList.remove('hovering'));
    });

    // ── Counter Animation ──────────────────────────────────────────
    function animateCounter(el, target, duration = 1800) {
        let start = null;
        const step = (timestamp) => {
            if (!start) start = timestamp;
            const progress = Math.min((timestamp - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
            el.textContent = Math.floor(eased * target).toLocaleString();
            if (progress < 1) requestAnimationFrame(step);
            else el.textContent = target.toLocaleString();
        };
        requestAnimationFrame(step);
    }

    // Panel stat counters
    document.querySelectorAll('.stat-value[data-target]').forEach(el => {
        animateCounter(el, +el.dataset.target);
    });

    // Hero mini stat counter
    const heroEndpoints = document.getElementById('stat-endpoints');
    if (heroEndpoints) animateCounter(heroEndpoints, 1542, 2200);

    // Health bar
    setTimeout(() => {
        const bar = document.getElementById('healthBar');
        if (bar) bar.style.width = '100%';
    }, 500);

    // ── 3D Tilt on Panel ──────────────────────────────────────────
    const panel = document.getElementById('tiltPanel');
    const visual = document.querySelector('.hero-visual');

    if (panel && visual && window.matchMedia('(hover:hover) and (pointer:fine)').matches) {
        visual.addEventListener('mousemove', e => {
            const r = panel.getBoundingClientRect();
            const x = e.clientX - r.left - r.width / 2;
            const y = e.clientY - r.top - r.height / 2;
            const rx = (y / (r.height / 2)) * -6;
            const ry = (x / (r.width  / 2)) *  6;
            panel.style.transform = `perspective(1000px) rotateX(${rx}deg) rotateY(${ry}deg) scale3d(1.02,1.02,1.02)`;
            panel.style.animation = 'none'; // pause float during tilt
        });

        visual.addEventListener('mouseleave', () => {
            panel.style.transform = '';
            panel.style.animation = '';
        });
    }

    // ── Scroll Reveal ─────────────────────────────────────────────
    const reveals = document.querySelectorAll('.reveal');
    const featureCards = document.querySelectorAll('.feature-card');

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });

    reveals.forEach(el => observer.observe(el));

    // Stagger feature cards
    featureCards.forEach((card, i) => {
        card.style.transitionDelay = `${i * 80}ms`;
        observer.observe(card);
    });

    // ── Typewriter effect on hero tag ─────────────────────────────
    const tag = document.querySelector('.system-tag');
    if (tag) {
        const originalText = tag.textContent.trim();
        tag.style.opacity = '0';
        setTimeout(() => { tag.style.opacity = '1'; }, 300);
    }

    // ── Navbar scroll shadow ──────────────────────────────────────
    const navbar = document.querySelector('.navbar');
    window.addEventListener('scroll', () => {
        if (window.scrollY > 20) {
            navbar.style.background = 'rgba(2,6,23,0.75)';
            navbar.style.boxShadow = '0 8px 32px rgba(0,0,0,0.4)';
        } else {
            navbar.style.background = 'rgba(2,6,23,0.5)';
            navbar.style.boxShadow = 'none';
        }
    }, { passive: true });

});
</script>
</body>
</html>
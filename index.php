<?php
// RCMP NIMS - UniKL RCMP NextCheck Inventory Management System
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RCMP NIMS - UniKL NextCheck Inventory Management</title>
    <meta name="description" content="RCMP NIMS is a modern asset management system for the UniKL RCMP IT Department. Monitor, track, and optimize technical resources securely.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --secondary: #0ea5e9;
            --accent: #f59e0b; /* UniKL usually uses bits of gold/yellow */
            --dark: #0f172a;
            --darker: #020617;
            --light: #f8fafc;
            --glass-bg: rgba(15, 23, 42, 0.55);
            --glass-border: rgba(255, 255, 255, 0.08);
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--darker);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            position: relative;
        }

        /* Background Setup */
        .page-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            background-image: url('public/bgm.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }

        .bg-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            /* Deep gradient overlay to ensure text readability while letting bg seep through */
            background: linear-gradient(135deg, rgba(2, 6, 23, 0.95) 0%, rgba(15, 23, 42, 0.85) 100%);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        /* Navbar */
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 5%;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--glass-border);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .nav-logo {
            height: 45px;
            object-fit: contain;
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.3));
            transition: transform 0.3s ease;
        }

        .nav-logo:hover {
            transform: scale(1.05);
        }

        .nav-links {
            display: flex;
            gap: 2.5rem;
            align-items: center;
        }

        .nav-links a {
            color: var(--text-main);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            position: relative;
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0%;
            height: 2px;
            background: var(--secondary);
            transition: width 0.3s ease;
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        .nav-links a:hover {
            color: var(--secondary);
        }

        .btn-login-nav {
            padding: 0.6rem 1.75rem;
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: white;
            font-weight: 600;
            font-family: inherit;
            transition: all 0.3s ease;
            cursor: pointer;
            backdrop-filter: blur(5px);
        }

        .btn-login-nav:hover {
            background: white;
            color: var(--darker);
            box-shadow: 0 0 15px rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        /* Main Content */
        .hero {
            flex: 1;
            display: flex;
            align-items: center;
            padding: 4rem 5%;
            gap: 4rem;
            position: relative;
            z-index: 10;
        }

        .hero-content {
            flex: 1;
            max-width: 650px;
            animation: slideUp 1s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            transform: translateY(30px);
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1.25rem;
            background: rgba(14, 165, 233, 0.1);
            border: 1px solid rgba(14, 165, 233, 0.25);
            border-radius: 50px;
            color: var(--secondary);
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 1.75rem;
            box-shadow: 0 4px 15px rgba(14, 165, 233, 0.05);
        }

        .hero-logo {
            display: block;
            height: 160px;
            object-fit: contain;
            margin-bottom: 1.5rem;
            filter: drop-shadow(0 8px 16px rgba(0,0,0,0.5));
            transition: transform 0.4s ease;
        }

        .hero-logo:hover {
            transform: perspective(400px) translateZ(20px);
        }

        h1 {
            font-size: 4.5rem;
            font-weight: 800;
            line-height: 1.05;
            margin-bottom: 1rem;
            background: linear-gradient(to right, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -1px;
        }

        h2 {
            font-size: 1.85rem;
            font-weight: 400;
            color: var(--text-main);
            margin-bottom: 1.75rem;
            line-height: 1.3;
        }

        .hero-desc {
            font-family: 'Inter', sans-serif;
            font-size: 1.15rem;
            color: var(--text-muted);
            line-height: 1.7;
            margin-bottom: 2.75rem;
            max-width: 90%;
        }

        .cta-group {
            display: flex;
            gap: 1.25rem;
        }

        .btn {
            padding: 1rem 2.25rem;
            border-radius: 14px;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            font-size: 1.05rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(120deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: all 0.5s ease;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            color: white;
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px -5px rgba(37, 99, 235, 0.5);
            filter: brightness(1.1);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: white;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        }

        /* Glass Visual Widget */
        .hero-visual {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            animation: fadeIn 1.2s ease-in-out forwards 0.3s;
            opacity: 0;
        }

        .glass-panel {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 28px;
            padding: 2.5rem;
            width: 100%;
            max-width: 520px;
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.6), inset 0 1px 0 0 rgba(255,255,255,0.1);
            position: relative;
            z-index: 2;
            transition: transform 0.3s ease;
        }

        /* Floating animation for the panel */
        .glass-panel {
            animation: float 8s ease-in-out infinite;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.25rem;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding-bottom: 1.25rem;
        }

        .panel-title {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: white;
        }

        .status-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            border-radius: 50px;
            font-size: 0.85rem;
            color: #10b981;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            box-shadow: 0 0 12px #10b981;
            animation: pulse 2s infinite;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            padding: 1.75rem;
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(145deg, rgba(255,255,255,0.05) 0%, transparent 100%);
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .stat-card:hover {
            background: rgba(255, 255, 255, 0.04);
            transform: translateY(-6px);
            border-color: rgba(255, 255, 255, 0.1);
            box-shadow: 0 15px 30px -10px rgba(0,0,0,0.3);
        }

        .stat-card:hover::after {
            opacity: 1;
        }

        .stat-icon {
            font-size: 1.75rem;
            margin-bottom: 1.25rem;
            color: var(--secondary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            background: rgba(14, 165, 233, 0.1);
            border-radius: 14px;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            line-height: 1;
            color: white;
            font-feature-settings: "tnum";
            font-variant-numeric: tabular-nums;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            font-family: 'Inter', sans-serif;
            font-weight: 500;
        }

        .health-bar-container {
            width: 100%;
            height: 8px;
            background: rgba(0,0,0,0.3);
            border-radius: 4px;
            margin-top: 1.25rem;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.5);
        }

        .health-bar {
            width: 0%;
            height: 100%;
            background: linear-gradient(90deg, #10b981, #34d399);
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.5);
            transition: width 1.5s cubic-bezier(0.22, 1, 0.36, 1);
        }

        /* Decorative glowing orbs behind the glass */
        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 1;
            opacity: 0.45;
        }

        .blob-1 {
            width: 350px;
            height: 350px;
            background: var(--primary);
            top: -50px;
            right: -100px;
            animation: blobAnim 12s infinite alternate ease-in-out;
        }

        .blob-2 {
            width: 300px;
            height: 300px;
            background: #8b5cf6;
            bottom: -50px;
            left: -80px;
            animation: blobAnim 15s infinite alternate-reverse ease-in-out;
        }
        
        .blob-3 {
            width: 250px;
            height: 250px;
            background: var(--secondary);
            top: 40%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation: blobPulse 10s infinite alternate ease-in-out;
            opacity: 0.3;
        }

        /* Keyframes */
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
            100% { transform: translateY(0px); }
        }

        /* Continuous subtle 3D rotation based on mouse happens via JS, fallback float here */

        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        @keyframes blobAnim {
            0% { transform: translate(0, 0) scale(1) rotate(0deg); }
            100% { transform: translate(40px, -40px) scale(1.1) rotate(20deg); }
        }
        
        @keyframes blobPulse {
            0% { transform: translate(-50%, -50%) scale(0.8); }
            100% { transform: translate(-50%, -50%) scale(1.2); }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            h1 { font-size: 3.5rem; }
            .hero { gap: 2rem; }
        }

        @media (max-width: 968px) {
            .hero {
                flex-direction: column;
                text-align: center;
                padding-top: 3rem;
            }
            .hero-content {
                display: flex;
                flex-direction: column;
                align-items: center;
                max-width: 100%;
            }
            .hero-desc {
                margin-left: auto;
                margin-right: auto;
            }
            .cta-group {
                justify-content: center;
            }
            .nav-links {
                display: none;
            }
            .hero-visual {
                width: 100%;
                margin-top: 2rem;
            }
            .blob { opacity: 0.3; }
        }

        @media (max-width: 480px) {
            h1 { font-size: 2.75rem; }
            h2 { font-size: 1.35rem; }
            .hero-desc { font-size: 1rem; }
            .cta-group { flex-direction: column; width: 100%; }
            .btn { width: 100%; }
            .stats-grid { grid-template-columns: 1fr; gap: 1rem; }
            .glass-panel { padding: 1.5rem; }
            .nav-logo { height: 35px; }
            .hero-logo { height: 110px; }
        }
    </style>
</head>
<body>

    <!-- Background Layers -->
    <div class="page-bg"></div>
    <div class="bg-overlay"></div>

    <nav class="navbar">
        <div class="nav-brand">
            <img src="public/logo-nims.png" alt="NextCheck NIMS Logo" class="nav-logo">
        </div>
        <div class="nav-links">
            <a href="index.php">Overview</a>
            <a href="#features">Capabilities</a>
            <a href="#resources">IT Guidelines</a>
            <button class="btn-login-nav" onclick="window.location.href='login.php'">IT Staff Login</button>
        </div>
    </nav>

    <main class="hero">
        <div class="hero-content">
            <img src="public/unikl-official.png" alt="UniKL RCMP Logo" class="hero-logo">

            <div class="chip">
                <i class="ri-shield-keyhole-line"></i> IT Department Asset Management
            </div>
            
            <h1>RCMP NIMS</h1>
            <h2>NextCheck Inventory Management System</h2>
            
            <p class="hero-desc">
                An advanced, secure, and centralized asset tracking platform engineered specifically for the University of Kuala Lumpur Royal College of Medicine Perak (UniKL RCMP) IT Department. Gain absolute control over your technical infrastructure.
            </p>
            
            <div class="cta-group">
                <a href="login.php" class="btn btn-primary">
                    <i class="ri-login-circle-line" style="margin-right: 8px; font-size: 1.2rem;"></i> System Access
                </a>
                <a href="#about" class="btn btn-secondary">
                    <i class="ri-article-line" style="margin-right: 8px; font-size: 1.2rem;"></i> Documentation
                </a>
            </div>
        </div>

        <div class="hero-visual">
            <div class="blob blob-1"></div>
            <div class="blob blob-2"></div>
            <div class="blob blob-3"></div>
            
            <div class="glass-panel" id="tilt-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="ri-dashboard-3-line" style="color: var(--secondary);"></i> Infrastructure Overview
                    </div>
                    <div class="status-badge">
                        <span class="status-dot"></span> System Optimal
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon" style="color: #3b82f6; background: rgba(59, 130, 246, 0.1);">
                            <i class="ri-computer-line"></i>
                        </div>
                        <div class="stat-value" data-target="1542">0</div>
                        <div class="stat-label">Total Endpoints Tracking</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="color: #f59e0b; background: rgba(245, 158, 11, 0.1);">
                            <i class="ri-tools-fill"></i>
                        </div>
                        <div class="stat-value" data-target="28">0</div>
                        <div class="stat-label">Action Required</div>
                    </div>
                    
                    <div class="stat-card" style="grid-column: 1 / -1;">
                        <div style="display: flex; align-items: flex-start; justify-content: space-between;">
                            <div>
                                <div class="stat-label" style="text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Network Integrity Protocol</div>
                                <div style="font-size: 1.25rem; font-weight: 600; color: white;">NextCheck Scanning Engine</div>
                            </div>
                            <div style="color: #10b981; font-size: 2.5rem; line-height: 1;">
                                <i class="ri-shield-check-fill"></i>
                            </div>
                        </div>
                        <div class="health-bar-container">
                            <div class="health-bar" id="health-bar"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 0.8rem; color: var(--text-muted); font-family: 'Inter', sans-serif;">
                            <span>Scanning completed</span>
                            <span style="color: #10b981; font-weight: 600;">100% Secure</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Animate numeric counters gracefully
            const counters = document.querySelectorAll('.stat-value');
            counters.forEach(counter => {
                const target = +counter.getAttribute('data-target');
                let current = 0;
                
                // Duration of counting animation based on target size to make it feel organic
                const increment = Math.ceil(target / 45); 
                
                const updateCounter = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        counter.innerText = target.toLocaleString();
                        clearInterval(updateCounter);
                    } else {
                        counter.innerText = current.toLocaleString();
                    }
                }, 35);
            });
            
            // Animate health bar filling up
            setTimeout(() => {
                const healthBar = document.getElementById('health-bar');
                if(healthBar) {
                    healthBar.style.width = '100%';
                }
            }, 600);
            
            // Interactive 3D tilt effect on the glass panel
            const panel = document.getElementById('tilt-panel');
            const heroVisual = document.querySelector('.hero-visual');
            
            if (panel && heroVisual && window.matchMedia("(hover: hover) and (pointer: fine)").matches) {
                heroVisual.addEventListener('mousemove', (e) => {
                    const rect = panel.getBoundingClientRect();
                    const x = e.clientX - rect.left; // x position within the element
                    const y = e.clientY - rect.top;  // y position within the element
                    
                    const centerX = rect.width / 2;
                    const centerY = rect.height / 2;
                    
                    // Cap the rotation degrees for a subtle effect
                    const rotateX = ((y - centerY) / centerY) * -5;
                    const rotateY = ((x - centerX) / centerX) * 5;
                    
                    panel.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale3d(1.02, 1.02, 1.02)`;
                });
                
                heroVisual.addEventListener('mouseleave', () => {
                    // Reset styling, let CSS animation takeover again
                    panel.style.transform = `perspective(1000px) rotateX(0deg) rotateY(0deg) scale3d(1, 1, 1)`;
                    setTimeout(() => {
                        panel.style.transform = '';
                    }, 300);
                });
            }
        });
    </script>
</body>
</html>

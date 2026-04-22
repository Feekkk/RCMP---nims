<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

$manualDirFs = realpath(__DIR__ . '/../manuals');
$manualDirWeb = '../manuals';

$steps = [
    [
        'id' => 'login',
        'title' => 'Login',
        'subtitle' => 'Access the system using your credentials.',
        'image' => 'login.png',
    ],
    [
        'id' => 'add-assets',
        'title' => 'Add Assets',
        'subtitle' => 'Register a new asset and fill in the required fields.',
        'image' => 'add-assets.png',
    ],
    [
        'id' => 'handover',
        'title' => 'Handover',
        'subtitle' => 'Generate a handover form when issuing items.',
        'image' => 'handover.png',
    ],
];

$gallery = [];
if ($manualDirFs !== false && is_dir($manualDirFs)) {
    foreach ($steps as $s) {
        $img = (string)($s['image'] ?? '');
        $fs = $img !== '' ? $manualDirFs . DIRECTORY_SEPARATOR . $img : '';
        $exists = $fs !== '' && is_file($fs);
        $gallery[] = [
            'id' => (string)$s['id'],
            'title' => (string)$s['title'],
            'subtitle' => (string)$s['subtitle'],
            'image' => $img,
            'exists' => $exists,
            'web' => $img !== '' ? ($manualDirWeb . '/' . rawurlencode($img)) : '',
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Manual — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root{
            --primary:#2563eb;
            --secondary:#7c3aed;
            --bg:#f0f4ff;
            --card-bg:#ffffff;
            --text-main:#0f172a;
            --text-muted:#64748b;
            --border:#e2e8f0;
            --panel:#f8faff;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{
            font-family:'Inter',sans-serif;
            background:var(--bg);
            color:var(--text-main);
            display:flex;
            min-height:100vh;
            overflow-x:hidden;
        }
        .blob{position:fixed;border-radius:50%;filter:blur(80px);pointer-events:none;z-index:0}
        .blob-1{width:520px;height:520px;background:rgba(37,99,235,0.06);top:-110px;right:-120px}
        .blob-2{width:420px;height:420px;background:rgba(124,58,237,0.05);bottom:-90px;left:-90px}

        .main-content{
            margin-left:280px;
            flex:1;
            padding:2.5rem 3.5rem 5rem;
            max-width:calc(100vw - 280px);
            position:relative;
            z-index:1;
        }
        .page-header{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:1.25rem;
            margin-bottom:2rem;
            border-bottom:1px solid var(--border);
            padding-bottom:1.5rem;
        }
        .page-title h1{
            font-family:'Outfit',sans-serif;
            font-size:2rem;
            font-weight:800;
            display:flex;
            align-items:center;
            gap:0.75rem;
        }
        .page-title h1 i{color:var(--primary)}
        .page-title p{color:var(--text-muted);font-size:0.95rem;margin-top:0.25rem;max-width:46rem;line-height:1.45}

        .toolbar{
            display:flex;
            gap:0.75rem;
            align-items:center;
            flex-wrap:wrap;
        }
        .search{
            display:flex;
            align-items:center;
            gap:0.6rem;
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:14px;
            padding:0.65rem 0.9rem;
            min-width:280px;
        }
        .search i{color:var(--text-muted)}
        .search input{
            border:none;
            outline:none;
            background:transparent;
            font-size:0.92rem;
            width:100%;
            color:var(--text-main);
        }
        .pill{
            display:inline-flex;
            align-items:center;
            gap:0.5rem;
            padding:0.55rem 0.9rem;
            border-radius:999px;
            background:rgba(37,99,235,0.08);
            border:1px solid rgba(37,99,235,0.18);
            color:var(--primary);
            font-weight:700;
            font-size:0.85rem;
            user-select:none;
        }

        .layout{display:block}
        .card{
            background:var(--card-bg);
            border:1px solid var(--border);
            border-radius:20px;
            box-shadow:0 2px 12px rgba(15,23,42,0.06);
        }
        .card-pad{padding:1.5rem}
        .card-title{
            font-family:'Outfit',sans-serif;
            font-size:1.05rem;
            font-weight:800;
            display:flex;
            align-items:center;
            gap:0.6rem;
            margin-bottom:0.9rem;
        }
        .card-title i{color:var(--primary);font-size:1.25rem}

        .step{
            padding:1.65rem;
            display:grid;
            grid-template-columns:1fr;
            gap:1rem;
            margin-bottom:1.25rem;
        }
        .step-head{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:1rem;
            flex-wrap:wrap;
            cursor:pointer;
            user-select:none;
        }
        .step-body{display:none}
        .step.open .step-body{display:block}
        .step-head h2{
            font-family:'Outfit',sans-serif;
            font-size:1.35rem;
            font-weight:900;
            display:flex;
            align-items:center;
            gap:0.7rem;
        }
        .step-head h2 small{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width:34px;
            height:34px;
            border-radius:14px;
            background:rgba(124,58,237,0.10);
            border:1px solid rgba(124,58,237,0.18);
            color:var(--secondary);
            font-weight:900;
            font-size:0.9rem;
        }
        .step-head p{color:var(--text-muted);max-width:52rem;line-height:1.5}

        .shot{
            border-radius:18px;
            border:1px solid var(--border);
            overflow:hidden;
            background:linear-gradient(180deg, rgba(248,250,255,1), rgba(255,255,255,1));
        }
        .shot button{
            border:none;
            padding:0;
            width:100%;
            background:transparent;
            cursor:zoom-in;
            display:block;
        }
        .shot img{
            width:100%;
            height:auto;
            display:block;
        }
        .shot-cap{
            display:flex;
            justify-content:space-between;
            gap:1rem;
            padding:0.95rem 1.1rem;
            border-top:1px solid rgba(226,232,240,0.7);
            align-items:center;
            flex-wrap:wrap;
        }
        .shot-cap .left{min-width:0}
        .shot-cap .left strong{display:block;font-weight:900}
        .shot-cap .left span{display:block;color:var(--text-muted);font-size:0.86rem;margin-top:0.15rem}
        .shot-cap .right{
            display:inline-flex;
            align-items:center;
            gap:0.5rem;
            color:var(--primary);
            font-weight:800;
            font-size:0.88rem;
            user-select:none;
        }

        .missing{
            padding:1.1rem;
            background:rgba(245,158,11,0.08);
            border:1px solid rgba(245,158,11,0.20);
            border-radius:16px;
            color:#92400e;
            font-weight:700;
        }

        .modal{
            position:fixed;
            inset:0;
            background:rgba(2,6,23,0.64);
            display:none;
            align-items:center;
            justify-content:center;
            padding:1.25rem;
            z-index:999;
        }
        .modal.open{display:flex}
        .modal-card{
            width:min(1200px, 96vw);
            max-height:88vh;
            background:#0b1224;
            border:1px solid rgba(255,255,255,0.12);
            border-radius:18px;
            overflow:hidden;
            box-shadow:0 30px 120px rgba(0,0,0,0.55);
            display:flex;
            flex-direction:column;
        }
        .modal-bar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            padding:0.85rem 1rem;
            gap:1rem;
            background:rgba(255,255,255,0.06);
            border-bottom:1px solid rgba(255,255,255,0.12);
        }
        .modal-title{
            color:rgba(255,255,255,0.92);
            font-weight:900;
            font-size:0.95rem;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .modal-actions{
            display:flex;
            align-items:center;
            gap:0.5rem;
            flex:0 0 auto;
        }
        .icon-btn{
            width:38px;height:38px;
            border-radius:12px;
            border:1px solid rgba(255,255,255,0.16);
            background:rgba(255,255,255,0.06);
            color:rgba(255,255,255,0.92);
            display:inline-flex;
            align-items:center;
            justify-content:center;
            cursor:pointer;
        }
        .icon-btn:hover{background:rgba(255,255,255,0.10)}
        .modal-body{overflow:auto}
        .modal-body img{width:100%;height:auto;display:block}

        @media (max-width: 1100px){
            .main-content{padding:2.1rem 1.5rem 4rem}
        }
        @media (max-width: 900px){
            .main-content{margin-left:0;max-width:100vw}
        }
    </style>
</head>
<body>
<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">
    <header class="page-header">
        <div class="page-title">
            <h1><i class="ri-book-read-line"></i> User Manual</h1>
            <p>Step-by-step screenshots for the most common tasks. Click any image to view it larger.</p>
        </div>
        <div class="toolbar">
            <div class="pill"><i class="ri-image-2-line"></i> <?= (int)count(array_filter($gallery, static function ($x): bool { return (bool)($x['exists'] ?? false); })) ?> screenshots</div>
            <div class="search" title="Filter by title">
                <i class="ri-search-2-line"></i>
                <input id="filterInput" type="text" placeholder="Search manual…" autocomplete="off">
            </div>
        </div>
    </header>

    <div class="layout">
        <section>
            <?php $i = 0; foreach ($gallery as $g): $i++; ?>
                <article id="<?= htmlspecialchars($g['id']) ?>" class="card step" data-title="<?= htmlspecialchars(strtolower($g['title'])) ?>" data-step-id="<?= htmlspecialchars($g['id']) ?>">
                    <div class="step-head" role="button" tabindex="0" aria-expanded="false">
                        <div>
                            <h2><small><?= $i ?></small> <?= htmlspecialchars($g['title']) ?></h2>
                            <p><?= htmlspecialchars($g['subtitle']) ?></p>
                        </div>
                    </div>

                    <div class="step-body">
                        <?php if (!$g['exists']): ?>
                            <div class="missing">
                                Screenshot not found: <code><?= htmlspecialchars($g['image']) ?></code> in <code>/manuals</code>.
                            </div>
                        <?php else: ?>
                            <div class="shot">
                                <button type="button"
                                        class="openShot"
                                        data-src="<?= htmlspecialchars($g['web']) ?>"
                                        data-title="<?= htmlspecialchars($g['title']) ?>">
                                    <img data-src="<?= htmlspecialchars($g['web']) ?>" alt="<?= htmlspecialchars($g['title']) ?>" loading="lazy">
                                </button>
                                <div class="shot-cap">
                                    <div class="left">
                                        <strong><?= htmlspecialchars($g['title']) ?></strong>
                                        <span><?= htmlspecialchars($g['image']) ?></span>
                                    </div>
                                    <div class="right"><i class="ri-zoom-in-line"></i> Click to zoom</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </div>
</main>

<div class="modal" id="imgModal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-label="Screenshot preview">
        <div class="modal-bar">
            <div class="modal-title" id="modalTitle">Screenshot</div>
            <div class="modal-actions">
                <a class="icon-btn" id="modalOpenNew" href="#" target="_blank" rel="noopener" title="Open in new tab">
                    <i class="ri-external-link-line"></i>
                </a>
                <button class="icon-btn" id="modalClose" type="button" title="Close (Esc)">
                    <i class="ri-close-line"></i>
                </button>
            </div>
        </div>
        <div class="modal-body">
            <img id="modalImg" src="" alt="">
        </div>
    </div>
</div>

<script>
    function toggleDropdown(el, e) {
        e.preventDefault();
        const group = el.closest('.nav-group');
        const dropdown = group && group.querySelector('.nav-dropdown');
        if (!dropdown) return;
        el.classList.toggle('open');
        dropdown.classList.toggle('show');
    }

    const modal = document.getElementById('imgModal');
    const modalImg = document.getElementById('modalImg');
    const modalTitle = document.getElementById('modalTitle');
    const modalClose = document.getElementById('modalClose');
    const modalOpenNew = document.getElementById('modalOpenNew');
    let lastFocus = null;

    function openModal(src, title) {
        if (!src) return;
        lastFocus = document.activeElement;
        modalImg.src = src;
        modalImg.alt = title || 'Screenshot';
        modalTitle.textContent = title || 'Screenshot';
        modalOpenNew.href = src;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        modalClose.focus();
    }

    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        modalImg.src = '';
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
    }

    function ensureImageLoaded(article) {
        if (!article) return;
        const img = article.querySelector('.openShot img[data-src]');
        if (!img) return;
        if (img.getAttribute('src')) return;
        const src = img.getAttribute('data-src');
        if (src) img.setAttribute('src', src);
    }

    function collapseAll(exceptId) {
        document.querySelectorAll('article.step').forEach(a => {
            if (exceptId && a.id === exceptId) return;
            a.classList.remove('open');
            const head = a.querySelector('.step-head');
            if (head) head.setAttribute('aria-expanded', 'false');
        });
    }

    function toggleStep(article, forceOpen) {
        if (!article) return;
        const wantOpen = forceOpen === true ? true : !article.classList.contains('open');
        if (wantOpen) {
            collapseAll(article.id);
            article.classList.add('open');
            ensureImageLoaded(article);
        } else {
            article.classList.remove('open');
        }
        const head = article.querySelector('.step-head');
        if (head) head.setAttribute('aria-expanded', wantOpen ? 'true' : 'false');
    }

    function getArticleFromHash(hash) {
        const id = (hash || '').replace('#', '');
        if (!id) return null;
        return document.getElementById(id);
    }

    document.querySelectorAll('.openShot').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn.dataset.src, btn.dataset.title));
    });

    document.querySelectorAll('article.step .step-head').forEach(head => {
        head.addEventListener('click', () => toggleStep(head.closest('article.step')));
        head.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleStep(head.closest('article.step'));
            }
        });
    });

    const initial = getArticleFromHash(window.location.hash);
    if (initial) {
        toggleStep(initial, true);
        setTimeout(() => initial.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
    }

    modalClose.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });

    const filterInput = document.getElementById('filterInput');
    const cards = Array.from(document.querySelectorAll('article.step'));

    function applyFilter(v) {
        const q = (v || '').trim().toLowerCase();
        cards.forEach(c => {
            const t = c.getAttribute('data-title') || '';
            c.style.display = (q === '' || t.includes(q)) ? '' : 'none';
        });
    }

    filterInput.addEventListener('input', () => applyFilter(filterInput.value));
</script>
</body>
</html>

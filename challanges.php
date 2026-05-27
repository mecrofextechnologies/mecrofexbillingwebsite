<?php
// challenges.php (or index.php?page=challenges)
// Public page showing all active challenges from creators

include 'msanii/includes/connect.php';
// session_start(); // optional on public page - comment out if not needed

// Pagination settings
$perPage = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Count total active challenges
$countStmt = $pdo->query("
    SELECT COUNT(*)
    FROM challenges
    WHERE status = 'active'
");
$totalChallenges = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalChallenges / $perPage));

// Fetch active challenges with creator info and safe days_completed
$stmt = $pdo->prepare("
    SELECT 
        c.id, c.title, c.description, c.duration, c.start_date, c.end_date, c.created_at,
        cr.full_name, cr.username,
        'default.png' AS profile_pic,
        COALESCE(
            (SELECT COUNT(*) 
             FROM challenge_progress cp 
             WHERE cp.challenge_id = c.id 
               AND cp.completed = 1),
            0
        ) AS days_completed
    FROM challenges c
    JOIN creators cr ON c.creator_id = cr.id
    WHERE c.status = 'active'
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$challenges = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Discover active challenges created by talented people on Keychele">
    <meta name="author" content="Keychele">
    <title>All Active Challenges | Keychele</title>

    <!-- CSS FILES -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-icons.css" rel="stylesheet">
    <link href="css/magnific-popup.css" rel="stylesheet">
    <link href="css/tooplate-waso-strategy.css" rel="stylesheet">

    <style>
        :root {
            --primary: #b81236;
            --dark: #032d5d;
            --success: #10b981;
        }
        .hero.section-hero {
            background: linear-gradient(rgba(3,45,93,0.85), rgba(3,45,93,0.85)), url('images/ui/challenges-hero.jpg') center/cover no-repeat;
        }
        .events-title {
            font-size: 2.8rem;
            color: var(--dark);
            font-weight: 700;
        }
        .search-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            width: 100%;
            max-width: 400px;
        }
        .search-wrap input[type="search"] {
            width: 100%;
            padding: 14px 50px 14px 20px;
            border-radius: 50px;
            border: 1px solid rgba(3,45,93,0.1);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            background: #fff;
            font-size: 1rem;
        }
        .search-wrap .search-btn {
            position: absolute;
            right: 10px;
            background: var(--primary);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .challenge-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .challenge-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        .challenge-img {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
        .challenge-body {
            padding: 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        .challenge-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 10px;
            line-height: 1.3;
        }
        .creator-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .creator-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #eee;
        }
        .creator-info small {
            color: #555;
            font-size: 0.95rem;
        }
        .progress-container {
            margin: 12px 0 16px;
        }
        .progress {
            height: 10px;
            background: #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #34d399);
            transition: width 0.6s ease;
        }
        .view-btn {
            margin-top: auto;
            display: block;
            text-align: center;
            padding: 12px;
            background: var(--primary);
            color: white;
            font-weight: 600;
            border-radius: 50px;
            text-decoration: none;
            transition: all 0.3s;
        }
        .view-btn:hover {
            background: #97112f;
            color: white;
        }
        .no-challenges {
            text-align: center;
            padding: 100px 20px;
            color: #777;
        }
        .no-challenges i {
            font-size: 5rem;
            color: #eee;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .search-wrap { max-width: 100%; }
        }
    </style>
</head>

<body class="projects-detail-page" id="section_1">

    <?php include_once('includes/header.php'); ?>
    <?php include_once('includes/nav.php'); ?>

    <main>
        <!-- Hero Section -->
        <section class="hero section-hero section-padding">
            <div class="container">
                <div class="row">
                    <div class="col-lg-8 col-12 text-center mx-auto">
                        <div class="section-hero-text">
                            <small class="small-title text-warning">Inspiration & Accountability</small>
                            <h1 class="text-white">Active Challenges</h1>
                            <p class="text-white mt-3 opacity-90">
                                Discover real people pursuing real goals. See their progress and get motivated!
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Challenges Listing Section -->
        <section class="projects section-padding pb-5">
            <div class="container">
                <div class="row align-items-center mb-5">
                    <div class="col-lg-8 col-12">
                        <h2 class="events-title">All Active Challenges <span class="text-muted fs-5">(<?= $totalChallenges ?> total)</span></h2>
                    </div>
                    <div class="col-lg-4 col-12 text-lg-end">
                        <div class="search-wrap">
                            <input type="search" id="challengeSearch" placeholder="Search by title or creator...">
                            <button class="search-btn"><i class="bi bi-search"></i></button>
                        </div>
                    </div>
                </div>

                <div class="row g-4" id="challengeGrid">
                    <?php if (!empty($challenges)): ?>
                        <?php foreach ($challenges as $ch): 
                            // Safe handling to prevent undefined key warnings
                            $days_completed = $ch['days_completed'] ?? 0;
                            $percentage = $ch['duration'] > 0 ? round(($days_completed / $ch['duration']) * 100, 1) : 0;
                        ?>
                            <div class="col-lg-3 col-md-6 col-12 challenge-card-item"
                                 data-title="<?= strtolower(htmlspecialchars($ch['title'])) ?>"
                                 data-creator="<?= strtolower(htmlspecialchars($ch['full_name'] . ' ' . $ch['username'])) ?>">
                                <div class="challenge-card">
                                    <img src="msanii/uploads/<?= htmlspecialchars($ch['profile_pic']) ?>"
                                         class="challenge-img" alt="<?= htmlspecialchars($ch['full_name']) ?>">
                                    <div class="challenge-body">
                                        <h5 class="challenge-title"><?= htmlspecialchars($ch['title']) ?></h5>
                                        
                                        <div class="creator-info">
                                            <img src="msanii/uploads/<?= htmlspecialchars($ch['profile_pic']) ?>" alt="">
                                            <small>by <?= htmlspecialchars($ch['full_name']) ?> (@<?= htmlspecialchars($ch['username']) ?>)</small>
                                        </div>

                                        <div class="progress-container">
                                            <div class="d-flex justify-content-between mb-1 small">
                                                <span><?= $days_completed ?> / <?= htmlspecialchars($ch['duration']) ?> days</span>
                                                <strong><?= $percentage ?>%</strong>
                                            </div>
                                            <div class="progress">
                                                <div class="progress-bar" role="progressbar"
                                                     style="width: <?= $percentage ?>%;"
                                                     aria-valuenow="<?= $percentage ?>"
                                                     aria-valuemin="0"
                                                     aria-valuemax="100"></div>
                                            </div>
                                        </div>

                                        <a href="index.php?page=challenge_progress&id=<?= $ch['id'] ?>"
                                           class="view-btn">
                                            View Progress →
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="no-challenges">
                                <i class="bi bi-trophy"></i>
                                <h3>No Active Challenges Yet</h3>
                                <p>Be the first to start one — or check back soon!</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <nav aria-label="Challenges pagination" class="mt-5">
                    <ul class="pagination">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        if ($start > 1): ?>
                            <li class="page-item"><a class="page-link" href="?page=1">1</a></li>
                            <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $start; $i <= $end; $i++): ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($end < $totalPages): ?>
                            <?php if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                            <li class="page-item"><a class="page-link" href="?page=<?= $totalPages ?>"><?= $totalPages ?></a></li>
                        <?php endif; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <?php include_once('includes/footer.php'); ?>

    <!-- JAVASCRIPT FILES -->
    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/jquery.sticky.js"></script>
    <script src="js/jquery.magnific-popup.min.js"></script>
    <script src="js/magnific-popup-options.js"></script>
    <script src="js/click-scroll.js"></script>
    <script src="js/custom.js"></script>

    <!-- Live Search Script -->
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const input = document.getElementById("challengeSearch");
            const cards = document.querySelectorAll(".challenge-card-item");

            input.addEventListener("input", () => {
                const q = input.value.toLowerCase().trim();
                cards.forEach(card => {
                    const title = card.dataset.title;
                    const creator = card.dataset.creator;
                    const show = title.includes(q) || creator.includes(q);
                    card.style.display = show ? "" : "none";
                });
            });
        });
    </script>
</body>
</html>
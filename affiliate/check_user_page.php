<?php
// =============================================
// check_user_page.php - FULL PAGE BLOCK VERSION
// Blocks access to the entire dashboard until page is created
// =============================================

if (empty($_SESSION['creator_id'])) {
    return; // shouldn't happen, but safety first
}

$creator_id = $_SESSION['creator_id'];

// Check if creator already has a page
$has_page = false;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM my_page WHERE creator_id = ?");
    $stmt->execute([$creator_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $has_page = ($row['cnt'] > 0);
} catch (Exception $e) {
    $has_page = false; // silent fail → treat as no page
}

// If they already have a page → show nothing and exit
if ($has_page) {
    return;
}

// If we reach here → user has NO page → show full-screen blocker
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Required - Keychele</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #b81236;
            --accent: #fdbd10;
            --light: #ffffff;
            --dark: #222222;
        }
        body, html {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        .blocker {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
        }
        .blocker-content {
            background: white;
            border-radius: 16px;
            max-width: 580px;
            width: 100%;
            padding: 3rem 2.5rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .blocker-icon {
            font-size: 4.5rem;
            color: var(--primary);
            margin-bottom: 1.5rem;
        }
        .blocker-title {
            color: var(--primary);
            font-weight: 700;
            font-size: 2.1rem;
            margin-bottom: 1rem;
        }
        .blocker-text {
            color: #444;
            font-size: 1.1rem;
            margin-bottom: 2.5rem;
            line-height: 1.6;
        }
        .btn-create {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 0.9rem 2.8rem;
            font-size: 1.2rem;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 6px 20px rgba(184,18,54,0.3);
        }
        .btn-create:hover {
            background: #9e0f2e;
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(184,18,54,0.4);
        }
        .accent-line {
            height: 6px;
            background: linear-gradient(to right, var(--primary), var(--accent));
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
        }
    </style>
</head>
<body>

<div class="blocker">
    <div class="blocker-content">
        <div class="accent-line"></div>
        
        <i class="fas fa-store-slash blocker-icon"></i>
        
        <h2 class="blocker-title">Your Public Page is Required</h2>
        
        <p class="blocker-text">
            To start using all Keychele features — receiving support, selling tickets, 
            building your community and more — you need to create your public page first.<br><br>
            <strong>This is a one-time setup.</strong> It only takes a few minutes!
        </p>

        <a href="https://keychele.co.ke/msanii/index.php?page=create_page" 
           class="btn btn-create btn-lg">
            <i class="fas fa-plus-circle me-2"></i> Create My Page Now
        </a>

        <p class="mt-4 mb-0 small text-muted">
            You will be able to use the full dashboard after completing this step.
        </p>
    </div>
</div>

</body>
</html>

<?php
// Important: stop all further page rendering
exit;
?>
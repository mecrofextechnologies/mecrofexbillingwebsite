<?php
// survey_preview.php - Survey Responses & Export (used via index.php?page=survey_preview)

ob_start(); // Start output buffering early

// Uncomment only during debugging
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);


include 'includes/connect.php';

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header("Location: login.php");
    exit();
}

$survey_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($survey_id <= 0) {
    header("Location: index.php?page=my_surveys");
    exit();
}

// ── Fetch survey details ───────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT id, title, description, is_active, created_at
    FROM surveys
    WHERE id = ? AND creator_id = ?
");
$stmt->execute([$survey_id, $creator_id]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    header("Location: index.php?page=my_surveys");
    exit();
}

// ── Fetch questions ────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT id, question_text, position
    FROM survey_questions
    WHERE survey_id = ?
    ORDER BY position ASC
");
$stmt->execute([$survey_id]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch responses ────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT id, answers, submitted_at
    FROM survey_responses
    WHERE survey_id = ?
    ORDER BY submitted_at DESC
");
$stmt->execute([$survey_id]);
$responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$response_count = count($responses);

// ─────────────────────────────────────────────────────────────
//    EXPORT HANDLING — MUST BE BEFORE ANY HTML OUTPUT
// ─────────────────────────────────────────────────────────────
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'pdf'])) {

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // ── CSV Export ────────────────────────────────────────────────
    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="survey-' . $survey_id . '-responses-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        $header = ['Submitted At'];
        foreach ($questions as $q) {
            $header[] = $q['question_text'];
        }
        fputcsv($output, $header);

        foreach ($responses as $resp) {
            $answers = json_decode($resp['answers'], true) ?: [];
            $row = [date('Y-m-d H:i:s', strtotime($resp['submitted_at']))];

            foreach ($questions as $q) {
                $value = $answers[$q['id']] ?? '';
                $row[] = is_array($value) ? implode(', ', $value) : $value;
            }
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    // ── PDF Export ────────────────────────────────────────────────
    if ($_GET['export'] === 'pdf') {
        // Correct path: one level up from msanii folder
        $fpdf_path = dirname(__DIR__) . '/vendor/fpdf.php';

        if (!file_exists($fpdf_path)) {
            header('Content-Type: text/plain; charset=utf-8');
            die("FPDF library not found.\nChecked path: " . htmlspecialchars($fpdf_path) . "\nPlease verify the file exists in the vendor folder.");
        }

        require $fpdf_path;

        class PDF extends FPDF {
            function Header() {
                $this->SetFont('Arial', 'B', 18);
                $this->Cell(0, 12, 'Keychele', 0, 1, 'C');
                $this->SetFont('Arial', 'I', 11);
                $this->Cell(0, 8, 'Survey Responses', 0, 1, 'C');
                $this->Ln(8);
            }

            function Footer() {
                $this->SetY(-15);
                $this->SetFont('Arial', 'I', 8);
                $this->Cell(0, 10, 'Generated on ' . date('d M Y H:i'), 0, 0, 'C');
                $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
            }
        }

        $pdf = new PDF('L', 'mm', 'A4');
        $pdf->AliasNbPages();
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, htmlspecialchars($survey['title']), 0, 1, 'C');
        $pdf->Ln(5);

        // Table header
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(235, 235, 235);

        $submitted_w = 38;
        $available_w = 277 - $submitted_w;
        $q_width = count($questions) > 0 ? $available_w / count($questions) : 50;

        $pdf->Cell($submitted_w, 10, 'Submitted', 1, 0, 'C', true);
        foreach ($questions as $q) {
            $pdf->Cell($q_width, 10, substr($q['question_text'], 0, 40) . (strlen($q['question_text']) > 40 ? '...' : ''), 1, 0, 'C', true);
        }
        $pdf->Ln();

        // Rows
        $pdf->SetFont('Arial', '', 9);
        foreach ($responses as $resp) {
            $answers = json_decode($resp['answers'], true) ?: [];

            $pdf->Cell($submitted_w, 8, date('d M Y H:i', strtotime($resp['submitted_at'])), 1, 0);

            foreach ($questions as $q) {
                $value = $answers[$q['id']] ?? '—';
                if (is_array($value)) $value = implode(', ', $value);
                $pdf->Cell($q_width, 8, substr($value, 0, 65) . (strlen($value) > 65 ? '...' : ''), 1, 0);
            }
            $pdf->Ln();
        }

        $pdf->Output('D', 'survey-' . $survey_id . '-responses-' . date('Y-m-d') . '.pdf');
        exit;
    }
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($survey['title']) ?> - Responses</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet"/>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">

<div id="preloader" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm transition-opacity duration-300">
    <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
        <div class="h-5 w-5 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin"></div>
        <span class="text-gray-700 font-medium">Loading...</span>
    </div>
</div>

<div class="max-w-[96%] mx-auto px-4 py-8 md:py-12">

    <!-- Header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-10">
        <div>
            <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">
                <?= htmlspecialchars($survey['title']) ?>
            </h1>
            <p class="text-gray-600 text-lg">
                <?= $response_count ?> response<?= $response_count !== 1 ? 's' : '' ?> received
            </p>
        </div>

        <div class="flex flex-wrap gap-4">
            <a href="index.php?page=my_surveys"
               class="px-6 py-3 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-xl font-medium transition flex items-center gap-2">
                <i class="fa-solid fa-arrow-left"></i> Back to My Surveys
            </a>

            <?php if ($response_count > 0): ?>
                <a href="?page=survey_preview&id=<?= $survey_id ?>&export=csv"
                   class="px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-xl font-medium transition flex items-center gap-2 shadow-sm">
                    <i class="fa-solid fa-file-csv"></i> Export CSV / Excel
                </a>

                <a href="?page=survey_preview&id=<?= $survey_id ?>&export=pdf"
                   class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl font-medium transition flex items-center gap-2 shadow-sm">
                    <i class="fa-solid fa-file-pdf"></i> Export PDF
                </a>
            <?php endif; ?>

            <a href="survey_edit.php?id=<?= $survey_id ?>"
               class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-medium transition flex items-center gap-2 shadow-sm">
                <i class="fa-solid fa-pen-to-square"></i> Edit Survey
            </a>
        </div>
    </div>

    <!-- Responses Table / Empty State -->
    <?php if ($response_count === 0): ?>
        <div class="bg-white rounded-2xl shadow-xl p-12 text-center text-gray-500">
            <i class="fa-solid fa-inbox text-7xl mb-6 block text-gray-300"></i>
            <h3 class="text-2xl font-semibold mb-3">No responses yet</h3>
            <p class="mb-6">Share your survey link to start collecting answers.</p>
            <a href="survey_edit.php?id=<?= $survey_id ?>"
               class="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-medium transition">
                <i class="fa-solid fa-pen-to-square"></i> Edit & Share Survey
            </a>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700 uppercase tracking-wider whitespace-nowrap">
                                Submitted
                            </th>
                            <?php foreach ($questions as $question): ?>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700 uppercase tracking-wider">
                                    <?= htmlspecialchars($question['question_text']) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <?php foreach ($responses as $resp): ?>
                            <?php $answers = json_decode($resp['answers'], true) ?: []; ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 text-sm text-gray-600 whitespace-nowrap">
                                    <?= date('d M Y • H:i', strtotime($resp['submitted_at'])) ?>
                                </td>
                                <?php foreach ($questions as $q): ?>
                                    <?php
                                    $value = $answers[$q['id']] ?? '—';
                                    if (is_array($value)) $value = implode(', ', $value);
                                    ?>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <?= htmlspecialchars($value) ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
window.addEventListener("load", () => {
    const loader = document.getElementById("preloader");
    loader.style.opacity = "0";
    setTimeout(() => loader.style.display = "none", 400);
});
</script>
</body>
</html>
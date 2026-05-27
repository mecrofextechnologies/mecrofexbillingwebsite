<?php
/**
 * survey_delete.php
 * Permanently deletes a survey, its questions, and its responses.
 * Called via: survey_delete.php?id=123
 */

// ── Start session safely (works whether connect.php starts it or not) ─────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Load DB connection ────────────────────────────────────────────────────────
include __DIR__ . '/includes/connect.php';

// ── Auth check ────────────────────────────────────────────────────────────────
if (empty($_SESSION['creator_id']) || !is_numeric($_SESSION['creator_id'])) {
    header("Location: index.php?page=surveys&error=session");
    exit;
}

$creator_id = (int) $_SESSION['creator_id'];
$survey_id  = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;

if ($survey_id <= 0) {
    header("Location: index.php?page=surveys&error=invalid");
    exit;
}

// ── Delete ────────────────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    // Confirm ownership before touching anything
    $own = $pdo->prepare("SELECT id FROM surveys WHERE id = ? AND creator_id = ?");
    $own->execute([$survey_id, $creator_id]);
    if (!$own->fetch()) {
        throw new Exception("Survey not found or access denied.");
    }

    // Delete child rows first (in case no ON DELETE CASCADE)
    $pdo->prepare("DELETE FROM survey_responses WHERE survey_id = ?")
        ->execute([$survey_id]);

    $pdo->prepare("DELETE FROM survey_questions WHERE survey_id = ?")
        ->execute([$survey_id]);

    // Delete the survey itself
    $pdo->prepare("DELETE FROM surveys WHERE id = ? AND creator_id = ?")
        ->execute([$survey_id, $creator_id]);

    $pdo->commit();

    header("Location: index.php?page=my_surveys&deleted=1");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("[survey_delete] FAILED — survey_id={$survey_id}, creator_id={$creator_id}, err=" . $e->getMessage());
    header("Location: index.php?page=my_surveys&error=delete_failed");
    exit;
}

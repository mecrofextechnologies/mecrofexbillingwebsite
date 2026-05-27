<?php
/**
 * header.php - FIXED VERSION
 * 
 * - Starts with <?php (no whitespace before)
 * - Handles all dynamic <head> content via PHP
 * - No direct HTML output before possible header() calls
 * - ob_start() only if really needed (usually not here)
 */

// Optional: Start output buffering ONLY if this file might be included 
// before any header-modifying code (but in your case, better to fix callers)
ob_start();  // ← safe here, but often not needed in header.php

$title = isset($_GET['page']) 
    ? ucwords(str_replace("_", ' ', $_GET['page'])) 
    : "Home";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin <?php echo htmlspecialchars($title); ?></title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intro.js/minified/introjs.min.css">
  <script src="https://cdn.jsdelivr.net/npm/intro.js/minified/intro.min.js"></script>

  <!-- Google Font: Source Sans Pro -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">

  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="assets/plugins/fontawesome-free/css/all.min.css">

  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="assets/plugins/overlayScrollbars/css/OverlayScrollbars.min.css">

  <!-- DataTables -->
  <link rel="stylesheet" href="assets/plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" href="assets/plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" href="assets/plugins/datatables-buttons/css/buttons.bootstrap4.min.css">

  <!-- Select2 -->
  <link rel="stylesheet" href="assets/plugins/select2/css/select2.min.css">
  <link rel="stylesheet" href="assets/plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">

  <!-- SweetAlert2 -->
  <link rel="stylesheet" href="assets/plugins/sweetalert2-theme-bootstrap-4/bootstrap-4.min.css">

  <!-- Toastr -->
  <link rel="stylesheet" href="assets/plugins/toastr/toastr.min.css">

  <!-- Theme style -->
  <link rel="stylesheet" href="assets/dist/css/adminlte.min.css">
  <link rel="stylesheet" href="assets/dist/css/styles.css">

  <!-- Summernote -->
  <link rel="stylesheet" href="assets/plugins/summernote/summernote-bs4.min.css">

  <!-- jQuery (moved up – should be before most plugins that depend on it) -->
  <script src="assets/plugins/jquery/jquery.min.js"></script>

  <?php
  // If you really need output buffering control here, flush only what you want
  // But in most cases → remove ob_start() and ob_end_flush() from this file completely
  // ob_end_flush();
  ?>
</head>
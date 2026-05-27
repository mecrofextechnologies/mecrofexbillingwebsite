<?php
// Database connection
include '../includes/connect.php';

// Function to insert or update statistics
function manageStatistics($pdo, $courses, $learners, $organizations, $venues, $sectors, $consultancy) {
    try {
        $sql = "SELECT COUNT(*) FROM statistics";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $exists = $stmt->fetchColumn();

        if ($exists > 0) {
            $sql = "UPDATE statistics 
                    SET courses = :courses, learners = :learners, organizations = :organizations, 
                        venues = :venues, sectors = :sectors, consultancy = :consultancy,
                        updated_at = NOW()
                    WHERE id = 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'courses' => $courses,
                'learners' => $learners,
                'organizations' => $organizations,
                'venues' => $venues,
                'sectors' => $sectors,
                'consultancy' => $consultancy
            ]);
            $message = "Statistics updated successfully!";
        } else {
            $sql = "INSERT INTO statistics (courses, learners, organizations, venues, sectors, consultancy, updated_at) 
                    VALUES (:courses, :learners, :organizations, :venues, :sectors, :consultancy, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'courses' => $courses,
                'learners' => $learners,
                'organizations' => $organizations,
                'venues' => $venues,
                'sectors' => $sectors,
                'consultancy' => $consultancy
            ]);
            $message = "Statistics inserted successfully!";
        }

        return $message;

    } catch (PDOException $e) {
        return "Error: " . $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courses = (int) $_POST['courses'];
    $learners = (int) $_POST['learners'];
    $organizations = (int) $_POST['organizations'];
    $venues = (int) $_POST['venues'];
    $sectors = (int) $_POST['sectors'];
    $consultancy = (int) $_POST['consultancy'];

    $statMessage = manageStatistics($pdo, $courses, $learners, $organizations, $venues, $sectors, $consultancy);
}

// Fetch statistics
try {
    $sql = "SELECT * FROM statistics WHERE id = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $statistics = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Failed to fetch statistics: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Statistics Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body {
        background: #f9f9f9;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    .card {
        border-radius: 15px;
        box-shadow: 0px 4px 15px rgba(0,0,0,0.1);
    }
    .stat-box {
        background: white;
        border-radius: 12px;
        padding: 25px;
        text-align: center;
        box-shadow: 0px 3px 10px rgba(0,0,0,0.08);
        transition: transform 0.2s ease-in-out;
    }
    .stat-box:hover {
        transform: translateY(-5px);
    }
    .stat-number {
        font-size: 2rem;
        font-weight: bold;
        color: #007bff;
    }
    .stat-label {
        font-size: 1rem;
        color: #555;
        margin-top: 8px;
    }
  </style>
</head>
<body>
<div class="container mt-5">
  <div class="col-lg-12">
    <div class="card p-4">
      <h3 class="mb-4">📊 Statistics Dashboard</h3>

      <!-- Form -->
      <form action="" method="POST" class="row g-3">
        <div class="col-md-6">
            <label for="courses" class="form-label">Courses</label>
            <input type="number" class="form-control" id="courses" name="courses" value="<?php echo htmlspecialchars($statistics['courses'] ?? 0); ?>" required>
        </div>
        <div class="col-md-6">
            <label for="learners" class="form-label">Learners</label>
            <input type="number" class="form-control" id="learners" name="learners" value="<?php echo htmlspecialchars($statistics['learners'] ?? 0); ?>" required>
        </div>
        <div class="col-md-6">
            <label for="organizations" class="form-label">Organizations</label>
            <input type="number" class="form-control" id="organizations" name="organizations" value="<?php echo htmlspecialchars($statistics['organizations'] ?? 0); ?>" required>
        </div>
        <div class="col-md-6">
            <label for="venues" class="form-label">Training Venues</label>
            <input type="number" class="form-control" id="venues" name="venues" value="<?php echo htmlspecialchars($statistics['venues'] ?? 0); ?>" required>
        </div>
        <div class="col-md-6">
            <label for="sectors" class="form-label">Sectors Served</label>
            <input type="number" class="form-control" id="sectors" name="sectors" value="<?php echo htmlspecialchars($statistics['sectors'] ?? 0); ?>" required>
        </div>
        <div class="col-md-6">
            <label for="consultancy" class="form-label">Consultancy Areas</label>
            <input type="number" class="form-control" id="consultancy" name="consultancy" value="<?php echo htmlspecialchars($statistics['consultancy'] ?? 0); ?>" required>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-primary w-100">💾 Save Statistics</button>
        </div>
      </form>

      <!-- Display Stats -->
      <div class="mt-5">
        <h4 class="mb-3">📈 Current Statistics</h4>
        <?php if ($statistics): ?>
          <div class="row g-4">
            <div class="col-md-4"><div class="stat-box"><div class="stat-number"><?php echo number_format($statistics['courses']); ?>+</div><div class="stat-label">Courses</div></div></div>
            <div class="col-md-4"><div class="stat-box"><div class="stat-number"><?php echo number_format($statistics['learners']); ?>+</div><div class="stat-label">Learners</div></div></div>
            <div class="col-md-4"><div class="stat-box"><div class="stat-number"><?php echo number_format($statistics['organizations']); ?>+</div><div class="stat-label">Organizations</div></div></div>
            <div class="col-md-4"><div class="stat-box"><div class="stat-number"><?php echo number_format($statistics['venues']); ?>+</div><div class="stat-label">Training Venues</div></div></div>
            <div class="col-md-4"><div class="stat-box"><div class="stat-number"><?php echo number_format($statistics['sectors']); ?>+</div><div class="stat-label">Sectors Served</div></div></div>
            <div class="col-md-4"><div class="stat-box"><div class="stat-number"><?php echo number_format($statistics['consultancy']); ?>+</div><div class="stat-label">Consultancy Areas</div></div></div>
          </div>
          <p class="text-muted mt-3">⏱ Last updated: <?php echo htmlspecialchars($statistics['updated_at']); ?></p>
        <?php else: ?>
          <p>No statistics available yet.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (isset($statMessage)): ?>
<script>
Swal.fire({
  icon: 'success',
  title: 'Done!',
  text: '<?php echo $statMessage; ?>',
  confirmButtonColor: '#3085d6'
});
</script>
<?php endif; ?>

<?php if (isset($error_message)): ?>
<script>
Swal.fire({
  icon: 'error',
  title: 'Oops...',
  text: '<?php echo $error_message; ?>',
  confirmButtonColor: '#d33'
});
</script>
<?php endif; ?>

</body>
</html>

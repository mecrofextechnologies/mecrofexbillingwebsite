
<?php
include 'includes/connect.php';

$data = null;

if (isset($_SESSION['employee_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
    $stmt->execute([$_SESSION['employee_id']]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Profile</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <style>
    body {
      background: linear-gradient(135deg, #6f2dbd, #275D7A);
      min-height: 100vh;
      font-family: 'Inter', sans-serif;
      margin: 0;
      padding: 0;
      display: flex;
      justify-content: center;
      align-items: flex-start;
    }
    .container {
      margin-top: 40px;
      max-width: 900px;
      width: 95%;
    }
    .card {
      border-radius: 15px;
      overflow: hidden;
      box-shadow: 0 8px 20px rgba(0,0,0,0.2);
    }
    .profile-username {
      font-weight: bold;
      font-size: 1.8rem;
      color: #333;
    }
    .list-group-item {
      font-size: 1rem;
    }
    .list-group-item b {
      color: #275D7A;
    }
    .btn-primary {
      background: #6f2dbd;
      border: none;
    }
    .btn-primary:hover {
      background: #56209b;
    }
    @media (max-width: 768px) {
      .profile-username {
        font-size: 1.4rem;
      }
      .list-group-item {
        font-size: 0.9rem;
      }
    }
    @media (max-width: 480px) {
      .profile-username {
        font-size: 1.2rem;
      }
      .list-group-item {
        font-size: 0.85rem;
      }
    }
  </style>
</head>
<body>
<div class="container">

  <?php if ($data): ?>
  <!-- Profile Card -->
  <div class="card card-outline">
    <div class="card-body box-profile">
      <div class="text-center">
        <img class="profile-user-img img-fluid img-circle"
             src="https://via.placeholder.com/150"
             alt="Profile picture">
      </div>

      <h3 class="profile-username text-center">
        <?= htmlspecialchars($data['first_name'] . ' ' . $data['middle_name'] . ' ' . $data['last_name']); ?>
      </h3>
      <p class="text-muted text-center">Employee</p>

      <!-- Profile Info List -->
      <ul class="list-group list-group-unbordered mb-3">
        <li class="list-group-item">
          <b><i class="fas fa-envelope"></i> Email</b> 
          <span class="float-right"><?= htmlspecialchars($data['email'] ?? 'N/A'); ?></span>
        </li>
        <li class="list-group-item">
          <b><i class="fas fa-building"></i> Department</b> 
          <span class="float-right"><?= htmlspecialchars($data['department'] ?? 'N/A'); ?></span>
        </li>
        <li class="list-group-item">
          <b><i class="fas fa-city"></i> Company</b> 
          <span class="float-right"><?= htmlspecialchars($data['company'] ?? 'N/A'); ?></span>
        </li>
        <li class="list-group-item">
          <b><i class="fas fa-map-marker-alt"></i> Location</b> 
          <span class="float-right"><?= htmlspecialchars($data['location'] ?? 'N/A'); ?></span>
        </li>
        <li class="list-group-item">
          <b><i class="fas fa-id-card"></i> Employee ID</b> 
          <span class="float-right"><?= htmlspecialchars($data['employee_id']); ?></span>
        </li>
        <li class="list-group-item">
          <b><i class="fas fa-credit-card"></i> Account Number</b> 
          <span class="float-right"><?= htmlspecialchars($data['account_number']); ?></span>
        </li>
      </ul>

      <a href="edit_profile.php" class="btn btn-primary btn-block">
        <i class="fas fa-edit"></i> Edit Profile
      </a>
    </div>
  </div>
  <?php else: ?>
    <div class="alert alert-warning mt-4">No profile data found. Please log in.</div>
  <?php endif; ?>

</div>
</body>
</html>

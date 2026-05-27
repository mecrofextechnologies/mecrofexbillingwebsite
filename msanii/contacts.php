<?php
include '../includes/connect.php';

$success_message = '';
$error_message = '';

// Fetch existing record (only 1 expected)
$stmt = $pdo->query("SELECT * FROM contact_info LIMIT 1");
$contact = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle update or insert
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone   = trim($_POST['phone']);
    $email   = trim($_POST['email']);
    $address = trim($_POST['address']);
    $map     = trim($_POST['map_embed']);

    if ($contact) {
        // Update
        $stmt = $pdo->prepare("UPDATE contact_info 
                               SET phone=:phone, email=:email, address=:address, map_embed=:map, updated_at=NOW() 
                               WHERE id=:id");
        $stmt->execute([
            'phone'   => $phone,
            'email'   => $email,
            'address' => $address,
            'map'     => $map,
            'id'      => $contact['id']
        ]);
        $success_message = "Contact information updated successfully!";
    } else {
        // Insert first record
        $stmt = $pdo->prepare("INSERT INTO contact_info (phone, email, address, map_embed) 
                               VALUES (:phone, :email, :address, :map)");
        $stmt->execute([
            'phone'   => $phone,
            'email'   => $email,
            'address' => $address,
            'map'     => $map
        ]);
        $success_message = "Contact information saved successfully!";
    }

    // Reload contact
    $stmt = $pdo->query("SELECT * FROM contact_info LIMIT 1");
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Contact Info</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card p-4 shadow">
        <h3 class="mb-3">Manage Contact Information</h3>
        <form method="post">
            <div class="mb-3">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($contact['phone'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($contact['email'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($contact['address'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Google Map Embed Code</label>
                <textarea name="map_embed" class="form-control" rows="3" placeholder="Paste Google Maps embed iframe here"><?= htmlspecialchars($contact['map_embed'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-success">Save Changes</button>
        </form>
    </div>
</div>

<?php if($success_message): ?>
<script>Swal.fire("Success","<?= htmlspecialchars($success_message); ?>","success");</script>
<?php endif; ?>
<?php if($error_message): ?>
<script>Swal.fire("Error","<?= htmlspecialchars($error_message); ?>","error");</script>
<?php endif; ?>
</body>
</html>

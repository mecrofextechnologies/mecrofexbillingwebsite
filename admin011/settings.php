<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Handle saving/updating the department details
    $id = isset($_POST['department_id']) ? $_POST['department_id'] : '';
    $institution_name = $_POST['institution_name'];
    $description = $_POST['description'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $email = $_POST['email'];

    if (!empty($id)) {
        // Update existing department
        $stmt = $pdo->prepare("UPDATE settings SET institution_name = ?, description = ?, phone = ?, address = ?, email = ? WHERE id = ?");
        $result = $stmt->execute([$institution_name, $description, $phone, $address, $email, $id]);
        if ($result) {
            echo 1;
        } else {
            echo 0;
        }
    } else {
        // Insert new department (this block can be removed if you only want to allow updates)
        $stmt = $pdo->prepare("INSERT INTO settings (institution_name, description, phone, address, email) VALUES (?, ?, ?, ?, ?)");
        $result = $stmt->execute([$institution_name, $description, $phone, $address, $email]);
        if ($result) {
            echo 1;
        } else {
            echo 0;
        }
    }
    exit;
}

if (isset($_GET['delete_id'])) {
    // Handle deleting the department
    $id = $_GET['delete_id'];
    $stmt = $pdo->prepare("DELETE FROM settings WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: settings.php");
    exit;
}
?>

<div class="col-lg-12">
    <div class="card card-outline card-primary">
        <div class="card-header">
            <div class="card-tools">
            </div>
        </div>
        <div class="card-body">
            <table class="table table-hover table-bordered" id="list">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Institution Name</th>
                        <th>Description</th>
                        <th>Phone</th>
                        <th>Address</th>
                        <th>Email</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    $qry = $pdo->query("SELECT * FROM settings ORDER BY date DESC LIMIT 1");
                    while ($row = $qry->fetch(PDO::FETCH_ASSOC)):
                    ?>
                    <tr>
                        <th class="text-center"><?php echo $i++ ?></th>
                        <td><b><?php echo ucwords($row['institution_name']) ?></b></td>
                        <td><b><?php echo $row['description'] ?></b></td>
                        <td><b><?php echo $row['phone'] ?></b></td>
                        <td><b><?php echo $row['address'] ?></b></td>
                        <td><b><?php echo $row['email'] ?></b></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-expanded="true">
                                Action
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item edit_settings" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>" data-name="<?php echo $row['institution_name'] ?>" data-description="<?php echo $row['description'] ?>" data-phone="<?php echo $row['phone'] ?>" data-address="<?php echo $row['address'] ?>" data-email="<?php echo $row['email'] ?>">Edit</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item delete_department" href="javascript:void(0)" onclick="return confirm('This settings cannot be deleted,edit and update instead')">Delete</a>
                            </div>
                        </td>
                    </tr>    
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="department_modal" tabindex="-1" role="dialog" aria-labelledby="department_modal_label" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="department_form">
                <input type="hidden" id="department_id" name="department_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="department_modal_label">Edit Settings</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="institution_name">Institution Name</label>
                        <input type="text" class="form-control" id="institution_name" name="institution_name" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="text" class="form-control" id="phone" name="phone" required>
                    </div>
                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" class="form-control" id="address" name="address" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#new_department').click(function() {
        $('#department_form')[0].reset();
        $('#department_modal_label').text('New Department');
        $('#department_id').val('');
        $('#department_modal').modal('show');
    });

    $('#department_form').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: 'settings.php',
            method: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (response == 1) {
                    toastr.success('Settings were updated successfully');
                    setTimeout(function(){
                        location.reload();
                    }, 1500);
                } else {
                    toastr.error('An error occurred while updating the settings,please try again,if problem persists,cantact support');
                }
            }
        });
    });

    $('.edit_settings').click(function() {
        $('#department_modal_label').text('Edit Settings');
        $('#department_id').val($(this).data('id'));
        $('#institution_name').val($(this).data('name'));
        $('#description').val($(this).data('description'));
        $('#phone').val($(this).data('phone'));
        $('#address').val($(this).data('address'));
        $('#email').val($(this).data('email'));
        $('#department_modal').modal('show');
    });
});
</script>

<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Handle saving/updating the department details
    $id = isset($_POST['department_id']) ? $_POST['department_id'] : '';
    $description = $_POST['description'];

    if (!empty($id)) {
        // Update existing department
        $stmt = $pdo->prepare("UPDATE iuk_history SET  description = ? WHERE id = ?");
        $result = $stmt->execute([$description,  $id]);
        if ($result) {
            echo 1;
        } else {
            echo 0;
        }
    } else {
        // Insert new department (this block can be removed if you only want to allow updates)
        $stmt = $pdo->prepare("INSERT INTO iuk_history ( description) VALUES (?)");
        $result = $stmt->execute([$description]);
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
    $stmt = $pdo->prepare("DELETE FROM iuk_history WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: iuk-history.php");
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
                        <th>IUK History</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    $qry = $pdo->query("SELECT * FROM iuk_history ORDER BY date DESC LIMIT 1");
                    while ($row = $qry->fetch(PDO::FETCH_ASSOC)):
                    ?>
                    <tr>
                        <th class="text-center"><?php echo $i++ ?></th>
                        <td><b><?php echo $row['description'] ?></b></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-expanded="true">
                                Action
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item edit_iukhistory" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>" data-id="<?php echo $row['description'] ?>">Edit</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item delete_department" href="javascript:void(0)" onclick="return confirm('This iuk history cannot be deleted,edit and update instead')">Delete</a>
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
                    <h5 class="modal-title" id="department_modal_label">Edit IUK History</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="description">Islamic University of Kenya History</label>
                        <textarea class="form-control" id="description" name="description" placeholder="Edit or input iuk history here" required></textarea>
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
            url: 'iuk-history.php',
            method: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (response == 1) {
                    toastr.success('Iuk History were updated successfully');
                    setTimeout(function(){
                        location.reload();
                    }, 1500);
                } else {
                    toastr.error('An error occurred while updating the Iuk History,please try again,if problem persists,cantact support');
                }
            }
        });
    });

    $('.edit_iukhistory').click(function() {
        $('#department_modal_label').text('Edit iukhistory');
        $('#department_id').val($(this).data('id'))
        $('#description').val($(this).data('description'));
        $('#department_modal').modal('show');
    });
});
</script>

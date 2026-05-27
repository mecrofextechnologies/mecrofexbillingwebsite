<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Handle saving the notice board details
    $id = isset($_POST['notice_id']) ? $_POST['notice_id'] : '';
    $title = $_POST['title'];
    $description = $_POST['description'];

    if (!empty($id)) {
        // Update existing notice
        $stmt = $pdo->prepare("UPDATE notice_board SET title = ?, description = ? WHERE id = ?");
        $result = $stmt->execute([$title, $description, $id]);
        if ($result) {
            echo 1;
        } else {
            echo 0;
        }
    } else {
        // Insert new notice
        $stmt = $pdo->prepare("INSERT INTO notice_board (title, description) VALUES (?, ?)");
        $result = $stmt->execute([$title, $description]);
        if ($result) {
            echo 1;
        } else {
            echo 0;
        }
    }
    exit;
}

if (isset($_GET['delete_id'])) {
    // Handle deleting the notice
    $id = $_GET['delete_id'];
    $stmt = $pdo->prepare("DELETE FROM notice_board WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: notice_board.php");
    exit;
}
?>

<div class="col-lg-12">
    <div class="card card-outline card-primary">
        <div class="card-header">
            <div class="card-tools">
                <button id="new_notic" class="btn btn-primary">Add New Notice</button>
            </div>
        </div>
        <div class="card-body">
            <table class="table table-hover table-bordered" id="list">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    $qry = $pdo->query("SELECT * FROM notice_board ORDER BY id DESC");
                    while ($row = $qry->fetch(PDO::FETCH_ASSOC)):
                    ?>
                    <tr>
                        <th class="text-center"><?php echo $i++ ?></th>
                        <td><b><?php echo ucwords($row['title']) ?></b></td>
                        <td><b><?php echo $row['description'] ?></b></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-expanded="true">
                                Action
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item edit_notice" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>" data-title="<?php echo $row['title'] ?>" data-description="<?php echo $row['description'] ?>">Edit</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item delete_notic" href="javascript:void(0)" onclick="return confirm('This notice cannot be deleted,edit and update instead')">Delete</a>
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
<div class="modal fade" id="notice_modal" tabindex="-1" role="dialog" aria-labelledby="notice_modal_label" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="notice_form">
                <input type="hidden" id="notice_id" name="notice_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="notice_modal_label">Add/Edit Notice</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="title">Title</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#new_notice').click(function() {
        $('#notice_form')[0].reset();
        $('#notice_modal_label').text('Add New Notice');
        $('#notice_id').val('');
        $('#notice_modal').modal('show');
    });

    $('#notice_form').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: 'notice_board.php',
            method: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (response == 1) {
                    toastr.success('Notice saved successfully');
                    setTimeout(function(){
                        location.reload();
                    }, 1500);
                } else {
                    toastr.error('An error occurred while saving the notice, please try again.');
                }
            }
        });
    });

    $('.edit_notice').click(function() {
        $('#notice_modal_label').text('Edit Notice');
        $('#notice_id').val($(this).data('id'));
        $('#title').val($(this).data('title'));
        $('#description').val($(this).data('description'));
        $('#notice_modal').modal('show');
    });
});
</script>

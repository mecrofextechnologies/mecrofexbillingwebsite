<?php
include '../includes/connect.php';

// Fetch events from the database
$stmt = $pdo->query("SELECT * FROM events ORDER BY event_date_created DESC");
$events = $stmt->fetchAll();

// Handle event deletion
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    // Prepare DELETE statement to remove the event
    $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
    $stmt->execute([$delete_id]);

    echo "<script>
        $(document).ready(function() {
            toastr.success('Event successfully deleted.', '', {timeOut: 2000, closeButton: true, progressBar: true, positionClass: 'toast-top-right', showEasing: 'swing', hideEasing: 'linear', showMethod: 'fadeIn', hideMethod: 'fadeOut', closeHtml: '<button><i class=\"icon-off\"></i></button>'});
            toastr.options.backgroundcolor = '#FFFFFF';
            setTimeout(function(){
                location.reload(); // Reload the page after deletion
            }, 750);
        });
    </script>";
}

// Handle new event creation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = bin2hex(random_bytes(7)); // Generate a random 15-character alphanumeric ID
    $event_title = strip_tags($_POST['title']);
    $event_description = strip_tags($_POST['description']);
    $event_thumbnail = '';

    // Handle file upload for event thumbnail
    if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] == UPLOAD_ERR_OK) {
        $uploads_dir = '../uploads_folder/';
        if (!is_dir($uploads_dir)) {
            mkdir($uploads_dir, 0777, true);
        }
        $event_thumbnail = $uploads_dir . basename($_FILES['thumbnail']['name']);
        move_uploaded_file($_FILES['thumbnail']['tmp_name'], $event_thumbnail);
    }

    // Insert the new event into the database
    $stmt = $pdo->prepare("INSERT INTO events (id, event_title, event_description, event_thumbnail, event_date_created) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$id, $event_title, $event_description, $event_thumbnail]);

    echo "<script>
        $(document).ready(function() {
            toastr.success('Event successfully created.', '', {timeOut: 2000, closeButton: true, progressBar: true, positionClass: 'toast-top-right', showEasing: 'swing', hideEasing: 'linear', showMethod: 'fadeIn', hideMethod: 'fadeOut', closeHtml: '<button><i class=\"icon-off\"></i></button>'});
            toastr.options.backgroundcolor = '#FFFFFF';
            setTimeout(function(){
                location.replace('index.php?page=new_event'); // Redirect after successful creation
            }, 750);
        });
    </script>";
}
?>

<div class="col-lg-12">
    <div class="card">
        <div class="card-body">
            <h3>Events List</h3>
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Thumbnail</th>
                        <th>Date Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($event['event_title']); ?></td>
                            <td><?php echo htmlspecialchars($event['event_description']); ?></td>
                            <td>
                                <?php if ($event['event_thumbnail']): ?>
                                    <img src="<?php echo $event['event_thumbnail']; ?>" alt="Thumbnail" width="100">
                                <?php else: ?>
                                    No Thumbnail
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($event['event_date_created']); ?></td>
                            <td>
                                <a href="edit_event.php?id=<?php echo $event['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                                <a href="?delete_id=<?php echo $event['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this event?')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create a new event form -->
<div class="col-lg-12">
    <div class="card">
        <div class="card-body">
            <h3>Create New Event</h3>
            <form action="" id="manage_event" method="POST" enctype="multipart/form-data">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="title" class="control-label">Event Title</label>
                        <input type="text" name="title" id="title" class="form-control form-control-sm" maxlength="100" required value="">
                        <small id="titleHelp" class="form-text text-muted">100 characters max. <span id="charCount" style="color: green;">100</span> remaining.</small>
                    </div>
                    <div class="form-group">
                        <label for="thumbnail" class="control-label">Event Thumbnail</label>
                        <input type="file" name="thumbnail" class="form-control form-control-sm" required>
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="form-group">
                        <label class="control-label">Event Description</label>
                        <textarea name="description" cols="30" rows="10" class="form-control summernote"></textarea>
                    </div>
                </div>
                <hr>
                <div class="col-lg-12 text-right justify-content-center d-flex">
                    <button class="btn btn-primary mr-2" type="submit">Save</button>
                    <button class="btn btn-secondary" type="reset">Clear</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(document).ready(function(){
        // Character limit and count display for event title
        var maxLength = 100;
        $('#title').on('input', function() {
            var length = $(this).val().length;
            var remaining = maxLength - length;
            $('#charCount').text(remaining);
            $('#charCount').css('color', remaining >= 0 ? 'green' : 'red');
            if (remaining < 0) {
                $(this).addClass('border-danger');
            } else {
                $(this).removeClass('border-danger');
            }
        });

        $('#manage_event').submit(function(e){
            e.preventDefault();
            start_load();
            $.ajax({
                url: '',
                data: new FormData($(this)[0]),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                success:function(resp){
                    $('body').append(resp);
                }
            });
        });
    });
</script>

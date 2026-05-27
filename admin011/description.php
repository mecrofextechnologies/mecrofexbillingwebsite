<?php 
include '../includes/connect.php';

try {
    // Fetch all data from the blogs table without pagination
    $stmt = $pdo->prepare("SELECT * FROM blogs");
    $stmt->execute();
    $blogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

<div class="col-lg-12">
    <div class="card card-outline card-info">
        <div class="card-body">
            <table class="table table-hover table-bordered" id="list">
                <colgroup>
                    <col width="5%">
                    <col width="15%">
                    <col width="20%">
                    <col width="15%">
                    <col width="25%">
                    <col width="10%">
                    <col width="10%">
                </colgroup>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Thumbnail</th>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Date Created</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($blogs): ?>
                        <?php foreach ($blogs as $index => $blog): ?>
                            <tr>
                                <th class="text-center"><?php echo $index + 1; ?></th>
                                <td><img src="../uploads_folder/<?php echo htmlspecialchars($blog['thumbnail']); ?>" alt="Thumbnail" style="width: 100px; height: 70px;"></td>
                                <td><b><?php echo htmlspecialchars($blog['title']); ?></b></td>
                                <td><b>
                                    <?php 
                                        $description = htmlspecialchars($blog['description']);
                                        echo strlen($description) > 30 ? substr($description, 0, 30) . '...' : $description;
                                    ?>
                                </b></td>
                                <td><b><?php echo htmlspecialchars($blog['date_created']); ?></b></td>
                                <td><b>Published</b></td>
                                <td class="text-center">
                                    <!-- Edit Button (Link to edit page) -->
                                    <a href="edit_blog.php?id=<?php echo htmlspecialchars($blog['id']); ?>" class="btn btn-warning btn-sm">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    
                                    <!-- Delete Button (Trigger Modal) -->
                                    <button type="button" class="btn btn-danger btn-sm" data-toggle="modal" data-target="#deleteBlogModal" data-id="<?php echo htmlspecialchars($blog['id']); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">No blogs found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    $(document).ready(function(){
        $('#list').dataTable();
        $('.delete_ticket').click(function(){
            _conf("Are you sure to delete this item?","delete_item",[$(this).attr('data-i')])
        });
    });
    function delete_item($id){
        start_load();
        $.ajax({
            url:'ajax.php?action=delete_item',
            method:'POST',
            data:{id:$id},
            success:function(resp){
                if(resp==1){
                    alert_toast("Data successfully deleted",'success');
                    setTimeout(function(){
                        location.reload();
                    },1500);
                }
            }
        });
    }
</script>

<!-- Delete Blog Modal -->
<div class="modal fade" id="deleteBlogModal" tabindex="-1" role="dialog" aria-labelledby="deleteBlogModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form method="post" action="delete_blog.php">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteBlogModalLabel">Delete Blog</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this blog?</p>
                    <input type="hidden" name="delete_id" id="deleteId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    // JavaScript to set the blog ID in the modal
    $('#deleteBlogModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget); // Button that triggered the modal
        var blogId = button.data('id'); // Extract info from data-* attributes
        var modal = $(this);
        modal.find('#deleteId').val(blogId);
    });
</script>
